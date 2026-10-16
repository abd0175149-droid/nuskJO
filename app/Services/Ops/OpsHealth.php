<?php

namespace App\Services\Ops;

use App\Exceptions\HealthCheckFailed;
use App\Jobs\ProcessWhatsAppReply;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * صحة التشغيل على /up (DiagnosingHealth): القاعدة، ونبضة المجدول، ونبضة كل عامل، ووظائف لم يلتقطها أحد.
 * رموز فقط بلا تفاصيل، ولا نداء لميتا هنا: حالة ميتا لا تُسقط الحاوية.
 * كان فحص Docker يطلب / فيأخذ تحويلاً إلى صفحة الدخول ويعدّه نجاحاً، والعامل أو المجدول ميت.
 */
final class OpsHealth
{
    public const SCHEDULE_BEAT = 'ops:schedule:beat';

    /** نبضة لكل طابور يخدمه عامل (laravel-queue على default، وlaravel-wa على طابور الردود) */
    public const QUEUE_BEAT = 'ops:queue:beat:';

    private const MEDIA_QUEUE = 'wa-media';

    private const MEDIA_OUT_QUEUE = 'wa-media-out';

    /** وظيفة أرشفة متاحة لم يلتقطها العامل منذ ساعة: تحذير في البطاقة فقط */
    private const MEDIA_BACKLOG_SECONDS = 3600;

    /** الرموز الممكنة، بالترتيب الذي تُعرض به */
    public const CODES = ['db', 'schedule_stale', 'queue_stale', 'reply_queue_stale', 'backlog'];

    public static function store(): Repository
    {
        return Cache::store(config('ops.cache_store', 'file'));
    }

    /** @return list<string> */
    public static function queues(): array
    {
        return array_values(array_unique(['default', ProcessWhatsAppReply::queueName()]));
    }

    public static function beatKey(string $queue): string
    {
        return self::QUEUE_BEAT.$queue;
    }

    /**
     * رموز الأعطال. محفوظة 10 ثوانٍ، فلا يصير /up العام وسيلة لإغراق القاعدة.
     *
     * @return list<string>
     */
    public function diagnose(): array
    {
        return self::store()->remember('ops:health:diagnosis', 10, fn () => $this->check());
    }

    /** @return list<string> */
    private function check(): array
    {
        try {
            DB::select('select 1');
            $now = now()->getTimestamp();
            $codes = [];

            if ($now - (int) Cache::get(self::SCHEDULE_BEAT, 0) > (int) config('ops.schedule_stale_seconds')) {
                $codes[] = 'schedule_stale';
            }
            foreach (self::queues() as $queue) {
                if ($now - (int) Cache::get(self::beatKey($queue), 0) > (int) config('ops.queue_stale_seconds')) {
                    $codes[] = $queue === 'default' ? 'queue_stale' : 'reply_queue_stale';
                }
            }
            // طابور الأرشيف (E14) له تحذيره الخاص ولا يُسقط /up: تنزيل متأخر لا يعطّل الرد
            // طابورا الوسائط (أرشفة الوارد، وملفات الموظف E32) لهما تحذيرهما ولا يُسقطان /up، وكذلك التكاملات (E40):
            // مستقبل خارجي معطّل أو عاملها الموقوف لا يجعل الحاوية unhealthy (سطرها في البطاقة ومُصالِحها ينبّه)
            $backlog = DB::table('jobs')->whereNull('reserved_at')->whereNotIn('queue', [self::MEDIA_QUEUE, self::MEDIA_OUT_QUEUE, (string) config('integrations.queue', 'integrations')])
                ->where('available_at', '<=', $now - (int) config('ops.backlog_stale_seconds'))
                ->exists();
            if ($backlog) {
                $codes[] = 'backlog';
            }

            return $codes;
        } catch (\Throwable) {
            // الكاش والطابور في القاعدة نفسها: أي فشل هنا فشل قاعدة
            return ['db'];
        }
    }

    /**
     * أعمار النبضات بالثواني (null: لا نبضة) وعدد الوظائف الفاشلة في 24 ساعة، لبطاقة الإعدادات وops:status.
     * last_inbound_minutes (E37): دقائق منذ آخر حدث من ميتا، للعرض فقط؛ لا يدخل codes ولا /up.
     *
     * @return array{enabled: bool, codes: list<string>, schedule_age: ?int, queues: array<string, ?int>, failed_24h: ?int, warnings: list<string>, last_inbound_minutes: ?int}
     */
    public function snapshot(): array
    {
        $now = now()->getTimestamp();
        $age = function (string $key) use ($now): ?int {
            try {
                $beat = Cache::get($key);
            } catch (\Throwable) {
                return null;
            }

            return $beat ? max(0, $now - (int) $beat) : null;
        };

        try {
            $failed = DB::table('failed_jobs')->where('failed_at', '>=', now()->subDay())->count();
        } catch (\Throwable) {
            $failed = null;
        }

        return [
            'enabled' => (bool) config('ops.health_checks'),
            'codes' => $this->check(),
            'schedule_age' => $age(self::SCHEDULE_BEAT),
            'queues' => collect(self::queues())->mapWithKeys(fn ($q) => [$q => $age(self::beatKey($q))])->all(),
            'failed_24h' => $failed,
            'warnings' => $this->warnings($now),
            // فهرس البحث في الرسائل (E30): آخر فحص يومي، أو null قبل أول فحص
            'search_index' => \App\Services\WhatsApp\Search\FtsSchema::lastCheck(),
            'last_inbound_minutes' => InboundSilence::minutesSinceLast(),
            // التكاملات الصادرة (E40): المعلّق وأقدمه والفاشل خلال 24 ساعة والوجهات الموقوفة، أو null قبل الحاجة
            'integrations' => \App\Services\Integrations\IntegrationsHealth::summary(),
        ];
    }

    /** @return list<string> تحذيرات لا تُسقط /up: media_backlog (تأخر أرشفة المرفقات) */
    private function warnings(int $now): array
    {
        try {
            return DB::table('jobs')->whereNull('reserved_at')->where('queue', self::MEDIA_QUEUE)
                ->where('available_at', '<=', $now - self::MEDIA_BACKLOG_SECONDS)->exists() ? ['media_backlog'] : [];
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * لمستمع DiagnosingHealth: يرمي فيرد /up بـ 500. سطر سجل لكل رمز مرة في الساعة،
     * وتنبيه للمدراء بعد إرسال الرد (من طلب يمر بـ nginx وphp-fpm، لا بالعامل أو المجدول المعطّل).
     */
    public function assertHealthy(): void
    {
        $codes = $this->diagnose();
        if ($codes === []) {
            return;
        }

        foreach ($codes as $code) {
            if (self::store()->add('ops:health:logged:'.$code, 1, 3600)) {
                Log::error('ops.health_failed', ['code' => $code]);
            }
        }
        // always: المؤجَّل لا يعمل افتراضياً حين يكون الرد 400 فما فوق، و/up هنا يرد 500
        defer(fn () => app(OpsAlerts::class)->healthDegraded($codes))->always();

        throw new HealthCheckFailed(implode(',', $codes));
    }
}
