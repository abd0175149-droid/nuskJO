<?php

namespace App\Services\WhatsApp;

use App\Jobs\ProcessWhatsAppReply;
use App\Models\WaAdminNumber;
use App\Models\WaConversation;
use Illuminate\Cache\DatabaseStore;
use Illuminate\Database\Query\Builder;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * الردود العالقة (E17.5): وارد من العميل بلا رد منذ دقائق، والبوت يملك المحادثة ونافذتها مفتوحة ولا تدخّل.
 * التقرير والتشخيص بمعرّفات وأعداد فقط، بلا هاتف ولا نص. وإعادة المعالجة لا تحرّر قفلاً أحدث من مهلة الوظيفة.
 */
final class StuckReplies
{
    /** قائمة العالقة لبطاقة المدير (InboxSummary) وزر الإعادة */
    public const CACHE_KEY = 'wa:stuck:ids';

    public const CACHE_MINUTES = 15;

    /** عالقة رغم المُصالِح: تنبيه للإدارة مرة في الساعة */
    public const ALERT_MINUTES = 15;

    /** @return Collection<int, WaConversation> */
    public static function find(int $minutes = 3): Collection
    {
        $pending = fn (Builder $q) => $q->selectRaw('1')->from('wa_messages')
            ->whereColumn('wa_messages.conversation_id', 'wa_conversations.id')
            ->where('wa_messages.direction', 'in')
            ->where('wa_messages.source', 'customer')
            ->where('wa_messages.msg_type', '!=', 'reaction')
            ->whereRaw('wa_messages.id > COALESCE(wa_conversations.bot_answered_in_id, 0)');

        return WaConversation::query()
            ->where('needs_attention', false)
            ->where('status', '!=', 'merged')
            ->where('bot_enabled', true)
            ->where(fn ($q) => $q->whereNull('bot_paused_until')->orWhere('bot_paused_until', '<=', now()))
            ->where('last_inbound_at', '>', now()->subDay())
            ->where('is_admin_chat', false)
            ->where(fn ($q) => $q->whereNull('phone')->orWhereNotIn('phone', WaAdminNumber::where('is_active', true)->select('phone')))
            ->whereExists($pending)
            ->whereNotExists(fn (Builder $q) => $pending($q)->where('wa_messages.created_at', '>', now()->subMinutes($minutes)))
            ->orderBy('last_inbound_at')
            ->limit(200)
            ->get();
    }

    /**
     * @return array{conv_id: int, waiting_min: int, state: string, attempts: int}
     *                                                                             state: running | queued | lock_held | reconcile_attempts | none
     */
    public static function diagnose(WaConversation $c): array
    {
        $newest = ReplyState::newestPending($c);
        $attempts = $newest ? (int) Cache::get("wa:recon:{$c->id}:{$newest->id}", 0) : 0;

        $state = match (true) {
            Cache::has(ProcessWhatsAppReply::runningKey($c->id)) => 'running',
            self::queued($c->id) => 'queued',
            self::lockAge($c->id) !== null => 'lock_held',
            $attempts > 0 => 'reconcile_attempts',
            default => 'none',
        };

        return [
            'conv_id' => $c->id,
            'waiting_min' => $newest ? (int) $newest->created_at->diffInMinutes(now()) : 0,
            'state' => $state,
            'attempts' => $attempts,
        ];
    }

    /** مفتاح قفل WithoutOverlapping للوظيفة كما يحسبه Laravel (بلا بادئة الكاش) */
    public static function lockName(int $convId): string
    {
        return (new WithoutOverlapping("wa-conv:{$convId}"))->getLockKey(new ProcessWhatsAppReply($convId, ''));
    }

    /**
     * عمر قفل المحادثة بالثواني من جدول الأقفال (الكاش database على الخادم)، أو null إن لا قفل أو لا يُعرف.
     * القفل يُكتب بانتهاء = وقت الأخذ + LOCK_SECONDS.
     */
    public static function lockAge(int $convId): ?int
    {
        $store = Cache::getStore();
        if (!$store instanceof DatabaseStore) {
            return null;
        }
        $table = config('cache.stores.database.lock_table') ?: 'cache_locks';
        $connection = config('cache.stores.database.lock_connection') ?? config('cache.stores.database.connection');
        $expiration = DB::connection($connection)->table($table)->where('key', $store->getPrefix().self::lockName($convId))->value('expiration');
        if ($expiration === null) {
            return null;
        }

        return max(0, now()->getTimestamp() - ((int) $expiration - ProcessWhatsAppReply::LOCK_SECONDS));
    }

    /** وظيفة رد تنتظر في الطابور لهذه المحادثة (المعرّف في الحمولة المسلسلة) */
    private static function queued(int $convId): bool
    {
        try {
            return DB::table('jobs')
                ->where('queue', ProcessWhatsAppReply::queueName())
                ->where('payload', 'like', '%ProcessWhatsAppReply%')
                ->where('payload', 'like', '%conversationId_";i:'.$convId.';%')
                ->exists();
        } catch (\Throwable) {
            return false;
        }
    }

    /** وظائف رد فشلت نهائياً خلال 24 ساعة (لا queue:retry لها: رمز الدمج انتهى، والإعادة عبر schedule) */
    public static function failedJobs24h(): int
    {
        try {
            return DB::table('failed_jobs')
                ->where('failed_at', '>=', now()->subDay())
                ->where('payload', 'like', '%ProcessWhatsAppReply%')
                ->count();
        } catch (\Throwable) {
            return 0;
        }
    }

    /** @return list<int> */
    public static function cachedIds(): array
    {
        return array_map('intval', (array) Cache::get(self::CACHE_KEY, []));
    }
}
