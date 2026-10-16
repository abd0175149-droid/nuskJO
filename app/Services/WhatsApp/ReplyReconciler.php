<?php

namespace App\Services\WhatsApp;

use App\Jobs\ProcessWhatsAppReply;
use App\Models\WaAdminNumber;
use App\Models\WaBotSetting;
use App\Models\WaConversation;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * المُصالِح (whatsapp:reconcile-replies كل دقيقة): وارد بقي بلا رد دقيقتين ولا وظيفة تعمل عليه
 * (عامل قُتل، جدولة فشلت بعد التخزين، وظيفة فشلت نهائياً) يُعاد جدولته مرتين كحد، ثم يصير «تدخّل».
 * الرد المزدوج ممنوع بثلاثة: schedule() يكتب رمزاً جديداً يُبطل المنتظرة، والبوابة لا تمر إن خُدم الوارد،
 * وWithoutOverlapping يسلسل معالجات المحادثة الواحدة.
 */
final class ReplyReconciler
{
    public const BATCH = 50;

    /** أحدث وارد معلّق وصل قبل هذا على الأقل (الوظيفة العادية تبدأ بعد ثانيتين) */
    public const MIN_AGE_MINUTES = 2;

    /** نافذة الاختيار بعد أقصى عمر للرد: ما بعدها متأخر أصلاً */
    public const LOOKBACK_EXTRA_MINUTES = 15;

    public const MAX_ATTEMPTS = 2;

    public const ATTEMPTS_TTL_HOURS = 2;

    public const QUEUE_LAG_MINUTES = 5;

    /**
     * @return array{skipped: ?string, queue_lag: int, actions: list<array{conv_id: int, message_id: int, action: string}>}
     */
    public static function run(bool $dryRun = false): array
    {
        $result = ['skipped' => null, 'queue_lag' => self::checkQueueLag($dryRun), 'actions' => []];

        $s = WaBotSetting::current();
        $result['skipped'] = match (true) {
            !ReplyState::available() => 'no_watermark_column',
            !$s->flag('reconcile') => 'flag_off',
            $s->sendingSuspended() => 'suspended',
            // البوت مطفأ: بوابات BotEngine ترفع الشارة عند وصول الرسالة
            !$s->botReady() => 'bot_off',
            default => null,
        };
        if ($result['skipped'] !== null) {
            return $result;
        }

        foreach (self::candidates($s) as $conv) {
            if (!$conv->botActive() || !$conv->isWindowOpen() || Cache::has(ProcessWhatsAppReply::runningKey($conv->id))) {
                continue;
            }
            $newest = ReplyState::newestPending($conv);
            if (!$newest) {
                continue;
            }

            $action = self::decide($conv, $s, $newest->id, $dryRun);
            $result['actions'][] = ['conv_id' => $conv->id, 'message_id' => $newest->id, 'action' => $action];
        }

        return $result;
    }

    /** محادثات فيها وارد غير مخدوم أحدثه عمره دقيقتان على الأقل، والبوت يملكها */
    private static function candidates(WaBotSetting $s)
    {
        $pending = fn (Builder $q) => $q->selectRaw('1')->from('wa_messages')
            ->whereColumn('wa_messages.conversation_id', 'wa_conversations.id')
            ->where('wa_messages.direction', 'in')
            ->where('wa_messages.msg_type', '!=', 'reaction')
            ->whereRaw('wa_messages.id > COALESCE(wa_conversations.bot_answered_in_id, 0)');

        return WaConversation::query()
            ->where('needs_attention', false)
            ->where('bot_enabled', true)
            ->where(fn ($q) => $q->whereNull('bot_paused_until')->orWhere('bot_paused_until', '<=', now()))
            ->where('last_inbound_at', '>', now()->subMinutes(ReplyState::maxAgeMinutes($s) + self::LOOKBACK_EXTRA_MINUTES))
            ->whereNotIn('phone', WaAdminNumber::where('is_active', true)->select('phone'))
            ->whereExists($pending)
            ->whereNotExists(fn (Builder $q) => $pending($q)->where('wa_messages.created_at', '>', now()->subMinutes(self::MIN_AGE_MINUTES)))
            ->orderBy('last_inbound_at')
            ->limit(self::BATCH)
            ->get();
    }

    private static function decide(WaConversation $conv, WaBotSetting $s, int $messageId, bool $dryRun): string
    {
        if (ReplyState::isStale($conv, $s)) {
            return self::unserved($conv, 'stale', $dryRun);
        }
        // احترام حد ردود البوت في الساعة: لا تتجاوزه الإعادة
        if (BotEngine::rateLimited($conv, $s)) {
            return self::unserved($conv, 'rate_limit', $dryRun);
        }

        $key = "wa:recon:{$conv->id}:{$messageId}";
        if ($dryRun) {
            $attempt = (int) Cache::get($key, 0) + 1;
        } else {
            Cache::add($key, 0, now()->addHours(self::ATTEMPTS_TTL_HOURS));
            $attempt = (int) Cache::increment($key);
        }
        if ($attempt > self::MAX_ATTEMPTS) {
            return self::unserved($conv, 'reconcile_exhausted', $dryRun);
        }

        if (!$dryRun) {
            ProcessWhatsAppReply::schedule($conv->id);
            WaLog::info('reply.reconcile', ['conv_id' => $conv->id, 'message_id' => $messageId, 'count' => $attempt]);
        }

        return 'scheduled';
    }

    private static function unserved(WaConversation $conv, string $reason, bool $dryRun): string
    {
        if (!$dryRun) {
            ReplyState::markUnserved($conv, $reason);
        }

        return $reason;
    }

    /**
     * وظائف ردود تنتظر في الطابور أكثر من 5 دقائق (العامل متوقف أو غارق): إشعار للإدارة مرة في الساعة.
     * يعمل ولو كان العلم مطفأً، فهو قراءة فقط.
     */
    private static function checkQueueLag(bool $dryRun): int
    {
        try {
            $waiting = DB::table('jobs')
                ->where('queue', ProcessWhatsAppReply::queueName())
                ->whereNull('reserved_at')
                ->where('available_at', '<', now()->subMinutes(self::QUEUE_LAG_MINUTES)->getTimestamp())
                ->count();
        } catch (\Throwable) {
            return 0;   // طابور غير database أو جدول غير موجود
        }

        if ($waiting > 0 && !$dryRun && Cache::add('wa:queue:lag:'.now()->format('YmdH'), 1, now()->addHour())) {
            WaLog::warning('reply.queue_lag', ['count' => $waiting]);
            WhatsAppInbox::notifyAdmins('🐢 تأخر طابور ردود واتساب', "{$waiting} وظيفة رد تنتظر أكثر من ".self::QUEUE_LAG_MINUTES.' دقائق. تحقّق من عامل الطابور.', runbook: 'RB-11');
        }

        return $waiting;
    }
}
