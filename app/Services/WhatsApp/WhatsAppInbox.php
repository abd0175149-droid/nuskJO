<?php

namespace App\Services\WhatsApp;

use App\Models\WaBotSetting;
use App\Models\WaConversation;
use App\Models\WaMessage;
use App\Models\WaOptout;
use Illuminate\Support\Facades\Log;

/**
 * طبقة الإنبوكس: خزّن أولاً ثمّ قرّر من يردّ.
 * البوت مستهلك لهذه الطبقة لا مالكها — إطفاؤه لا يوقف مركز المحادثات.
 */
class WhatsAppInbox
{
    /**
     * معالجة حمولة الويبهوك كاملة.
     * @return array<int> معرّفات المحادثات التي وصلتها رسالة عميل جديدة (للتمرير للبوت)
     */
    public static function handleWebhook(array $payload): array
    {
        $touched = [];
        // للتطبيق أكثر من رقم، والويبهوك واحد للتطبيق كلّه — نعالج رقمنا وحده
        $ownId = (string) WaBotSetting::current()->phoneNumberId();

        foreach (data_get($payload, 'entry', []) as $entry) {
            foreach (data_get($entry, 'changes', []) as $change) {
                $field = data_get($change, 'field');
                $value = data_get($change, 'value', []);

                $to = (string) data_get($value, 'metadata.phone_number_id', '');
                if ($field === 'messages' && $ownId !== '' && $to !== '' && $to !== $ownId) {
                    continue;
                }

                if ($field !== 'messages') {
                    // صحّة الحساب / حالة القوالب → قفل الإرسال احتياطاً
                    self::handleAccountEvent($field, $value);
                    continue;
                }

                $contacts = collect(data_get($value, 'contacts', []))->keyBy('wa_id');

                // حالات التسليم
                foreach (data_get($value, 'statuses', []) as $st) {
                    self::handleStatus($st);
                }

                // الرسائل الواردة
                foreach (data_get($value, 'messages', []) as $msg) {
                    $convId = self::storeInbound($msg, $contacts);
                    if ($convId) {
                        $touched[] = $convId;
                    }
                }
            }
        }

        return array_values(array_unique($touched));
    }

    /** @return int|null معرّف المحادثة إن كانت الرسالة تستحق ردّ البوت */
    private static function storeInbound(array $msg, $contacts): ?int
    {
        $wamid = data_get($msg, 'id');
        $from = data_get($msg, 'from');
        $type = data_get($msg, 'type', 'text');

        if (!$wamid || !$from) {
            return null;
        }

        // منع التكرار — ميتا تعيد الإرسال عند عدم الردّ بـ200
        if (WaMessage::where('wamid', $wamid)->exists()) {
            return null;
        }

        $conv = self::conversationFor($from, data_get($contacts->get($from), 'profile.name'));

        // التفاعلات (reaction) مسار منفصل: تُخزَّن ولا تفتح نافذة ولا تُمرَّر للبوت
        if ($type === 'reaction') {
            WaMessage::create([
                'conversation_id' => $conv->id, 'wamid' => $wamid, 'direction' => 'in',
                'source' => 'customer', 'msg_type' => 'reaction',
                'body' => data_get($msg, 'reaction.emoji', '👍'), 'payload' => $msg,
            ]);
            return null;
        }

        [$body, $buttonId] = self::extract($msg, $type);

        WaMessage::create([
            'conversation_id' => $conv->id,
            'wamid' => $wamid,
            'direction' => 'in',
            'source' => 'customer',
            'msg_type' => $type,
            'body' => $body,
            'payload' => $msg + ($buttonId ? ['_button_id' => $buttonId] : []),
        ]);

        // فتح نافذة 24 ساعة + تحديث المؤشرات
        $conv->update([
            'last_inbound_at' => now(),
            'last_message_at' => now(),
            'last_message_preview' => mb_substr(trim((string) $body), 0, 160),
            'unread_count' => $conv->unread_count + 1,
            'status' => 'open',
        ]);

        // إيقاف التسويق بنصّ مطابق
        // رقم الإدارة لا يُسجَّل رافضاً للتسويق: «إيقاف» منه تعني غالباً إيقاف البوت
        if (self::isOptOut($body, $buttonId) && !\App\Models\WaAdminNumber::forPhone($conv->phone)) {
            WaOptout::updateOrCreate(
                ['phone' => $conv->phone],
                ['reason' => 'طلب العميل', 'created_at' => now()]
            );
            WhatsAppChannel::sendText($conv, 'تم إيقاف الرسائل التسويقية. يمكنك مراسلتنا في أي وقت لأي استفسار.', 'system');
            return null;
        }

        return $conv->id;
    }

