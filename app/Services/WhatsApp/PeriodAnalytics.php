<?php

namespace App\Services\WhatsApp;

use App\Enums\AuditAction;
use App\Models\QuoteRequest;
use App\Models\User;
use App\Models\WaBotSetting;
use App\Models\WaBotUsage;
use App\Models\WaBotUsageMonthly;
use App\Models\WaConversation;
use App\Models\WaLlmIncident;
use App\Models\WaMessage;
use App\Services\Ops\FailuresCenter;
use App\Support\FeatureFlags;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * تحليلات البوت بفترات (E24، العلم analytics_v2؛ مطفأ = BotAnalytics القديم كما هو): تجميع SQL لا تحميل
 * wa_messages إلى الذاكرة، بفترات 24 ساعة و7 أيام و30 يوماً، ومخزّنة 5 دقائق لكل دالة وفترة.
 * قرارات الحساب الموثّقة في رأس BotAnalytics باقية: كل صف بنموذجه، والكاش بخصمه، وأيام عمّان، والمتوسط على
 * الأيام النشطة، والوسيط لكلفة المحادثة. وما تصلحه هذه النسخة:
 * - المصادر كلها في الكلفة (live وadmin وvoice وtranslate) بتفصيلها، لا live وحده.
 * - التحويلات = المحادثات المميزة التي لها تدقيق WaHandoff في الفترة، فلا يُعدّ طلب التسعير والتدخل مرتين.
 * - التجريبي (source=test، E23) خارج كل الأرقام، وغير المسلَّم (E19) خارج الحركة والأتمتة.
 *
 * التوقيت: الأردن على UTC+3 ثابتاً منذ 2022، وcreated_at بتوقيت UTC، فالتجميع بـ strftime(..., '+3 hours') كما
 * dayKey. إن عادت الأردن إلى التوقيت الصيفي يُستبدل TZ_SQL_OFFSET بحساب لكل يوم.
 */
final class PeriodAnalytics
{
    public const TZ = 'Asia/Amman';

    public const TZ_SQL_OFFSET = '+3 hours';

    /** الفترة => الساعات */
    public const PERIODS = ['24h' => 24, '7d' => 168, '30d' => 720];

    public const DEFAULT = '7d';

    /** مصادر كلفة النموذج المحتسبة (playground منفصل، وgate وoutage وrule بلا توكنز) */
    public const COST_SOURCES = ['live', 'admin', 'voice', 'translate'];

    private const INTENT_WORDS = ['سعر', 'كم', 'حجز', 'احجز', 'عرض', 'عروض', 'تذكر', 'تذاكر', 'عمرة', 'عمره', 'فندق', 'تاشير', 'تأشير', 'رحل'];

    public static function enabled(): bool
    {
        return FeatureFlags::on('analytics_v2');
    }

    public static function period(?string $p): string
    {
        return isset(self::PERIODS[$p ?? '']) ? (string) $p : self::DEFAULT;
    }

    public static function since(string $period): Carbon
    {
        return now()->subHours(self::PERIODS[self::period($period)]);
    }

    private static function cached(string $fn, string $period, callable $build): array
    {
        return Cache::remember("wa:analytics:{$fn}:".self::period($period), 300, $build);
    }

    /** مفتاح الدلو بتوقيت عمّان: ساعة لفترة 24 ساعة، ويوم لغيرها */
    private static function bucketSql(string $period, string $column = 'created_at'): string
    {
        $format = $period === '24h' ? '%Y-%m-%d %H' : '%Y-%m-%d';

        return "strftime('{$format}', {$column}, '".self::TZ_SQL_OFFSET."')";
    }

    /** الدلاء متصلة (بلا نشاط = صفر)، بالترتيب، مع تسمية قصيرة */
    private static function buckets(string $period): array
    {
        $out = [];
        $now = Carbon::now(self::TZ);
        if ($period === '24h') {
            for ($i = 23; $i >= 0; $i--) {
                $t = $now->copy()->subHours($i);
                $out[$t->format('Y-m-d H')] = $t->format('H:00');
            }
        } else {
            for ($i = self::PERIODS[$period] / 24 - 1; $i >= 0; $i--) {
                $t = $now->copy()->subDays($i);
                $out[$t->format('Y-m-d')] = $t->format('d/m');
            }
        }

        return $out;
    }

