<?php

namespace App\Services\WhatsApp;

use App\Jobs\SendWhatsAppReadReceipt;
use App\Models\User;
use App\Models\WaAdminNumber;
use App\Models\WaBotSetting;
use App\Models\WaConversation;
use App\Models\WaMessage;
use App\Support\FeatureFlags;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/**
 * إشعار القراءة ومؤشر «يكتب…» (E35). عند ميتا طلب واحد: status=read على wamid وارد، ومعه typing_indicator للمؤشر،
 * فالمؤشر يُظهر العلامتين الزرقاوين حتماً. لذلك يحكمهما إعداد واحد send_read_receipts:
 *   off (الافتراضي): لا شيء يصل ميتا، ويبقى عدّاد «غير مقروء» المحلي وحده.
 *   on_reply: «يكتب…» حين يبدأ البوت رداً سيرسله فعلاً (BotEngine::willReply)، والعلامتان عند رد الموظف.
 *   on_open: كالسابق، ويُضاف الفتح الصريح للمحادثة من الموكَّل بها (POST /read). الاستطلاع لا يرسل شيئاً أبداً.
 * وفوقه العلم read_receipts: مطفأ = off مهما كان الإعداد. النداء نفسه WhatsAppChannel::markRead خارج dispatch،
 * ولا sleep ولا تأخير مصطنع في أي مسار.
 */
final class ReadReceipts
{
    public const MODES = ['off', 'on_reply', 'on_open'];

    /** ميتا تقبل إشعار القراءة خلال 30 يوماً من استلام الرسالة */
    public const TARGET_DAYS = 30;

    /** مهلة النداء ومهلة الاتصال (ثوانٍ): من ميزانية الرد، وفشله لا يوقف أي رد */
    public const TIMEOUT = 5;

    public const CONNECT_TIMEOUT = 2;

    /** المؤشر ينطفئ عند ميتا بعد 25 ثانية: يُجدَّد قبل نداء النموذج إن مرت 20 */
    public const RENEW_SECONDS = 20;

    /** لا مؤشر في البداية بأقل من هذا من ميزانية الرد (ولا تجديد بأقل من ReplyRun::MIN_TURN_SECONDS) */
    public const START_MIN_SECONDS = 20;

    /** تهدئة إيصال الفتح لكل محادثة (ثوانٍ) */
    public const OPEN_COOLDOWN_SECONDS = 60;

    private static ?bool $available = null;

    /** العمودان موجودان (بين النشر والهجرة 2026_10_14_000035 لا يوجدان، فلا شيء يُرسل) */
    public static function available(): bool
    {
        return self::$available ??= Schema::hasColumn('wa_conversations', 'read_receipt_upto_id')
            && Schema::hasColumn('wa_bot_settings', 'send_read_receipts');
    }

    /** للاختبارات والهجرات داخل العملية نفسها */
    public static function flush(): void
    {
        self::$available = null;
    }

    /** الوضع المضبوط في الإعدادات كما هو (قيمة مجهولة = off) */
    public static function configured(?WaBotSetting $s = null): string
    {
        $mode = (string) (($s ?? WaBotSetting::current())->send_read_receipts ?? 'off');

        return in_array($mode, self::MODES, true) ? $mode : 'off';
    }

    /** الوضع الفعلي: off ما لم يُفعَّل العلم وتُشغَّل الهجرة */
    public static function mode(?WaBotSetting $s = null): string
    {
        if (!self::available() || !FeatureFlags::on('read_receipts')) {
            return 'off';
        }

        return self::configured($s);
    }

    /** on_reply أو on_open: المؤشر عند البوت والعلامتان عند رد الموظف */
    public static function onReply(): bool
    {
        return self::mode() !== 'off';
    }

    public static function onOpen(): bool
    {
        return self::mode() === 'on_open';
    }

    /** مؤشر الكتابة: الوضع ليس off، ومفتاح الطوارئ الداخلي WA_TYPING_INDICATOR لم يطفئه (إصدار Graph يرفضه) */
    public static function typingEnabled(): bool
    {
        return self::onReply() && (bool) config('whatsapp.read_receipts.typing', true);
    }

