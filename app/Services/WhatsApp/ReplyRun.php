<?php

namespace App\Services\WhatsApp;

use App\Models\WaBotSetting;
use App\Models\WaConversation;
use App\Models\WaMessage;

/**
 * تشغيل رد واحد (وظيفة ProcessWhatsAppReply): يبدأ بلقطة، ويُسأل قبل كل إرسال هل ما زال الرد مناسباً.
 * يُسقط الرد إن ردّ موظف أو أطفأ البوت أو أوقفه أثناء التوليد. لا يُسقطه تحويل نفّذه التشغيل نفسه،
 * ولا رسالة أحدث من العميل (العلامة تضمن لها رداً ثانياً).
 * تبقى نافذة ضيقة بين الفحص ونداء /messages لا يمكن إغلاقها.
 */
final class ReplyRun
{
    /** ميزانية الحلقة كلها من بداية الوظيفة، ومعها التفريغ الصوتي (ReplyTimingInvariantsTest) */
    public const BUDGET_SECONDS = 95;

    /** أقل من هذا قبل دورة نموذج: تنقطع الحلقة (budget) ويُحوَّل العميل لموظف */
    public const MIN_TURN_SECONDS = 15;

    /** إعادة نداء النموذج بعد خلل عابر: مرة واحدة، وبعد 1.5 ثانية، وفقط إن بقي أكثر من هذا */
    public const RETRY_MIN_SECONDS = 25;

    public const RETRY_DELAY_MS = 1500;

    /** توليد بطاقة عرض (Chromium) لا يبدأ بأقل من هذا */
    public const CARD_MIN_SECONDS = 40;

    /** تفريغ صوتي لا يبدأ بأقل من هذا، ويترك للحلقة بعده VOICE_RESERVE_SECONDS */
    public const VOICE_MIN_SECONDS = 50;

    public const VOICE_RESERVE_SECONDS = 45;

    /** أطول مهلة لنداء نموذج واحد، وللتفريغ */
    public const CALL_TIMEOUT_SECONDS = 40;

    public const VOICE_TIMEOUT_SECONDS = 60;

    public float $startedAt;

    /** أعلى وارد دخل سياق النموذج: إليه تتحرك العلامة بعد الإرسال */
    public int $uptoId = 0;

    /** نفّذ هذا التشغيل تحويلاً (handoff/request_quote/confirm_booking): تأكيده لا يُسقط */
    public bool $escalated = false;

    /** سبب قطع الحلقة (budget أو stale_*) */
    public ?string $abort = null;

    private static ?self $current = null;

    public function __construct(public string $token, public int $startMaxMsgId, ?float $startedAt = null)
    {
        $this->startedAt = $startedAt ?? microtime(true);
    }

    public static function start(WaConversation $conv, string $token): self
    {
        return new self($token, (int) WaMessage::where('conversation_id', $conv->id)->max('id'));
    }

    public function remaining(): float
    {
        return self::BUDGET_SECONDS - (microtime(true) - $this->startedAt);
    }

    public function hasAtLeast(int $seconds): bool
    {
        return $this->remaining() >= $seconds;
    }

    /** مهلة نداء النموذج التالي: ما بقي ناقص 5 ثوانٍ للإرسال، بين 5 و40 */
    public function callTimeout(): int
    {
        return (int) max(5, min(self::CALL_TIMEOUT_SECONDS, $this->remaining() - 5));
    }

    /** مهلة التفريغ: ما بقي ناقص حصة الحلقة، بين 5 و60 */
    public function voiceTimeout(): int
    {
        return (int) max(5, min(self::VOICE_TIMEOUT_SECONDS, $this->remaining() - self::VOICE_RESERVE_SECONDS));
    }

    public static function current(): ?self
    {
        return self::$current;
    }

    public static function bind(self $run): void
    {
        self::$current = $run;
    }

    public static function clear(): void
    {
        self::$current = null;
    }

    /** سبب الإسقاط، أو null إن جاز الإرسال. يقرأ المحادثة والإعدادات من القاعدة الآن */
    public function mayDeliver(WaConversation $conv, bool $customerPath = true): ?string
    {
        $fresh = $conv->fresh();
        if (!$fresh) {
            return 'stale_gone';
        }

        $staffReplied = WaMessage::where('conversation_id', $conv->id)
            ->where('source', 'staff')->where('status', '!=', 'failed')
            ->where('id', '>', $this->startMaxMsgId)->exists();
        if ($staffReplied) {
            return 'stale_takeover';
        }

        if (!$this->escalated) {
            if (!$fresh->bot_enabled) {
                return 'stale_disabled';
            }
            if ($fresh->bot_paused_until && $fresh->bot_paused_until->isFuture()) {
                return 'stale_paused';
            }
        }

        if ($customerPath && !WaBotSetting::current()->botReady()) {
            return 'stale_bot_off';
        }

        return null;
    }
}