    /** الصادر الذي ثبت قبوله وحده، كما excludeUndelivered */
    private static function deliveredSql(string $alias = 'wa_messages'): string
    {
        $statuses = "'".implode("','", WaMessage::NOT_DELIVERED)."'";

        return "({$alias}.direction = 'in' OR {$alias}.status IS NULL OR {$alias}.status NOT IN ({$statuses}))";
    }

    // ==================================================================
    //  الحركة
    // ==================================================================

    /** الوارد والصادر لكل دلو، والصادر بالمصدر. بلا test ولا غير المسلَّم */
    public static function traffic(string $period): array
    {
        $period = self::period($period);

        return self::cached('traffic', $period, function () use ($period) {
            $rows = WaMessage::query()->where('created_at', '>=', self::since($period))->where('source', '!=', OutboundCounter::TEST_SOURCE)
                ->whereRaw(self::deliveredSql())
                ->groupByRaw(self::bucketSql($period).', direction, source')
                ->selectRaw(self::bucketSql($period).' as b, direction, source, COUNT(*) as n')->get();

            $series = [];
            foreach (self::buckets($period) as $key => $label) {
                $series[$key] = ['key' => $key, 'label' => $label, 'in' => 0, 'out' => 0, 'bot' => 0, 'staff' => 0, 'system' => 0, 'rule' => 0];
            }
            $totals = ['in' => 0, 'out' => 0, 'bot' => 0, 'staff' => 0, 'system' => 0, 'rule' => 0];
            foreach ($rows as $r) {
                $n = (int) $r->n;
                if ($r->direction === 'in') {
                    $totals['in'] += $n;
                    if (isset($series[$r->b])) {
                        $series[$r->b]['in'] += $n;
                    }

                    continue;
                }
                $source = in_array($r->source, ['bot', 'staff', 'system', 'rule'], true) ? $r->source : 'system';
                $totals['out'] += $n;
                $totals[$source] += $n;
                if (isset($series[$r->b])) {
                    $series[$r->b]['out'] += $n;
                    $series[$r->b][$source] += $n;
                }
            }

            return ['period' => $period, 'series' => array_values($series), 'totals' => $totals];
        });
    }

    /** خريطة يوم الأسبوع × الساعة للوارد وحده (0 الأحد … 6 السبت، بتوقيت عمّان) */
    public static function peakHours(string $period): array
    {
        $period = self::period($period);

        return self::cached('peak', $period, function () use ($period) {
            $offset = self::TZ_SQL_OFFSET;
            $grid = array_fill(0, 7, array_fill(0, 24, 0));
            $rows = WaMessage::query()->where('direction', 'in')->where('created_at', '>=', self::since($period))
                ->where('source', '!=', OutboundCounter::TEST_SOURCE)
                ->groupByRaw("strftime('%w', created_at, '{$offset}'), strftime('%H', created_at, '{$offset}')")
                ->selectRaw("CAST(strftime('%w', created_at, '{$offset}') AS INTEGER) as d, CAST(strftime('%H', created_at, '{$offset}') AS INTEGER) as h, COUNT(*) as n")
                ->get();
            $max = 0;
            foreach ($rows as $r) {
                $grid[(int) $r->d][(int) $r->h] = (int) $r->n;
                $max = max($max, (int) $r->n);
            }

            return ['period' => $period, 'grid' => $grid, 'max' => $max];
        });
    }

    /** أكثر 10 محادثات نشاطاً ضمن نطاق المستخدم: الاسم أو الرقم مقنَّعاً، والأعداد وحدها (لا يُخزَّن: يتبع المستخدم) */
    public static function topConversations(string $period, User $user): array
    {
        $period = self::period($period);
        $rows = WaMessage::query()->where('created_at', '>=', self::since($period))->where('source', '!=', OutboundCounter::TEST_SOURCE)
            ->whereIn('conversation_id', WaConversation::query()->visibleTo($user)->select('wa_conversations.id'))
            ->groupBy('conversation_id')
            ->selectRaw("conversation_id, COUNT(*) as n, SUM(CASE WHEN direction = 'in' THEN 1 ELSE 0 END) as inbound")
            ->orderByDesc('n')->limit(10)->get();
        $convs = WaConversation::whereIn('id', $rows->pluck('conversation_id'))->get()->keyBy('id');

        return $rows->map(fn ($r) => [
            'id' => (int) $r->conversation_id,
            'name' => FailuresCenter::conversationLabel($convs[$r->conversation_id] ?? null),
            'messages' => (int) $r->n,
            'inbound' => (int) $r->inbound,
        ])->values()->all();
    }