    /**
     * هدف الإشعار: أحدث رسالة من العميل لها wamid خلال 30 يوماً، لا تفاعل ولا رسالة نظام، وحتى $upto إن مُرّر.
     * ميتا تعلّم ما قبلها مقروءاً تلقائياً، فطلب واحد يكفي.
     */
    public static function target(WaConversation $conv, ?int $upto = null): ?WaMessage
    {
        return WaMessage::where('conversation_id', $conv->id)
            ->where('direction', 'in')->where('source', 'customer')
            ->whereNotIn('msg_type', ['reaction', 'system'])
            ->whereNotNull('wamid')
            ->where('created_at', '>', now()->subDays(self::TARGET_DAYS))
            ->when($upto !== null, fn ($q) => $q->where('id', '<=', $upto))
            ->orderByDesc('id')->first(['id', 'wamid']);
    }

    /** أعلى وارد أُبلغت ميتا بقراءته، كما في القاعدة الآن */
    public static function upto(WaConversation $conv): int
    {
        return (int) WaConversation::whereKey($conv->id)->value('read_receipt_upto_id');
    }

    /** رتيب: لا يتراجع أبداً */
    public static function advance(WaConversation $conv, int $id): void
    {
        WaConversation::whereKey($conv->id)
            ->where(fn ($q) => $q->whereNull('read_receipt_upto_id')->orWhere('read_receipt_upto_id', '<', $id))
            ->update(['read_receipt_upto_id' => $id]);
        $conv->setAttribute('read_receipt_upto_id', max((int) $conv->read_receipt_upto_id, $id));
        $conv->syncOriginalAttribute('read_receipt_upto_id');
    }

    /**
     * فشل الطلب (رد غير 2xx أو مهلة أو انقطاع): سطر info بالمعرّفات وحدها، وعدّاد يومي وآخر رمز لـ whatsapp:doctor.
     * لا wa_last_error ولا MetaErrorReactor ولا عدّاد الصحة: 131009 على رسالة قديمة ليس عطلاً في القناة،
     * والرمز 190 يصل إنذار الرمز من مساراته. لا إعادة.
     */
    public static function failed(WaConversation $conv, string $code, ?int $metaCode = null, ?string $fbtraceId = null, ?int $http = null): void
    {
        WaLog::info('wa.read_receipt_failed', ['conv_id' => $conv->id, 'code' => $code, 'meta_code' => $metaCode, 'fbtrace_id' => $fbtraceId, 'http' => $http]);
        try {
            $key = 'wa:rr:fail:'.now()->format('Ymd');
            Cache::add($key, 0, now()->addDays(3));
            Cache::increment($key);
            Cache::put('wa:rr:last_code', $metaCode !== null ? "{$code} ({$metaCode})" : $code, now()->addDays(3));
        } catch (\Throwable) {
            // العدّاد لا يُسقط شيئاً
        }
    }

    public static function failuresToday(): int
    {
        return (int) Cache::get('wa:rr:fail:'.now()->format('Ymd'), 0);
    }

    public static function lastFailureCode(): ?string
    {
        return Cache::get('wa:rr:last_code');
    }

    // ==================== مؤشر الكتابة (بوت العملاء والمساعد الإداري) ====================

    /**
     * بداية وظيفة الرد، بعد القفل وReplyRun وقبل تفريغ الصوت (فيغطيه): مؤشر واحد إن كان البوت سيرد فعلاً
     * (البوابات الرخيصة، BotEngine::willReply)، وبقي من الميزانية 20 ثانية على الأقل. فشله لا يوقف شيئاً.
     */
    public static function startTyping(WaConversation $conv, ReplyRun $run): void
    {
        try {
            if (!self::typingEnabled() || !$run->hasAtLeast(self::START_MIN_SECONDS) || !BotEngine::willReply($conv)) {
                return;
            }
            if (WhatsAppChannel::markRead($conv, true)['ok']) {
                $run->lastTypingAt = microtime(true);
            }
        } catch (\Throwable $e) {
            WaLog::warning('wa.typing_failed', ['conv_id' => $conv->id, 'type' => class_basename($e)]);
        }
    }