    /** إيجاد/إنشاء محادثة وربطها بالعميل أو الموظف */
    public static function conversationFor(string $waPhone, ?string $profileName = null): WaConversation
    {
        $canonical = PhoneNormalizer::canonical($waPhone);

        $conv = WaConversation::firstOrCreate(
            ['phone' => $canonical],
            [
                'wa_phone' => PhoneNormalizer::digits($waPhone),
                'display_name' => $profileName,
                'bot_enabled' => true,
                'status' => 'open',
            ]
        );

        $update = [];
        if ($profileName && $conv->display_name !== $profileName) {
            $update['display_name'] = $profileName;
        }
        // يُعاد الفحص مع كل رسالة حتى يُربط
        if (!$conv->client_id) {
            if ($client = PhoneNormalizer::findClient($canonical)) {
                $update['client_id'] = $client->id;
                $update['display_name'] = $update['display_name'] ?? $client->name;
            }
        }
        if (!$conv->user_id) {
            if ($user = PhoneNormalizer::findUser($canonical)) {
                $update['user_id'] = $user->id;
            }
        }
        if ($update) {
            $conv->update($update);
            $conv->refresh();
        }

        return $conv;
    }

    /** استخراج النص ومعرّف الزر إن وجد */
    private static function extract(array $msg, string $type): array
    {
        return match ($type) {
            'text' => [data_get($msg, 'text.body', ''), null],
            'interactive' => [
                data_get($msg, 'interactive.button_reply.title')
                    ?? data_get($msg, 'interactive.list_reply.title', ''),
                // التقاط الاثنين — القوائم ليست أزراراً
                data_get($msg, 'interactive.button_reply.id')
                    ?? data_get($msg, 'interactive.list_reply.id'),
            ],
            'button' => [data_get($msg, 'button.text', ''), data_get($msg, 'button.payload')],
            'image' => ['📷 صورة' . (data_get($msg, 'image.caption') ? ': ' . data_get($msg, 'image.caption') : ''), null],
            'audio' => ['🎤 رسالة صوتية', null],
            'video' => ['🎬 مقطع فيديو', null],
            'document' => ['📄 ملف: ' . data_get($msg, 'document.filename', ''), null],
            'location' => ['📍 موقع', null],
            'sticker' => ['🏷️ ملصق', null],
            default => ['[' . $type . ']', null],
        };
    }

    private static function isOptOut(?string $body, ?string $buttonId): bool
    {
        if ($buttonId === 'optout') return true;
        $t = trim(mb_strtolower((string) $body));
        // «إلغاء» وحدها ليست هنا: من يكتبها يقصد غالباً إلغاء حجز، فتصل للبوت
        return in_array($t, ['stop', 'ايقاف', 'إيقاف', 'الغاء الاشتراك', 'إلغاء الاشتراك', 'unsubscribe'], true);
    }

    /** حالات التسليم: sent / delivered / read / failed */
    private static function handleStatus(array $st): void
    {
        $wamid = data_get($st, 'id');
        $status = data_get($st, 'status');
        if (!$wamid || !$status) return;

        $m = WaMessage::where('wamid', $wamid)->first();
        if (!$m) return;

        $payload = $m->payload ?? [];
        $payload['statusHistory'][] = ['status' => $status, 'at' => now()->toIso8601String()];

        // الرمز مع العنوان، مثل «[131047] Re-engagement message»: ميتا توصي بالبناء على code لا على title
        $code = data_get($st, 'errors.0.code');
        $error = trim(($code ? "[{$code}] " : '') . data_get($st, 'errors.0.title', ''));

        $m->update([
            'status' => $status,
            'payload' => $payload,
            'error_message' => $error !== '' ? $error : $m->error_message,
        ]);
    }

    /**
     * أحداث الحساب: الحدث السلبي وحده يقفل الإرسال.
     * كان أيّ account_update يقفله — ومنها أحداث عادية تصل عند ربط الحساب أو توثيقه —
     * فيسكت البوت بلا خطأ ظاهر. الأحداث الأخرى تُسجَّل فقط.
     */
    private static function handleAccountEvent(string $field, array $value): void
    {
        $critical = ['account_update', 'phone_number_quality_update'];
        if (!in_array($field, $critical, true)) return;

        $event = data_get($value, 'event') ?: data_get($value, 'current_limit') ?: $field;
        Log::warning("WA account event [{$field}]: " . json_encode($value));

        $bad = ['FLAGGED', 'DOWNGRADE', 'ACCOUNT_VIOLATION', 'ACCOUNT_RESTRICTION', 'DISABLED_UPDATE', 'ACCOUNT_DELETED'];
        $violation = data_get($value, 'violation_info') || data_get($value, 'ban_info');
        if (in_array(strtoupper((string) $event), $bad, true) || $violation) {
            WaBotSetting::current()->update(['wa_suspended' => true]);
            self::notifyAdmins('⛔ إيقاف إرسال الواتساب', "وصل حدث «{$event}» من ميتا، فأُوقف كل الإرسال تلقائياً. راجع صحّة الحساب قبل إعادة التشغيل.");
        }
    }

    public static function notifyAdmins(string $title, string $body, string $url = '/whatsapp/inbox'): void
    {
        try {
            $ids = \App\Models\User::whereHas('role', fn ($q) => $q->where('slug', 'admin'))->where('is_active', true)->pluck('id');
            foreach ($ids as $id) {
                \App\Services\NotificationService::send($id, $title, $body, [
                    'type' => 'whatsapp', 'icon' => '💬', 'action_url' => $url,
                ]);
            }
        } catch (\Throwable $e) {
            Log::error('WA notify failed: ' . $e->getMessage());
        }
    }
}