    /**
     * أول رد بشري بعد التحويل: لكل تدقيق WaHandoff في الفترة أول صادر staff في المحادثة بعده (استعلام فرعي MIN)،
     * ثم الوسيط وp90 في PHP على عشرات القيم. بلا صفوف WaHandoff (التدقيق الموسّع مطفأ): طلبات التسعير بديلاً «تقديرياً».
     */
    public static function firstHumanResponse(string $period): array
    {
        $period = self::period($period);

        return self::cached('first_human', $period, function () use ($period) {
            $since = self::since($period);
            $staffAfter = fn (string $conv, string $at) => "(SELECT MIN(m.created_at) FROM wa_messages m WHERE m.conversation_id = {$conv}
                AND m.direction = 'out' AND m.source = 'staff' AND m.created_at >= {$at} AND ".self::deliveredSql('m').')';

            $estimated = !DB::table('audit_logs')->where('action', AuditAction::WaHandoff->value)->exists();
            $rows = $estimated
                ? DB::table('quote_requests as q')->whereNotNull('q.conversation_id')->where('q.created_at', '>=', $since)
                    ->selectRaw('q.created_at as h, '.$staffAfter('q.conversation_id', 'q.created_at').' as r')->get()
                : DB::table('audit_logs as a')->where('a.action', AuditAction::WaHandoff->value)->where('a.entity_type', 'wa_conversation')
                    ->where('a.created_at', '>=', $since)
                    ->selectRaw('a.created_at as h, '.$staffAfter('a.entity_id', 'a.created_at').' as r')->get();

            $waits = [];
            $open = 0;
            foreach ($rows as $row) {
                if ($row->r === null) {
                    $open++;

                    continue;
                }
                $waits[] = max(0, Carbon::parse($row->r, 'UTC')->getTimestamp() - Carbon::parse($row->h, 'UTC')->getTimestamp());
            }
            sort($waits);

            return ['period' => $period, 'handoffs' => count($rows), 'answered' => count($waits), 'unanswered' => $open,
                'median_s' => self::median($waits), 'p90_s' => self::p90($waits), 'estimated' => $estimated];
        });
    }

    /** تحوّل طلب التسعير إلى فاتورة: العدد، وما له فاتورة، والنسبة، وقيمة المعتمدة، والتوزيع بالنوع */
    public static function quoteConversion(string $period): array
    {
        $period = self::period($period);

        return self::cached('quotes', $period, function () use ($period) {
            $since = self::since($period);
            $byType = QuoteRequest::query()->where('created_at', '>=', $since)->groupBy('type')
                ->selectRaw('type, COUNT(*) as n, SUM(CASE WHEN invoice_id IS NOT NULL THEN 1 ELSE 0 END) as invoiced')->get();
            $total = (int) $byType->sum('n');
            $invoiced = (int) $byType->sum('invoiced');
            $value = (float) DB::table('quote_requests as q')->join('invoices as i', 'i.id', '=', 'q.invoice_id')
                ->where('q.created_at', '>=', $since)->where('i.status', 'approved')->sum('i.total_sell_jod');

            return [
                'period' => $period, 'requests' => $total, 'invoiced' => $invoiced,
                'rate' => $total ? round($invoiced * 100 / $total, 1) : 0.0,
                'won' => QuoteRequest::query()->where('created_at', '>=', $since)->where('status', 'won')->count(),
                'invoiced_value_jod' => round($value, 3),
                'by_type' => $byType->map(fn ($r) => ['type' => $r->type, 'label' => QuoteRequest::TYPE_LABELS[$r->type] ?? $r->type,
                    'requests' => (int) $r->n, 'invoiced' => (int) $r->invoiced])->values()->all(),
            ];
        });
    }

    // ==================================================================
    //  الاستهلاك والكلفة (شكل BotAnalytics::usage نفسه، فالتبويب لا يتغيّر)
    // ==================================================================

    private static function prices(): array
    {
        return array_merge(BotAnalytics::DEFAULT_PRICES, (array) (WaBotSetting::current()->model_prices ?? []));
    }

