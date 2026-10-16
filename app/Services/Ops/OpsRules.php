<?php

namespace App\Services\Ops;

use App\Models\WaBotSetting;
use App\Services\WhatsApp\OutboundCounter;
use App\Support\FeatureFlags;
use Illuminate\Support\Facades\Log;

/**
 * قواعد التنبيه على لقطة OpsMetrics (E24، بديل Alertmanager). العتبات والعناوين والإجراءات في config('ops.rules')،
 * والمقياس لكل قاعدة هنا. الإرسال عبر OpsAlerts::notifyAdmins (الجرس وFCM مباشرة): مرة لكل قاعدة في فترتها
 * (ساعة، أو يوم، أو شهر)، و«عاد طبيعياً» مرة واحدة عند الزوال، بحالة كل قاعدة في مخزن ops: 'ops:rule:state:{rule}'.
 * العلم ops_alerts مطفأ: تُحسب القواعد وتُكتب انتقالاتها في سجل perf ولا إشعار (أسبوع مراجعة العتبات).
 * لا رسالة واتساب أبداً: القناة قد تكون المعطّلة. النص أرقام وفئات فقط.
 */
final class OpsRules
{
    public const STATE_KEY = 'ops:rule:state:';

    /** حالات E22 التي نبّهت عنها آلة صحة القناة بنفسها: لا تكرار لقاعدتي الاعتماد والقيود */
    private const HEALTH_ALERTED = ['TOKEN_INVALID', 'BLOCKED'];

    /** طابور أرشفة المرفقات (E14) تأخره تحذير في البطاقة، لا تنبيه: لا يعطّل الرد */
    private const BACKLOG_IGNORED = ['wa-media'];

    public function __construct(private OpsAlerts $alerts) {}

    public static function alerting(): bool
    {
        return FeatureFlags::on('ops_alerts');
    }

    /**
     * قيمة كل قاعدة وعتبتها وهل تنطلق. skipped: سبب التخطي (القاعدة لا تُقيَّم الآن).
     *
     * @return array<string, array{value: ?int, threshold: int, firing: bool, skipped: ?string, detail: array}>
     */
    public function evaluate(array $snap, ?WaBotSetting $s = null): array
    {
        $s ??= WaBotSetting::current();
        $out = [];
        foreach ((array) config('ops.rules', []) as $rule => $def) {
            $threshold = $rule === 'daily_outbound' ? (int) ($s->wa_daily_alert_threshold ?? $def['threshold']) : (int) $def['threshold'];
            [$value, $detail] = $this->value((string) $rule, $snap);
            $skipped = $this->skip((string) $rule, $snap);
            $firing = $skipped === null && $value !== null && ($rule === 'queue_backlog' || $rule === 'bot_latency' ? $value > $threshold : $value >= $threshold);
            $out[$rule] = ['value' => $value, 'threshold' => $threshold, 'firing' => $firing, 'skipped' => $skipped, 'detail' => $detail];
        }

        return $out;
    }

    /** @return array{0: ?int, 1: array} القيمة وتفصيل رقمي (اسم طابور أو فئة أو نوع آلي، من قوائم ثابتة) */
    private function value(string $rule, array $snap): array
    {
        $failed = (array) ($snap['outbound_failed_1h'] ?? []);

        return match ($rule) {
            'queue_backlog' => $this->oldestQueue($snap),
            'failed_jobs' => [self::int($snap['failed_jobs']['1h'] ?? null), []],
            'send_auth' => [self::int($failed['auth'] ?? null), []],
            'send_integrity' => [self::int($failed['integrity'] ?? null), []],
            'send_recipient' => [(int) ($failed['window'] ?? 0) + (int) ($failed['caller'] ?? 0) + (int) ($failed['quality'] ?? 0),
                ['window' => (int) ($failed['window'] ?? 0), 'caller' => (int) ($failed['caller'] ?? 0), 'quality' => (int) ($failed['quality'] ?? 0)]],
            'unknown_sends' => [self::int($snap['unknown_sends_1h'] ?? null), []],
            'webhook_failed' => [self::int($snap['webhook_failed_24h'] ?? null), []],
            'waiting_human' => [self::int($snap['waiting_human'] ?? null), []],
            'bot_latency' => [self::int($snap['bot_latency_p90_ms_1h'] ?? null), []],
            'daily_outbound' => [self::int($snap['outbound_today']['total'] ?? null), []],
            'service_free_tier' => [self::int($snap['service_billable_month'] ?? null), []],
            'automated_budget' => $this->overBudget($snap),
            default => [null, []],
        };
    }

    private function skip(string $rule, array $snap): ?string
    {
        // آلة صحة القناة (E22) نبّهت عن الرمز أو الحجب بنفسها: لا تكرار
        if (in_array($rule, ['send_auth', 'send_integrity'], true) && ($snap['channel_health_enabled'] ?? false)
            && in_array($snap['channel_health'] ?? null, self::HEALTH_ALERTED, true)) {
            return 'channel_health';
        }

        return null;
    }