    /**
     * قبل كل نداء للنموذج وبين ملفي صوت: تجديد المؤشر إن مرت 20 ثانية على آخره (ينطفئ عند ميتا بعد 25)، وبقي من
     * الميزانية ما يكفي دورة. لا تجديد إن أسقط ReplyRun الرد (ردّ موظف أو أُطفئ البوت أثناء التوليد) في مسار العملاء:
     * المؤشر يختفي وحده. فشل التجديد يوقفه لهذا التشغيل، بلا إعادة.
     */
    public static function renewTyping(WaConversation $conv, ?ReplyRun $run, bool $customer = true): void
    {
        if (!$run || $run->lastTypingAt === null || microtime(true) - $run->lastTypingAt < self::RENEW_SECONDS
            || !$run->hasAtLeast(ReplyRun::MIN_TURN_SECONDS)) {
            return;
        }
        try {
            if (!self::typingEnabled() || ($customer && $run->mayDeliver($conv) !== null)) {
                $run->lastTypingAt = null;

                return;
            }
            $run->lastTypingAt = WhatsAppChannel::markRead($conv, true)['ok'] ? microtime(true) : null;
        } catch (\Throwable $e) {
            $run->lastTypingAt = null;
            WaLog::warning('wa.typing_failed', ['conv_id' => $conv->id, 'type' => class_basename($e)]);
        }
    }

    // ==================== إيصال القراءة: رد الموظف والفتح الصريح ====================

    /**
     * أعلى وارد كان موجوداً لحظة إرسال رد الموظف (قبل صف الرد إن عُرف)، فلا يُعلَّم مقروءاً ما وصل بعده.
     * null: لا إيصال لهذا الإرسال (ليس موظفاً، أو تفاعلاً لا رداً، أو الوضع off).
     */
    public static function replyUpto(WaConversation $conv, string $source, string $msgType, ?int $beforeId = null): ?int
    {
        if ($source !== 'staff' || $msgType === 'reaction' || !self::onReply()) {
            return null;
        }
        $upto = WaMessage::where('conversation_id', $conv->id)->where('direction', 'in')
            ->when($beforeId !== null, fn ($q) => $q->where('id', '<', $beforeId))->max('id');

        return $upto ? (int) $upto : null;
    }

    /** بعد نجاح رد الموظف: الإيصال بعد إرسال استجابة الصفحة (dispatchAfterResponse)، فلا يتأخر الصندوق بنداء Graph */
    public static function queueAfterReply(WaConversation $conv, ?int $upto): void
    {
        if ($upto === null || $upto <= (int) $conv->read_receipt_upto_id) {
            return;
        }
        try {
            SendWhatsAppReadReceipt::dispatchAfterResponse($conv->id, $upto, 'reply');
        } catch (\Throwable $e) {
            WaLog::warning('wa.read_receipt_failed', ['conv_id' => $conv->id, 'type' => class_basename($e)]);
        }
    }

    /**
     * فتح صريح للمحادثة (POST /read من E04، لا الاستطلاع) في الوضع on_open: إيصال لأحدث وارد، للموكَّل بها أو لمحادثة
     * غير موكلة، لا لمحادثة إدارة، ومرة في الدقيقة لكل محادثة.
     */
    public static function afterOpen(WaConversation $conv, User $user): bool
    {
        if (!self::onOpen() || !$user->can('whatsapp.reply')) {
            return false;
        }
        if ($conv->assigned_to && (int) $conv->assigned_to !== (int) $user->id) {
            return false;
        }
        if ($conv->is_admin_chat || ($conv->hasPhone() && WaAdminNumber::forPhone($conv->phone))) {
            return false;
        }
        $upto = (int) WaMessage::where('conversation_id', $conv->id)->where('direction', 'in')->max('id');
        if ($upto <= self::upto($conv) || !Cache::add("wa:rr:open:{$conv->id}", 1, self::OPEN_COOLDOWN_SECONDS)) {
            return false;
        }
        SendWhatsAppReadReceipt::dispatchAfterResponse($conv->id, $upto, 'open');

        return true;
    }

    /** لصاحب المحادثة في الخيط: فتحه في on_open يُظهر للعميل أنه قرأ */
    public static function openNotice(WaConversation $conv, User $user): bool
    {
        return self::onOpen() && $user->can('whatsapp.reply') && !$conv->is_admin_chat
            && (!$conv->assigned_to || (int) $conv->assigned_to === (int) $user->id);
    }
}