    public static function usage(string $period): array
    {
        $period = self::period($period);

        return self::cached('usage', $period, function () use ($period) {
            $prices = self::prices();
            $disc = (float) (WaBotSetting::current()->cache_discount ?? 0.25);
            $since = self::since($period);
            $sum = 'SUM(prompt_tokens) as prompt_tokens, SUM(output_tokens) as output_tokens, SUM(cache_read_tokens) as cache_read_tokens, COUNT(*) as replies';

            // دلو × نموذج × مصدر: rowCost على الصف المجمّع (الكلفة خطية في التوكنز)
            $rows = WaBotUsage::query()->whereIn('source', self::COST_SOURCES)->where('created_at', '>=', $since)
                ->groupByRaw(self::bucketSql($period).', model, source')
                ->selectRaw(self::bucketSql($period).' as b, model, source, '.$sum)->toBase()->get();
            $series = [];
            foreach (self::buckets($period) as $key => $label) {
                $series[$key] = ['day' => $key, 'label' => $label, 'cost' => 0.0, 'in' => 0, 'out' => 0, 'cache' => 0, 'replies' => 0];
            }
            $bySource = array_fill_keys(self::COST_SOURCES, ['cost' => 0.0, 'replies' => 0]);
            $periodCost = 0.0;
            $in = $out = $cache = $replies = 0;
            foreach ($rows as $r) {
                $c = BotAnalytics::rowCost($r, $prices, $disc);
                $periodCost += $c;
                $in += (int) $r->prompt_tokens;
                $out += (int) $r->output_tokens;
                $cache += (int) $r->cache_read_tokens;
                $replies += (int) $r->replies;
                $bySource[$r->source]['cost'] += $c;
                $bySource[$r->source]['replies'] += (int) $r->replies;
                if (isset($series[$r->b])) {
                    $series[$r->b]['cost'] += $c;
                    $series[$r->b]['in'] += (int) $r->prompt_tokens;
                    $series[$r->b]['out'] += (int) $r->output_tokens;
                    $series[$r->b]['cache'] += (int) $r->cache_read_tokens;
                    $series[$r->b]['replies'] += (int) $r->replies;
                }
            }
            $series = array_values(array_map(fn ($d) => ['cost' => round($d['cost'], 6)] + $d, $series));

            // الإجمالي منذ البداية: مجمّع بالنموذج، والأشهر المقلَّمة من wa_bot_usage_monthly (E13)
            $totalCost = 0.0;
            foreach (WaBotUsage::query()->whereIn('source', self::COST_SOURCES)->groupBy('model')->selectRaw('model, '.$sum)->toBase()->get() as $r) {
                $totalCost += BotAnalytics::rowCost($r, $prices, $disc);
            }
            $rolledUp = 0;
            if (Schema::hasTable('wa_bot_usage_monthly')) {
                $rawMonths = WaBotUsage::query()->whereIn('source', self::COST_SOURCES)->selectRaw("DISTINCT strftime('%Y-%m', created_at) as m")->pluck('m')->flip();
                foreach (WaBotUsageMonthly::whereIn('source', self::COST_SOURCES)->get() as $m) {
                    if (!$rawMonths->has($m->month)) {
                        $rolledUp++;
                        $totalCost += BotAnalytics::rowCost($m, $prices, $disc);
                    }
                }
            }

            // كلفة المحادثة في الفترة: الوسيط والمتوسط، وأغلى 5 (بالمعرّف؛ الأسماء تُضاف في المتحكم بنطاق المستخدم)
            $byConv = [];
            foreach (WaBotUsage::query()->whereIn('source', self::COST_SOURCES)->where('created_at', '>=', $since)->whereNotNull('conversation_id')
                ->groupBy('conversation_id', 'model')->selectRaw('conversation_id, model, '.$sum)->toBase()->get() as $r) {
                $byConv[$r->conversation_id] = ($byConv[$r->conversation_id] ?? 0) + BotAnalytics::rowCost($r, $prices, $disc);
            }
            $costs = array_values($byConv);
            sort($costs);
            arsort($byConv);
            $activeDays = $period === '24h' ? 1 : count(array_filter($series, fn ($d) => $d['replies'] > 0));
            $playground = 0.0;
            foreach (WaBotUsage::query()->where('source', 'playground')->where('created_at', '>=', $since)->groupBy('model')->selectRaw('model, '.$sum)->toBase()->get() as $r) {
                $playground += BotAnalytics::rowCost($r, $prices, $disc);
            }

            return [
                'period' => $period,
                'tiles' => [
                    'today_cost' => round($period === '24h'
                        ? array_sum(array_column(array_filter($series, fn ($d) => str_starts_with($d['day'], Carbon::now(self::TZ)->toDateString())), 'cost'))
                        : ($series[array_key_last($series)]['cost'] ?? 0), 6),
                    'cost_30' => round($periodCost, 6),
                    'cost_total' => round($totalCost, 6),
                    'replies' => $replies,
                    'replies_30' => $replies,
                    'tokens_in' => $in,
                    'tokens_out' => $out,
                    'tokens_cache' => $cache,
                    'daily_avg' => round($activeDays ? $periodCost / $activeDays : 0, 6),
                    'active_days' => $activeDays,
                    'avg_reply' => round($replies ? $periodCost / $replies : 0, 6),
                    'median_conv' => round(self::median($costs), 6),
                    'mean_conv' => round($costs ? array_sum($costs) / count($costs) : 0, 6),
                    'playground_cost' => round($playground, 6),
                    'rolled_up_months' => $rolledUp,
                ],
                'by_source' => array_map(fn ($s) => ['cost' => round($s['cost'], 6), 'replies' => $s['replies']], $bySource),
                'series' => $series,
                'top_conversations' => array_map(fn ($id) => ['id' => (int) $id, 'cost' => round($byConv[$id], 6)], array_slice(array_keys($byConv), 0, 5)),
                'prices' => $prices,
                'cache_discount' => $disc,
                'model' => WaBotSetting::current()->model,
            ];
        });
    }

