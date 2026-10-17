<?php

namespace App\Services\WhatsApp;

use App\Models\WaAdminNumber;
use App\Models\WaBotSetting;
use App\Models\WaCall;
use App\Models\WaConversation;
use App\Models\WaMessage;
use App\Support\FeatureFlags;
use Illuminate\Support\Facades\Cache;

/**
 * المتابعة النصية بعد المكالمة الفائتة (E39، العلم calls_followup فوق calls_webhook). رسالة خدمة حرة داخل نافذة
 * فتحها العميل باتصاله، فلا قالب. مدفوعة بعد حد الخدمة الشهري (A9). تُرسل إن:
 * النافذة مفتوحة، والمحادثة ليست رقم إدارة ولا محظورة، ولا موظف يتابع الآن (bot_paused_until)، والإرسال غير موقوف،
 * ولم تُرسل متابعة للمحادثة خلال whatsapp.calls.followup_hours. عبر WhatsAppChannel (الأقفال وOutboundPolicy والدفتر)
 * بالمفتاح call:{id}، فإعادة الحدث لا تكررها. النص من الإعدادات بعد حارس الردود الحتمية (لا أسعار ولا دفع، والهاتف متغير).
 */
final class CallFollowup
{
    /** النص المقترح حتى يعتمد المالك نصاً (calls_followup_text فارغ) */
    public const DEFAULT_TEXT = 'أهلاً بك، وصلتنا مكالمتك ولم نتمكن من الرد الآن. اكتب لنا طلبك هنا وسنتابعه، أو يتصل بك أحد زملائنا من الرقم {company.phone} خلال الدوام.';

    public static function enabled(): bool
    {
        return CallEventHandler::enabled() && FeatureFlags::on('calls_followup');
    }

    /** النص المعتمد بالعربية (أو المقترح)، والإنجليزي إن ضُبط */
    public static function templates(?WaBotSetting $s = null): array
    {
        $s ??= WaBotSetting::current();

        return ['ar' => filled($s->calls_followup_text) ? (string) $s->calls_followup_text : self::DEFAULT_TEXT,
            'en' => filled($s->calls_followup_text_en) ? (string) $s->calls_followup_text_en : null];
    }

    /** @return ?string سبب عدم الإرسال، أو null إن أُرسلت */
    public static function afterMissed(WaConversation $conv, WaCall $call): ?string
    {
        $skip = self::skipReason($conv);
        if ($skip !== null) {
            WaLog::info('calls.followup_skipped', ['conv_id' => $conv->id, 'reason' => $skip]);

            return $skip;
        }

        $text = self::text($conv);
        if ($text === null) {
            if (Cache::add('wa:call:fu:text-rejected:'.now()->format('Ymd'), 1, now()->addDay())) {
                WaLog::error('calls.followup_text_rejected', ['conv_id' => $conv->id]);
            }

            return 'text';
        }
        $key = "wa:call:fu:{$conv->id}";
        if (!Cache::add($key, 1, now()->addHours(max(1, (int) config('whatsapp.calls.followup_hours', 6))))) {
            return 'recent';
        }

        $res = WhatsAppChannel::sendText($conv, $text, 'system', sendKey: SendKey::call($call->wa_call_id));
        if (!$res['ok']) {
            // لم تخرج: الفائتة التالية تحاول (والمفتاح يمنع التكرار إن كانت ميتا قد قبلتها)
            Cache::forget($key);
            WaLog::warning('calls.followup_failed', ['conv_id' => $conv->id, 'code' => $res['code'] ?? null]);

            return 'send_failed';
        }
        $id = WaMessage::where('conversation_id', $conv->id)->where('direction', 'out')
            ->where(fn ($q) => filled($res['message_id'] ?? null) ? $q->where('wamid', $res['message_id']) : $q->where('send_key', SendKey::call($call->wa_call_id)))
            ->value('id');
        if ($id) {
            WaCall::whereKey($call->id)->update(['followup_message_id' => $id]);
        }

        return null;
    }

    private static function skipReason(WaConversation $conv): ?string
    {
        if (!self::enabled()) {
            return 'off';
        }
        $conv->refresh();
        $s = WaBotSetting::current();

        return match (true) {
            $s->sendingSuspended() => 'suspended',
            $conv->isBlocked() => 'blocked',
            (bool) $conv->is_admin_chat || ($conv->hasPhone() && WaAdminNumber::forPhone($conv->phone) !== null) => 'admin',
            $conv->bot_paused_until !== null && $conv->bot_paused_until->isFuture() => 'staff_active',
            !$conv->isWindowOpen() => 'window',
            default => null,
        };
    }

    /** الإنجليزي إن كانت آخر رسالة نصية للعميل لاتينية وضُبط نصه، وإلا العربي */
    private static function text(WaConversation $conv): ?string
    {
        $t = self::templates();
        $last = (string) $conv->messages()->where('direction', 'in')->where('source', 'customer')->where('msg_type', 'text')->orderByDesc('id')->value('body');
        $template = $t['en'] !== null && $last !== '' && HandoffGuard::isLatin($last) ? $t['en'] : $t['ar'];

        return RuleTextGuard::fill($template);
    }
}
