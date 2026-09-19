<?php

namespace App\Services\WhatsApp;

use App\Models\WaBotSetting;
use App\Models\WaConversation;
use App\Models\WaMessage;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * بوّابة الإرسال الوحيدة إلى ميتا — كل صادر (بوت/موظف/نظام) يمرّ من هنا.
 *
 * ثلاثة أقفال بالترتيب:
 *   1) القناة مهيّأة (رمز + معرّف رقم)
 *   2) قفل الإرسال الطارئ (wa_suspended)
 *   3) نافذة 24 ساعة من آخر رسالة واردة — لا استثناء لأحد
 */
class WhatsAppChannel
{
    public const ERR_NOT_CONFIGURED = 'CHANNEL_NOT_CONFIGURED';
    public const ERR_SUSPENDED = 'SENDING_SUSPENDED';
    public const ERR_WINDOW = 'WINDOW_EXPIRED';

    private static function graphUrl(string $phoneNumberId): string
    {
        $v = env('WA_GRAPH_VERSION', 'v21.0');
        return "https://graph.facebook.com/{$v}/{$phoneNumberId}/messages";
    }

    /** تحقّق توقيع ميتا على الجسم الخام — إلزامي في الإنتاج */
    public static function verifySignature(string $rawBody, ?string $header, ?string $appSecret): bool
    {
        if (empty($appSecret) || empty($header)) {
            return false;
        }
        $expected = 'sha256=' . hash_hmac('sha256', $rawBody, $appSecret);
        return hash_equals($expected, trim($header));
    }

    /**
     * إرسال رسالة نصية.
     * @return array{ok:bool, error:?string, code:?string, message_id:?string}
     */
    public static function sendText(WaConversation $conv, string $text, string $source = 'bot', ?int $staffId = null): array
    {
        return self::dispatch($conv, [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $conv->wa_phone,
            'type' => 'text',
            'text' => ['preview_url' => false, 'body' => mb_substr($text, 0, 4096)],
        ], $text, 'text', $source, $staffId);
    }

    /**
     * إرسال أزرار (حتى 3) — معرّف كل زر يحمل الحالة مثل offer:12
     * @param array<array{id:string,title:string}> $buttons
     */
    public static function sendButtons(WaConversation $conv, string $body, array $buttons, string $source = 'bot'): array
    {
        $rows = [];
        foreach (array_slice($buttons, 0, 3) as $b) {
            $rows[] = ['type' => 'reply', 'reply' => [
                'id' => mb_substr($b['id'], 0, 256),
                'title' => mb_substr($b['title'], 0, 20),
            ]];
        }

        return self::dispatch($conv, [
            'messaging_product' => 'whatsapp',
            'to' => $conv->wa_phone,
            'type' => 'interactive',
            'interactive' => [
                'type' => 'button',
                'body' => ['text' => mb_substr($body, 0, 1024)],
                'action' => ['buttons' => $rows],
            ],
        ], $body, 'interactive', $source);
    }

    /**
     * إرسال قائمة (حتى 10 صفوف)
     * @param array<array{id:string,title:string,description?:string}> $items
     */
    public static function sendList(WaConversation $conv, string $body, string $buttonLabel, array $items, string $source = 'bot'): array
    {
        $rows = [];
        foreach (array_slice($items, 0, 10) as $it) {
            $row = [
                'id' => mb_substr($it['id'], 0, 200),
                'title' => mb_substr($it['title'], 0, 24),
            ];
            if (!empty($it['description'])) {
                $row['description'] = mb_substr($it['description'], 0, 72);
            }
            $rows[] = $row;
        }

        return self::dispatch($conv, [
            'messaging_product' => 'whatsapp',
            'to' => $conv->wa_phone,
            'type' => 'interactive',
            'interactive' => [
                'type' => 'list',
                'body' => ['text' => mb_substr($body, 0, 1024)],
                'action' => [
                    'button' => mb_substr($buttonLabel, 0, 20),
                    'sections' => [['title' => 'الخيارات', 'rows' => $rows]],
                ],
            ],
        ], $body, 'interactive', $source);
    }

    // ==================== المحرّك ====================

    private static function dispatch(
        WaConversation $conv,
        array $payload,
        string $displayBody,
        string $msgType,
        string $source,
        ?int $staffId = null
    ): array {
        $s = WaBotSetting::current();

        // ── القفل 1: القناة مهيّأة؟
        if (!$s->channelReady()) {
            return self::fail(self::ERR_NOT_CONFIGURED, 'قناة الواتساب غير مهيّأة (رمز الوصول أو معرّف الرقم ناقص).');
        }

        // ── القفل 2: إيقاف إرسال طارئ
        if ($s->wa_suspended || env('WA_SUSPENDED')) {
            return self::fail(self::ERR_SUSPENDED, 'الإرسال موقوف حالياً (قفل طارئ).');
        }

        // ── القفل 3: نافذة 24 ساعة
        if (!$conv->isWindowOpen()) {
            return self::fail(self::ERR_WINDOW, 'انتهت نافذة الـ24 ساعة — لا يمكن الإرسال حتى يراسلك العميل من جديد.');
        }

        try {
            $res = Http::withToken($s->token())
                ->timeout(25)
                ->acceptJson()
                ->post(self::graphUrl($s->phoneNumberId()), $payload);
        } catch (\Throwable $e) {
            Log::error('WA send exception: ' . $e->getMessage());
            return self::fail('HTTP_ERROR', 'تعذّر الاتصال بميتا: ' . $e->getMessage());
        }

        $json = $res->json() ?? [];

        if (!$res->successful()) {
            $err = data_get($json, 'error.message', 'HTTP ' . $res->status());
            self::store($conv, null, $displayBody, $msgType, $source, 'failed', $err, $staffId, $payload);
            return self::fail('META_ERROR', $err);
        }

        $wamid = data_get($json, 'messages.0.id');
        self::store($conv, $wamid, $displayBody, $msgType, $source, 'sent', null, $staffId, $payload);

        // ردّ الموظف يُوقف البوت مؤقتاً ويصفّر المؤشرات
        $update = [
            'last_outbound_at' => now(),
            'last_message_at' => now(),
            'last_message_preview' => mb_substr(trim($displayBody), 0, 160),
        ];
        if ($source === 'staff') {
            $update['bot_paused_until'] = now()->addMinutes(max(1, (int) $s->pause_minutes));
            $update['unread_count'] = 0;
            $update['needs_attention'] = false;
        }
        $conv->update($update);

        return ['ok' => true, 'error' => null, 'code' => null, 'message_id' => $wamid];
    }

    private static function store(
        WaConversation $conv, ?string $wamid, string $body, string $msgType,
        string $source, string $status, ?string $error, ?int $staffId, array $payload
    ): void {
        WaMessage::create([
            'conversation_id' => $conv->id,
            'wamid' => $wamid,
            'direction' => 'out',
            'source' => $source,
            'msg_type' => $msgType,
            'body' => $body,
            'payload' => $payload,
            'status' => $status,
            'error_message' => $error,
            'staff_id' => $staffId,
        ]);
    }

    private static function fail(string $code, string $msg): array
    {
        return ['ok' => false, 'error' => $msg, 'code' => $code, 'message_id' => null];
    }
}