    // ==================================================================
    //  الجودة (شكل BotAnalytics::quality نفسه)
    // ==================================================================

    public static function quality(string $period): array
    {
        $period = self::period($period);

        return self::cached('quality', $period, function () use ($period) {
            $since = self::since($period);
            $ledger = FeatureFlags::on('send_ledger');
            $notTest = fn ($q) => $q->where('source', '!=', OutboundCounter::TEST_SOURCE);
            $base = fn () => WaMessage::query()->where('created_at', '>=', $since)->tap($notTest)->when($ledger, fn ($q) => $q->whereRaw(self::deliveredSql()));

            // القمع: من تحدّث، ومن أبدى نيّة (LIKE على كلمات النية في الوارد)، ونقطة الحسم والحجز في الفترة
            $talked = (int) WaMessage::query()->where('direction', 'in')->where('created_at', '>=', $since)->tap($notTest)->distinct()->count('conversation_id');
            $intent = (int) WaMessage::query()->where('direction', 'in')->where('created_at', '>=', $since)->tap($notTest)
                ->where(function ($w) {
                    foreach (self::INTENT_WORDS as $word) {
                        $w->orWhere('body', 'like', '%'.$word.'%');
                    }
                })->distinct()->count('conversation_id');
            $decision = (int) QuoteRequest::query()->where('created_at', '>=', $since)->whereNotNull('conversation_id')->distinct()->count('conversation_id');
            $closed = QuoteRequest::query()->where('created_at', '>=', $since)->where('status', 'won')->count();

            // التفاعل (E33، msg_type=reaction) ليس رداً
            $bySource = $base()->where('direction', 'out')->where('msg_type', '!=', 'reaction')->groupBy('source')->selectRaw('source, COUNT(*) as n')->pluck('n', 'source')->map(fn ($n) => (int) $n);
            $ruleReplies = (int) ($bySource['rule'] ?? 0);
            $botReplies = (int) ($bySource['bot'] ?? 0) + $ruleReplies;
            $staffReplies = (int) ($bySource['staff'] ?? 0);
            $systemMsgs = (int) ($bySource['system'] ?? 0);
            $voice = $base()->where('direction', 'in')->where('msg_type', 'audio')->count();

            [$latencies, $unanswered] = self::replyLatencies($since, $ledger);
            $handoffs = self::handoffs($since);

            $usage = fn () => WaBotUsage::query()->where('source', 'live')->where('model', '!=', 'rule')->where('created_at', '>=', $since);
            $usageCount = $usage()->count();
            $tools = self::toolCounts($since);
            $withTools = $usage()->whereNotNull('tools_used')->where('tools_used', '!=', '[]')->count();

            $metrics = [
                ['key' => 'automation', 'label' => 'نسبة الأتمتة', 'value' => ($botReplies + $staffReplies) ? round($botReplies * 100 / ($botReplies + $staffReplies)) : 0, 'unit' => '%', 'good' => 'high'],
                ['key' => 'rule_replies', 'label' => 'ردود القواعد', 'value' => $ruleReplies, 'unit' => '', 'good' => 'neutral'],
                ['key' => 'median', 'label' => 'وسيط زمن الردّ', 'value' => self::median($latencies), 'unit' => 'ث', 'good' => 'low'],
                ['key' => 'p90', 'label' => 'p90 لزمن الردّ', 'value' => self::p90($latencies), 'unit' => 'ث', 'good' => 'low'],
                ['key' => 'unanswered', 'label' => 'رسائل بلا جواب', 'value' => $unanswered, 'unit' => '', 'good' => 'low'],
                ['key' => 'handoff', 'label' => 'تحويلات لموظف (محادثات)', 'value' => $handoffs['count'], 'unit' => '', 'good' => 'neutral', 'estimated' => $handoffs['estimated']],
                ['key' => 'failures', 'label' => 'أعطال (فشل آمن)', 'value' => $systemMsgs, 'unit' => '', 'good' => 'low'],
                ['key' => 'leaks', 'label' => 'تسريب أدوات', 'value' => $usage()->where('leaked', true)->count(), 'unit' => '', 'good' => 'low'],
                ['key' => 'voice', 'label' => 'رسائل صوتية', 'value' => $voice, 'unit' => '', 'good' => 'neutral'],
                ['key' => 'tool_rate', 'label' => 'ردود استعملت أداة', 'value' => $usageCount ? round($withTools * 100 / $usageCount) : 0, 'unit' => '%', 'good' => 'high'],
                ['key' => 'llm_outage', 'label' => 'تحويلات بسبب تعطّل المزوّد', 'value' => WaBotUsage::where('source', 'outage')->where('created_at', '>=', $since)->count(), 'unit' => '', 'good' => 'low'],
                ['key' => 'llm_incidents', 'label' => 'حوادث المزوّد', 'value' => rescue(fn () => WaLlmIncident::where('opened_at', '>=', $since)->count(), 0, false), 'unit' => '', 'good' => 'low'],
                ['key' => 'tools_denied', 'label' => 'استدعاءات أدوات مرفوضة', 'value' => $tools['status']['denied'] ?? 0, 'unit' => '', 'good' => 'low'],
                ['key' => 'tools_failed', 'label' => 'أدوات فشلت', 'value' => $tools['status']['failed'] ?? 0, 'unit' => '', 'good' => 'low'],
                ...($ledger ? [
                    ['key' => 'send_unknown', 'label' => 'إرسال غير مؤكد', 'value' => WaMessage::where('direction', 'out')->where('status', WaMessage::STATUS_UNKNOWN)->where('created_at', '>=', $since)->count(), 'unit' => '', 'good' => 'low'],
                    ['key' => 'send_failed', 'label' => 'فشل إرسال', 'value' => WaMessage::where('direction', 'out')->where('status', WaMessage::STATUS_FAILED)->where('created_at', '>=', $since)->count(), 'unit' => '', 'good' => 'low'],
                ] : []),
                ...(MessageTranslator::enabled() ? [
                    ['key' => 'translations', 'label' => 'ترجمات للموظفين', 'value' => WaBotUsage::where('source', 'translate')->where('created_at', '>=', $since)->count(), 'unit' => '', 'good' => 'neutral'],
                ] : []),
            ];

            return [
                'period' => $period,
                'funnel' => [
                    ['key' => 'talked', 'label' => 'تحدّث معنا', 'value' => $talked],
                    ['key' => 'intent', 'label' => 'أبدى نيّة', 'value' => $intent],
                    ['key' => 'decision', 'label' => 'وصل لنقطة الحسم', 'value' => $decision],
                    ['key' => 'closed', 'label' => 'حُسم (تمّ الحجز)', 'value' => $closed],
                ],
                'metrics' => $metrics,
                'attention' => self::attention($since),
                'tools' => $tools['names'],
                'weekly' => self::weekly(),
            ];
        });
    }