    /** @return array{0: ?int, 1: array} */
    private function oldestQueue(array $snap): array
    {
        $worst = null;
        $queue = null;
        foreach ((array) ($snap['queue'] ?? []) as $name => $q) {
            $age = $q['oldest_age_s'] ?? null;
            if ($age !== null && !in_array($name, self::BACKLOG_IGNORED, true) && ($worst === null || $age > $worst)) {
                [$worst, $queue] = [(int) $age, (string) $name];
            }
        }

        return [$worst ?? 0, $queue !== null ? ['queue' => $queue] : []];
    }

    /** عدد الأنواع الآلية فوق ميزانيتها اليوم، والأنواع نفسها (بادئات SendKey) */
    private function overBudget(array $snap): array
    {
        $over = array_values(array_filter(OutboundCounter::automatedWithBudgets((array) ($snap['automated_today'] ?? [])),
            fn ($row) => $row['over']));

        return [count($over), ['kinds' => array_map(fn ($r) => $r['kind'].' '.$r['count'].'/'.$r['budget'], $over)]];
    }

    private static function int(mixed $v): ?int
    {
        return is_numeric($v) ? (int) $v : null;
    }

    /**
     * يطبّق القواعد: الإشعار في فترة القاعدة ما دامت قائمة، و«عاد طبيعياً» مرة عند الزوال. يعيد ما أُرسل أو سُجّل.
     *
     * @return array{fired: list<string>, resolved: list<string>, mode: string, rules: array}
     */
    public function apply(array $snap, ?WaBotSetting $s = null): array
    {
        $alerting = self::alerting();
        $store = OpsHealth::store();
        $fired = $resolved = [];
        $evaluated = $this->evaluate($snap, $s);

        foreach ($evaluated as $rule => $r) {
            $def = (array) config("ops.rules.{$rule}");
            $stateKey = self::STATE_KEY.$rule;
            $wasFiring = (bool) $store->get($stateKey);

            if ($r['firing']) {
                if (!$wasFiring) {
                    $store->forever($stateKey, now()->getTimestamp());
                    $this->log($rule, 'firing', $r, $alerting);
                }
                if ($alerting && $this->notifyOnce($rule, $def, $r)) {
                    $fired[] = $rule;
                }

                continue;
            }
            if ($wasFiring && $r['skipped'] === null) {
                $store->forget($stateKey);
                $this->log($rule, 'resolved', $r, $alerting);
                if ($alerting && $this->alerts->notifyAdmins('ops:'.$rule.':ok', '✅ عاد طبيعياً: '.self::plain((string) ($def['title'] ?? $rule)),
                    'القيمة الآن '.($r['value'] ?? 0).' (العتبة '.$r['threshold'].').', (string) ($def['url'] ?? OpsAlerts::URL))) {
                    $resolved[] = $rule;
                }
            }
        }

        return ['fired' => $fired, 'resolved' => $resolved, 'mode' => $alerting ? 'alert' : 'log', 'rules' => $evaluated];
    }

    /** فترة القاعدة: الساعة عبر مفتاح OpsAlerts نفسه، واليوم والشهر بمفتاح يحمل التاريخ (عمّان) */
    private function notifyOnce(string $rule, array $def, array $r): bool
    {
        $period = (string) ($def['period'] ?? 'hour');
        $key = 'ops:'.$rule;
        if ($period !== 'hour') {
            $stamp = now('Asia/Amman')->format($period === 'month' ? 'Ym' : 'Ymd');
            if (!OpsHealth::store()->add('ops:rule:period:'.$rule.':'.$stamp, 1, now()->addDays($period === 'month' ? 32 : 2))) {
                return false;
            }
            $key .= ':'.$stamp;
        }

        return $this->alerts->notifyAdmins($key, (string) ($def['title'] ?? $rule), $this->body($rule, $def, $r), (string) ($def['url'] ?? OpsAlerts::URL));
    }

    /** أرقام وفئات وأسماء طوابير وأنواع ثابتة فقط */
    private function body(string $rule, array $def, array $r): string
    {
        $detail = match ($rule) {
            'queue_backlog' => isset($r['detail']['queue']) ? " في الطابور {$r['detail']['queue']}" : '',
            'send_recipient' => ' (النافذة '.($r['detail']['window'] ?? 0).' · المستلم '.($r['detail']['caller'] ?? 0).' · الجودة '.($r['detail']['quality'] ?? 0).')',
            'automated_budget' => ($r['detail']['kinds'] ?? []) !== [] ? ': '.implode('، ', $r['detail']['kinds']) : '',
            default => '',
        };

        return 'القيمة '.($r['value'] ?? 0).$detail.' (العتبة '.$r['threshold'].'). '.($def['action'] ?? '');
    }

    /** سطر في سجل perf لكل انتقال (وضع التسجيل وضع التنبيه معاً)، بلا أي بيان شخصي */
    private function log(string $rule, string $state, array $r, bool $alerting): void
    {
        try {
            Log::channel('perf')->warning('ops_rule', ['rule' => $rule, 'state' => $state, 'value' => $r['value'], 'threshold' => $r['threshold'],
                'mode' => $alerting ? 'alert' : 'log']);
        } catch (\Throwable) {
            // السجل لا يُسقط الفحص
        }
    }

    /** العنوان بلا رمز تعبيري في أوله (يُسبق بـ ✅) */
    private static function plain(string $title): string
    {
        return trim(preg_replace('/^[^\p{L}\p{N}]+/u', '', $title) ?? $title);
    }
}
