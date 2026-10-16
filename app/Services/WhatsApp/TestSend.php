<?php

namespace App\Services\WhatsApp;

use App\Models\WaAdminNumber;
use App\Models\WaConversation;
use App\Models\WaMessage;
use App\Support\FeatureFlags;
use Illuminate\Support\Facades\Schema;

/**
 * «إرسال تجريبي» (E23، العلم wa_test_send): إلى أرقام الإدارة المعلَّمة test_recipient وحدها، بنافذة مفتوحة، بنصوص ثابتة
 * من الكود (لا نص حر من المستخدم)، وبالمصدر test عبر WhatsAppChannel نفسه (الأقفال والإيقاف وصحة القناة والدفتر).
 * رسالة خدمة داخل النافذة: تُحتسب ضمن الألف المجانية شهرياً لكل رقم، وليست مجانية بالضرورة بعدها.
 */
final class TestSend
{
    public const KINDS = ['text', 'image', 'buttons', 'list'];

    public const SOURCE = 'test';

    public const RATE_KEY = 'wa-test-send';

    public static function enabled(): bool
    {
        return FeatureFlags::on('wa_test_send') && Schema::hasColumn('wa_admin_numbers', 'test_recipient');
    }

    /** المستلمون للوحة: الاسم وآخر أربعة أرقام والنافذة، بلا الرقم كاملاً */
    public static function recipients(): array
    {
        return WaAdminNumber::where('is_active', true)->where('test_recipient', true)->orderBy('name')->get()
            ->map(function (WaAdminNumber $n) {
                $conv = self::conversationFor($n);

                return [
                    'id' => $n->id,
                    'name' => $n->name,
                    'last4' => substr((string) $n->phone, -4),
                    'has_conversation' => $conv !== null,
                    'window_open' => (bool) $conv?->isWindowOpen(),
                    'minutes_left' => $conv?->windowMinutesLeft(),
                ];
            })->values()->all();
    }

    public static function conversationFor(WaAdminNumber $n): ?WaConversation
    {
        $phone = PhoneNormalizer::canonical($n->phone);

        return $phone === '' ? null : WaConversation::where('phone', $phone)->where(fn ($q) => $q->whereNull('status')->orWhere('status', '!=', 'merged'))
            ->orderByDesc('last_inbound_at')->orderByDesc('id')->first();
    }

    /**
     * يرسل النوع المطلوب بنصوصه الثابتة. يعيد نتيجة WhatsAppChannel وصف الرسالة المحفوظ (إن حُفظ).
     *
     * @return array{result: array, row: ?WaMessage}
     */
    public static function send(WaConversation $conv, string $kind, string $sendKey): array
    {
        $before = (int) WaMessage::where('conversation_id', $conv->id)->max('id');
        $stamp = now()->timezone('Asia/Amman')->format('Y-m-d H:i');
        $result = match ($kind) {
            'image' => WhatsAppChannel::sendImage($conv, rtrim((string) config('app.url'), '/').'/images/wa-test-card.png',
                "🧪 صورة تجريبية من نُسك — {$stamp}", self::SOURCE, sendKey: $sendKey),
            'buttons' => WhatsAppChannel::sendButtons($conv, "🧪 أزرار تجريبية من نُسك — {$stamp}",
                [['id' => 'test:1', 'title' => 'زر تجريبي 1'], ['id' => 'test:2', 'title' => 'زر تجريبي 2']], self::SOURCE, null, ['send_key' => $sendKey]),
            'list' => WhatsAppChannel::sendList($conv, "🧪 قائمة تجريبية من نُسك — {$stamp}", 'الخيارات',
                [['id' => 'test:a', 'title' => 'خيار تجريبي أ'], ['id' => 'test:b', 'title' => 'خيار تجريبي ب']], self::SOURCE, null, ['send_key' => $sendKey]),
            default => WhatsAppChannel::sendText($conv, "🧪 رسالة تجريبية من نُسك — {$stamp}", self::SOURCE, sendKey: $sendKey),
        };
        $row = WaMessage::where('conversation_id', $conv->id)->where('source', self::SOURCE)->where('id', '>', $before)->latest('id')->first();

        return ['result' => $result, 'row' => $row];
    }

    /** حالة رسالة تجريبية للوحة (علامات E21 حتى read)، بلا نصها */
    public static function status(WaMessage $m): array
    {
        return [
            'id' => $m->id,
            'status' => $m->status,
            'status_at' => $m->status_at?->toIso8601String(),
            'error_code' => $m->error_code,
            'meta_code' => $m->meta_code,
            'label' => $m->error_code ? (MetaErrorCatalog::byCode($m->error_code)['message'] ?? null) : null,
        ];
    }
}