    /**
     * زمن الرد: لكل وارد في الفترة، الرسالة التالية في محادثته (غير رسالة النظام) باستعلام فرعي على فهرس
     * (conversation_id, id). صادر = رد بزمنه، ووارد = بلا جواب، ولا شيء = آخر رسالة (لا يُعدّ).
     *
     * @return array{0: list<int>, 1: int}
     */
    private static function replyLatencies(Carbon $since, bool $ledger): array
    {
        $delivered = $ledger ? ' AND '.self::deliveredSql('x') : '';
        $rows = DB::table('wa_messages as m')
            ->leftJoin('wa_messages as n', 'n.id', '=', DB::raw("(SELECT x.id FROM wa_messages x WHERE x.conversation_id = m.conversation_id AND x.id > m.id
                AND NOT (x.direction = 'out' AND x.source = 'system') AND x.source != 'test' AND x.msg_type != 'reaction'{$delivered} ORDER BY x.id LIMIT 1)"))
            ->where('m.direction', 'in')->where('m.created_at', '>=', $since)->where('m.source', '!=', 'test')->where('m.msg_type', '!=', 'reaction')
            ->selectRaw("n.direction as nd, (strftime('%s', n.created_at) - strftime('%s', m.created_at)) as s")->get();
        $latencies = [];
        $unanswered = 0;
        foreach ($rows as $r) {
            if ($r->nd === 'out') {
                $latencies[] = max(0, (int) $r->s);
            } elseif ($r->nd === 'in') {
                $unanswered++;
            }
        }
        sort($latencies);

        return [$latencies, $unanswered];
    }

    /** المحادثات المميزة المحوّلة: تدقيق WaHandoff، وإلا (التدقيق الموسّع مطفأ) طلبات التسعير والتدخل معاً بلا تكرار */
    private static function handoffs(Carbon $since): array
    {
        if (DB::table('audit_logs')->where('action', AuditAction::WaHandoff->value)->exists()) {
            return ['count' => (int) DB::table('audit_logs')->where('action', AuditAction::WaHandoff->value)
                ->where('entity_type', 'wa_conversation')->where('created_at', '>=', $since)->distinct()->count('entity_id'), 'estimated' => false];
        }
        $union = DB::table('quote_requests')->whereNotNull('conversation_id')->where('created_at', '>=', $since)->select('conversation_id')
            ->union(DB::table('wa_conversations')->where('needs_attention', true)->where('updated_at', '>=', $since)->selectRaw('id as conversation_id'));

        return ['count' => (int) DB::query()->fromSub($union, 'h')->count(), 'estimated' => true];
    }

    /** الأدوات بالاسم وحالاتها («الحالة:الاسم» منذ E15) عبر json_each، بلا تحميل الصفوف */
    private static function toolCounts(Carbon $since): array
    {
        $rows = DB::table('wa_bot_usage as u')->crossJoin(DB::raw('json_each(u.tools_used) as j'))
            ->where('u.source', 'live')->where('u.model', '!=', 'rule')->where('u.created_at', '>=', $since)->whereNotNull('u.tools_used')
            ->groupBy('j.value')->selectRaw('j.value as t, COUNT(*) as n')->get();
        $names = [];
        $status = [];
        foreach ($rows as $r) {
            $t = (string) $r->t;
            if (str_contains($t, ':')) {
                [$st, $name] = explode(':', $t, 2);
                $status[$st] = ($status[$st] ?? 0) + (int) $r->n;
            } else {
                $name = $t;
            }
            $names[$name] = ($names[$name] ?? 0) + (int) $r->n;
        }
        arsort($names);

        return ['names' => array_map(fn ($n, $c) => ['name' => $n, 'count' => $c], array_keys($names), $names), 'status' => $status];
    }

    /** آخر 40 موضع عطل في الفترة مع سؤال العميل قبله (110 حرفاً)، ثم أحدث طلبات التسعير، كما في التبويب القديم */
    private static function attention(Carbon $since): array
    {
        $faults = DB::table('wa_messages as s')->where('s.direction', 'out')->where('s.source', 'system')->where('s.created_at', '>=', $since)
            ->orderByDesc('s.id')->limit(40)
            ->selectRaw("s.conversation_id, s.created_at, (SELECT q.body FROM wa_messages q WHERE q.conversation_id = s.conversation_id
                AND q.direction = 'in' AND q.id < s.id ORDER BY q.id DESC LIMIT 1) as question")->get();
        $out = [];
        foreach ($faults as $f) {
            $out[] = ['conversation_id' => (int) $f->conversation_id, 'type' => 'عطل', 'question' => mb_substr((string) ($f->question ?? '—'), 0, 110),
                'at' => Carbon::parse($f->created_at, 'UTC')->setTimezone(self::TZ)->format('m-d H:i')];
        }
        foreach (QuoteRequest::query()->where('created_at', '>=', $since)->orderByDesc('id')->limit(max(0, 40 - count($out)))->get(['conversation_id', 'details', 'created_at']) as $qr) {
            $out[] = ['conversation_id' => $qr->conversation_id, 'type' => 'تحويل', 'question' => mb_substr((string) $qr->details, 0, 110),
                'at' => $qr->created_at?->copy()->setTimezone(self::TZ)->format('m-d H:i')];
        }

        return $out;
    }

    /** الأثر التجاري أسبوعياً (8 أسابيع ثابتة كما اليوم) باستعلام مجمّع واحد */
    private static function weekly(): array
    {
        $start = Carbon::now(self::TZ)->startOfWeek()->subWeeks(7);
        $rows = QuoteRequest::query()->where('created_at', '>=', $start->copy()->utc())
            ->selectRaw('created_at, status')->toBase()->get();
        $weeks = [];
        for ($i = 0; $i < 8; $i++) {
            $weeks[$i] = ['label' => $start->copy()->addWeeks($i)->format('d/m'), 'requests' => 0, 'won' => 0];
        }
        foreach ($rows as $r) {
            $i = (int) floor($start->diffInDays(Carbon::parse($r->created_at, 'UTC')->setTimezone(self::TZ)) / 7);
            if (isset($weeks[$i])) {
                $weeks[$i]['requests']++;
                $weeks[$i]['won'] += $r->status === 'won' ? 1 : 0;
            }
        }

        return array_values($weeks);
    }

    // ==================================================================
    //  تكلفة ميتا التقديرية
    // ==================================================================

    /** رسائل الخدمة المحتسبة هذا الشهر مقابل المجانية، وفئات pricing، والتقدير بسعر المالك، وبجانبها كلفة النموذج */
    public static function metaCost(string $period): array
    {
        $period = self::period($period);
        $summary = OutboundCounter::summary();
        $categories = [];
        if (Schema::hasColumn('wa_messages', 'pricing_category')) {
            $categories = WaMessage::query()->where('direction', 'out')->where('created_at', '>=', OutboundCounter::monthStart())->whereNotNull('pricing_category')
                ->groupBy('pricing_category', 'pricing_type', 'pricing_billable')
                ->selectRaw('pricing_category as category, pricing_type as type, pricing_billable as billable, COUNT(*) as n')->toBase()->get()
                ->map(fn ($r) => ['category' => $r->category, 'type' => $r->type, 'billable' => (bool) $r->billable, 'count' => (int) $r->n])->all();
        }

        return $summary + [
            'period' => $period,
            'pricing' => $categories,
            'model_cost_usd' => self::usage($period)['tiles']['cost_30'],
            'meta' => self::metaAnalytics(),
            'note' => 'تقديري: بعدد الرسائل المحتسبة كما أبلغت ميتا (pricing.billable) وبسعر يدخله المالك. الفاتورة في Business Manager هي المرجع.',
        ];
    }

    /** المطابقة الاختيارية مع تحليلات ميتا (العلم meta_analytics_reconcile): آخر قراءة يومية مخزّنة، للمقارنة فقط */
    public static function metaAnalytics(): ?array
    {
        if (!FeatureFlags::on('meta_analytics_reconcile')) {
            return null;
        }

        return MetaAnalytics::last();
    }

    // ==================================================================

    /** @param list<int|float> $sorted */
    private static function median(array $sorted): int|float
    {
        $n = count($sorted);
        if ($n === 0) {
            return 0;
        }

        return $n % 2 ? $sorted[intdiv($n, 2)] : ($sorted[$n / 2 - 1] + $sorted[$n / 2]) / 2;
    }

    /** @param list<int|float> $sorted */
    private static function p90(array $sorted): int|float
    {
        $n = count($sorted);

        return $n ? $sorted[min($n - 1, (int) floor($n * 0.9))] : 0;
    }
}
