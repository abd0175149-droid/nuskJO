<?php

namespace App\Services\WhatsApp;

use App\Models\QuoteRequest;
use App\Models\WaBotSetting;
use App\Models\WaBotUsage;
use App\Models\WaConversation;
use App\Models\WaMessage;
use Illuminate\Support\Carbon;

/**
 * تحليلات البوت — الاستهلاك والكلفة + الجودة.
 *
 * قرارات حسابية مقصودة (لا تُغيَّر دون قصد):
 *  1) كل صفّ يُسعَّر بنموذجه هو → تبديل النموذج لا يُعيد تسعير التاريخ.
 *  2) توكنز الكاش تُفوتَر بنسبة cache_discount من سعر الإدخال (الافتراضي الربع).
 *  3) مفاتيح الأيام بتوقيت الأردن لا UTC → نشاط ما بعد التاسعة مساءً لا ينزلق لـ«الغد».
 *  4) المتوسّط اليومي يُقسم على الأيام النشطة لا على 30.
 *  5) «الدردشة الروتينية» وسيط لا متوسّط → محادثة شاذّة لا تُفسد التسعير.
 */
class BotAnalytics
{
    public const TZ = 'Asia/Amman';

    /** أسعار افتراضية بالدولار لكل مليون توكن — تُضبط من الإعدادات */
    public const DEFAULT_PRICES = [
        'gemini-2.5-flash'        => ['in' => 0.30, 'out' => 2.50],
        'gemini-2.5-flash-lite'   => ['in' => 0.10, 'out' => 0.40],
        'gemini-3-flash'          => ['in' => 0.30, 'out' => 2.50],
        'gemini-3.5-flash-lite'   => ['in' => 0.10, 'out' => 0.40],
        'claude-sonnet-5'         => ['in' => 3.00, 'out' => 15.00],
        'claude-haiku-4-5'        => ['in' => 1.00, 'out' => 5.00],
    ];

    private static function prices(): array
    {
        $s = WaBotSetting::current();
        return array_merge(self::DEFAULT_PRICES, (array) ($s->model_prices ?? []));
    }

    private static function cacheDiscount(): float
    {
        return (float) (WaBotSetting::current()->cache_discount ?? 0.25);
    }

    /** كلفة صفّ واحد بنموذجه هو */
    public static function rowCost(object $r, array $prices, float $disc): float
    {
        $p = $prices[$r->model] ?? ['in' => 0.0, 'out' => 0.0];
        $in  = ((int) $r->prompt_tokens / 1_000_000) * $p['in'];
        $out = ((int) $r->output_tokens / 1_000_000) * $p['out'];
        $cache = ((int) ($r->cache_read_tokens ?? 0) / 1_000_000) * $p['in'] * $disc;

        return $in + $out + $cache;
    }

    private static function dayKey($ts): string
    {
        return Carbon::parse($ts)->setTimezone(self::TZ)->format('Y-m-d');
    }

    // ==================================================================
    //  تبويب الاستهلاك والكلفة
    // ==================================================================
    public static function usage(): array
    {
        $prices = self::prices();
        $disc = self::cacheDiscount();

        $rows = WaBotUsage::where('source', 'live')->orderBy('created_at')->get();
        $today = Carbon::now(self::TZ)->format('Y-m-d');

        $byDay = [];           // يوم => [cost, in, out, cache, replies]
        $byConv = [];          // محادثة => كلفة
        $totIn = $totOut = $totCache = 0;
        $totCost = 0.0;

        foreach ($rows as $r) {
            $d = self::dayKey($r->created_at);
            $c = self::rowCost($r, $prices, $disc);

            $byDay[$d] ??= ['cost' => 0.0, 'in' => 0, 'out' => 0, 'cache' => 0, 'replies' => 0];
            $byDay[$d]['cost'] += $c;
            $byDay[$d]['in'] += (int) $r->prompt_tokens;
            $byDay[$d]['out'] += (int) $r->output_tokens;
            $byDay[$d]['cache'] += (int) ($r->cache_read_tokens ?? 0);
            $byDay[$d]['replies']++;

            if ($r->conversation_id) {
                $byConv[$r->conversation_id] = ($byConv[$r->conversation_id] ?? 0) + $c;
            }

            $totIn += (int) $r->prompt_tokens;
            $totOut += (int) $r->output_tokens;
            $totCache += (int) ($r->cache_read_tokens ?? 0);
            $totCost += $c;
        }

        // ما قُلّم خامه (أقدم من 12 شهراً، E13): يُضاف من التجميع الشهري، فلا تتغيّر الإجماليات بعد التقليم
        $rawMonths = $rows->map(fn ($r) => Carbon::parse($r->created_at)->format('Y-m'))->unique()->flip();
        $rolledUp = 0;
        if (\Illuminate\Support\Facades\Schema::hasTable('wa_bot_usage_monthly')) {
            foreach (\App\Models\WaBotUsageMonthly::where('source', 'live')->get() as $m) {
                if ($rawMonths->has($m->month)) {
                    continue;
                }
                $rolledUp++;
                $totIn += (int) $m->prompt_tokens;
                $totOut += (int) $m->output_tokens;
                $totCache += (int) $m->cache_read_tokens;
                $totCost += self::rowCost($m, $prices, $disc);
                $extraReplies = ($extraReplies ?? 0) + (int) $m->replies;
            }
        }

        // سلسلة 30 يوماً متّصلة (أيام بلا نشاط = صفر)
        $series = [];
        for ($i = 29; $i >= 0; $i--) {
            $d = Carbon::now(self::TZ)->subDays($i)->format('Y-m-d');
            $series[] = [
                'day' => $d,
                'label' => Carbon::parse($d)->format('d/m'),
                'cost' => round($byDay[$d]['cost'] ?? 0, 6),
                'in' => $byDay[$d]['in'] ?? 0,
                'out' => $byDay[$d]['out'] ?? 0,
                'cache' => $byDay[$d]['cache'] ?? 0,
                'replies' => $byDay[$d]['replies'] ?? 0,
            ];
        }

        // (4) المتوسّط اليومي على الأيام النشطة فقط
        $last30 = array_slice($series, -30);
        $activeDays = count(array_filter($last30, fn ($d) => $d['replies'] > 0));
        $cost30 = array_sum(array_column($last30, 'cost'));

        // (5) «الدردشة الروتينية» = وسيط كلفة المحادثة
        $convCosts = array_values($byConv);
        sort($convCosts);
        $n = count($convCosts);
        $median = $n ? ($n % 2 ? $convCosts[intdiv($n, 2)]
                               : ($convCosts[$n / 2 - 1] + $convCosts[$n / 2]) / 2) : 0.0;
        $meanConv = $n ? array_sum($convCosts) / $n : 0.0;

        // أغلى 5 محادثات
        arsort($byConv);
        $topIds = array_slice(array_keys($byConv), 0, 5);
        $names = WaConversation::whereIn('id', $topIds)->get()->keyBy('id');
        $top = [];
        foreach ($topIds as $cid) {
            $top[] = [
                'id' => $cid,
                'name' => $names[$cid]->display_name ?? ($names[$cid]->phone ?? ('#' . $cid)),
                'cost' => round($byConv[$cid], 6),
            ];
        }

        $replies = $rows->count() + ($extraReplies ?? 0);
        $playground = WaBotUsage::where('source', 'playground')->get()
            ->sum(fn ($r) => self::rowCost($r, $prices, $disc));

        return [
            'tiles' => [
                'today_cost' => round($byDay[$today]['cost'] ?? 0, 6),
                'cost_30' => round($cost30, 6),
                'cost_total' => round($totCost, 6),
                'replies' => $replies,
                'replies_30' => array_sum(array_column($last30, 'replies')),
                'tokens_in' => $totIn,
                'tokens_out' => $totOut,
                'tokens_cache' => $totCache,
                'daily_avg' => round($activeDays ? $cost30 / $activeDays : 0, 6),
                'active_days' => $activeDays,
                'avg_reply' => round($replies ? $totCost / $replies : 0, 6),
                'median_conv' => round($median, 6),      // الروتينية (وسيط)
                'mean_conv' => round($meanConv, 6),
                'playground_cost' => round($playground, 6),
                // أشهر قديمة مجمّعة بلا تفاصيل يومية ولا لكل محادثة
                'rolled_up_months' => $rolledUp,
            ],
            'series' => $series,
            'top_conversations' => $top,
            'prices' => $prices,
            'cache_discount' => $disc,
            'model' => WaBotSetting::current()->model,
        ];
    }

    // ==================================================================
    //  تبويب الجودة
    // ==================================================================
    public static function quality(): array
    {
        // دفتر الإرسال (E19.9): غير المسلَّم خارج الأتمتة وزمن الرد و«بلا جواب»، ومؤشران منفصلان له أدناه
        $ledger = \App\Support\FeatureFlags::on('send_ledger');
        $msgs = WaMessage::when($ledger, fn ($q) => $q->excludeUndelivered())
            ->orderBy('id')->get(['id', 'conversation_id', 'direction', 'source', 'msg_type', 'body', 'created_at']);
        $byConv = $msgs->groupBy('conversation_id');

        // ── قمع الحجز (يُقاس من الرسائل نفسها فيعمل على كل التاريخ)
        $intentWords = ['سعر', 'كم', 'حجز', 'احجز', 'عرض', 'عروض', 'تذكر', 'تذاكر', 'عمرة', 'عمره', 'فندق', 'تاشير', 'تأشير', 'رحل'];
        $talked = $intent = 0;
        $intentConvIds = [];

        foreach ($byConv as $cid => $list) {
            $ins = $list->where('direction', 'in');
            if ($ins->isEmpty()) continue;
            $talked++;
            $hit = $ins->first(function ($m) use ($intentWords) {
                $b = (string) $m->body;
                foreach ($intentWords as $w) if (mb_stripos($b, $w) !== false) return true;
                return false;
            });
            if ($hit) { $intent++; $intentConvIds[] = $cid; }
        }

        $decisionConvs = QuoteRequest::whereNotNull('conversation_id')->distinct()->pluck('conversation_id');
        $closed = QuoteRequest::where('status', 'won')->count();

        $funnel = [
            ['key' => 'talked',   'label' => 'تحدّث معنا',        'value' => $talked],
            ['key' => 'intent',   'label' => 'أبدى نيّة',          'value' => $intent],
            ['key' => 'decision', 'label' => 'وصل لنقطة الحسم',  'value' => $decisionConvs->count()],
            ['key' => 'closed',   'label' => 'حُسم (تمّ الحجز)',  'value' => $closed],
        ];

        // ── مؤشّرات الردود: ردود القواعد الحتمية (E37) آلية كالبوت، ولها مؤشرها المنفصل
        $ruleReplies = $msgs->where('source', 'rule')->count();
        $botReplies = $msgs->where('source', 'bot')->count() + $ruleReplies;
        $staffReplies = $msgs->where('source', 'staff')->count();
        $systemMsgs = $msgs->where('source', 'system')->count();
        $voice = $msgs->where('msg_type', 'audio')->count();

        // زمن الاستجابة: من رسالة العميل إلى أول ردّ بعدها
        $latencies = [];
        $unanswered = 0;
        foreach ($byConv as $list) {
            $arr = $list->values();
            for ($i = 0; $i < $arr->count(); $i++) {
                if ($arr[$i]->direction !== 'in') continue;
                $reply = null;
                for ($j = $i + 1; $j < $arr->count(); $j++) {
                    if ($arr[$j]->direction === 'out' && $arr[$j]->source !== 'system') { $reply = $arr[$j]; break; }
                    if ($arr[$j]->direction === 'in') break;   // رسالة أخرى قبل الردّ
                }
                if ($reply) {
                    $latencies[] = Carbon::parse($reply->created_at)->diffInSeconds(Carbon::parse($arr[$i]->created_at));
                } else {
                    // بلا جواب فقط إن لم تكن آخر رسالة في المحادثة الآن
                    if ($i < $arr->count() - 1) $unanswered++;
                }
            }
        }
        sort($latencies);
        $ln = count($latencies);
        $medianLat = $ln ? ($ln % 2 ? $latencies[intdiv($ln, 2)] : ($latencies[$ln / 2 - 1] + $latencies[$ln / 2]) / 2) : 0;
        $p90Lat = $ln ? $latencies[min($ln - 1, (int) floor($ln * 0.9))] : 0;

        // صفوف القواعد (model=rule) بلا نداء ولا أداة: خارج مؤشرات الأدوات
        $usage = WaBotUsage::where('source', 'live')->where('model', '!=', 'rule')->get();
        $withTools = $usage->filter(fn ($u) => !empty($u->tools_used))->count();
        $leaks = $usage->where('leaked', true)->count();

        $handoffs = WaConversation::where('needs_attention', true)->count()
            + QuoteRequest::count();   // كل طلب تسعير هو تحويل

        $totalReplies = $botReplies + $staffReplies;

        $metrics = [
            ['key' => 'automation', 'label' => 'نسبة الأتمتة',       'value' => $totalReplies ? round($botReplies * 100 / $totalReplies) : 0, 'unit' => '%', 'good' => 'high'],
            ['key' => 'rule_replies', 'label' => 'ردود القواعد',     'value' => $ruleReplies, 'unit' => '', 'good' => 'neutral'],
            ['key' => 'median',     'label' => 'وسيط زمن الردّ',     'value' => $medianLat, 'unit' => 'ث', 'good' => 'low'],
            ['key' => 'p90',        'label' => 'p90 لزمن الردّ',     'value' => $p90Lat, 'unit' => 'ث', 'good' => 'low'],
            ['key' => 'unanswered', 'label' => 'رسائل بلا جواب',     'value' => $unanswered, 'unit' => '', 'good' => 'low'],
            ['key' => 'handoff',    'label' => 'تحويلات لموظف',      'value' => $handoffs, 'unit' => '', 'good' => 'neutral'],
            ['key' => 'failures',   'label' => 'أعطال (فشل آمن)',    'value' => $systemMsgs, 'unit' => '', 'good' => 'low'],
            ['key' => 'leaks',      'label' => 'تسريب أدوات',        'value' => $leaks, 'unit' => '', 'good' => 'low'],
            ['key' => 'voice',      'label' => 'رسائل صوتية',        'value' => $voice, 'unit' => '', 'good' => 'neutral'],
            ['key' => 'tool_rate',  'label' => 'ردود استعملت أداة',  'value' => $usage->count() ? round($withTools * 100 / $usage->count()) : 0, 'unit' => '%', 'good' => 'high'],
            // قاطع المزوّد (E12): ما حُوِّل لموظف لأن مزوّد الذكاء تعطّل، وعدد الحوادث (المراقبة shadow معها)
            ['key' => 'llm_outage', 'label' => 'تحويلات بسبب تعطّل المزوّد', 'value' => \App\Models\WaBotUsage::where('source', 'outage')->count(), 'unit' => '', 'good' => 'low'],
            ['key' => 'llm_incidents', 'label' => 'حوادث المزوّد (30 يوماً)', 'value' => rescue(fn () => \App\Models\WaLlmIncident::where('opened_at', '>=', now()->subDays(30))->count(), 0, false), 'unit' => '', 'good' => 'low'],
            // سجل الأدوات (E15): أداة لم تُعلن أو أطفأها المالك، وأداة أعادت خطأ
            ['key' => 'tools_denied', 'label' => 'استدعاءات أدوات مرفوضة', 'value' => self::toolStatusCount($usage, 'denied'), 'unit' => '', 'good' => 'low'],
            ['key' => 'tools_failed', 'label' => 'أدوات فشلت', 'value' => self::toolStatusCount($usage, 'failed'), 'unit' => '', 'good' => 'low'],
            ...self::toolBytesMetrics($usage),
            ...($ledger ? [
                ['key' => 'send_unknown', 'label' => 'إرسال غير مؤكد', 'value' => WaMessage::where('direction', 'out')->where('status', WaMessage::STATUS_UNKNOWN)->count(), 'unit' => '', 'good' => 'low'],
                ['key' => 'send_failed', 'label' => 'فشل إرسال', 'value' => WaMessage::where('direction', 'out')->where('status', WaMessage::STATUS_FAILED)->count(), 'unit' => '', 'good' => 'low'],
            ] : []),
            // الترجمة عند الطلب (E29): نداءات المزوّد للموظفين، خارج كلفة ردود البوت
            ...(MessageTranslator::enabled() ? [
                ['key' => 'translations', 'label' => 'ترجمات للموظفين (30 يوماً)', 'value' => WaBotUsage::where('source', 'translate')->where('created_at', '>=', now()->subDays(30))->count(), 'unit' => '', 'good' => 'neutral'],
            ] : []),
        ];

        // ── ما يحتاج نظرك: آخر 40 موضعاً عجز فيه البوت أو حوّل أو تعطّل
        $attention = [];
        foreach ($msgs->where('source', 'system')->sortByDesc('id')->take(40) as $m) {
            $q = $msgs->where('conversation_id', $m->conversation_id)
                      ->where('direction', 'in')->where('id', '<', $m->id)->last();
            $attention[] = [
                'conversation_id' => $m->conversation_id,
                'type' => 'عطل',
                'question' => mb_substr((string) ($q->body ?? '—'), 0, 110),
                'at' => Carbon::parse($m->created_at)->setTimezone(self::TZ)->format('m-d H:i'),
            ];
        }
        foreach (QuoteRequest::with('conversation')->orderByDesc('id')->take(40 - count($attention))->get() as $qr) {
            $attention[] = [
                'conversation_id' => $qr->conversation_id,
                'type' => 'تحويل',
                'question' => mb_substr((string) $qr->details, 0, 110),
                'at' => Carbon::parse($qr->created_at)->setTimezone(self::TZ)->format('m-d H:i'),
            ];
        }

        // ── أكثر الأدوات استعمالاً (E15: «الحالة:الاسم»، والصفوف القديمة بالاسم وحده)
        $toolCounts = [];
        foreach ($usage as $u) {
            foreach ((array) $u->tools_used as $t) {
                $name = str_contains((string) $t, ':') ? explode(':', (string) $t, 2)[1] : (string) $t;
                $toolCounts[$name] = ($toolCounts[$name] ?? 0) + 1;
            }
        }
        arsort($toolCounts);
        $tools = [];
        foreach ($toolCounts as $name => $c) $tools[] = ['name' => $name, 'count' => $c];

        // ── الأثر التجاري أسبوعياً (طلبات ومحسومة)
        $weeks = [];
        for ($i = 7; $i >= 0; $i--) {
            $start = Carbon::now(self::TZ)->startOfWeek()->subWeeks($i);
            $end = (clone $start)->addWeek();
            $weeks[] = [
                'label' => $start->format('d/m'),
                'requests' => QuoteRequest::whereBetween('created_at', [$start, $end])->count(),
                'won' => QuoteRequest::where('status', 'won')->whereBetween('created_at', [$start, $end])->count(),
            ];
        }

        return [
            'funnel' => $funnel,
            'metrics' => $metrics,
            'attention' => array_slice($attention, 0, 40),
            'tools' => $tools,
            'weekly' => $weeks,
        ];
    }

    // ==================================================================
    //  مصادر المحادثات (E38): رمز QR أو رابط أو إعلان — أعداد فقط، بلا أسماء ولا أرقام
    // ==================================================================

    public const ENTRIES_NOTE = 'قياس داخلي تقريبي: لا تحليلات من ميتا للرموز، والعميل الذي حذف المرجع لا يُحسب.';

    /**
     * لكل قناة وعرض ورمز وإعلان: المحادثات المميزة، وطلبات التسعير التي أُنشئت للمحادثة نفسها بعد صف الدخول (لعرضه
     * أو بلا عرض)، ومنها المحسومة (won). تجميع SQL لا في الذاكرة.
     *
     * @return array{days: int, note: string, channels: array<string, int>, rows: list<array>}
     */
    public static function entries(int $days = 30): array
    {
        $days = in_array($days, [7, 30, 90], true) ? $days : 30;
        $empty = ['days' => $days, 'note' => self::ENTRIES_NOTE, 'channels' => [], 'rows' => []];
        if (!\Illuminate\Support\Facades\Schema::hasTable('wa_conversation_entries')) {
            return $empty;
        }
        $since = now()->subDays($days);
        $db = \Illuminate\Support\Facades\DB::class;

        $channels = $db::table('wa_conversation_entries')->where('created_at', '>=', $since)
            ->groupBy('channel')->selectRaw('channel, COUNT(DISTINCT conversation_id) as n')->pluck('n', 'channel')
            ->map(fn ($n) => (int) $n)->all();

        $rows = $db::table('wa_conversation_entries as e')
            ->leftJoin('quote_requests as q', function ($j) {
                $j->on('q.conversation_id', '=', 'e.conversation_id')->on('q.created_at', '>=', 'e.created_at')
                    ->where(fn ($w) => $w->whereColumn('q.offer_id', 'e.offer_id')->orWhereNull('q.offer_id')->orWhereNull('e.offer_id'));
            })
            ->leftJoin('offers as o', 'o.id', '=', 'e.offer_id')
            ->leftJoin('wa_qr_links as l', 'l.id', '=', 'e.qr_link_id')
            ->where('e.created_at', '>=', $since)
            ->groupBy('e.channel', 'e.offer_id', 'e.qr_link_id', 'e.referral_source_id')
            ->selectRaw('e.channel, e.offer_id, e.qr_link_id, e.referral_source_id, MAX(o.title) as offer_title, MAX(l.label) as qr_label,
                MAX(l.channel) as qr_channel, MAX(e.referral_headline) as headline, COUNT(DISTINCT e.conversation_id) as conversations,
                COUNT(DISTINCT q.id) as quotes, COUNT(DISTINCT CASE WHEN q.status = ? THEN q.id END) as won', ['won'])
            ->orderByDesc('conversations')->limit(200)->get();

        return ['days' => $days, 'note' => self::ENTRIES_NOTE, 'channels' => $channels, 'rows' => $rows->map(fn ($r) => [
            'channel' => $r->channel,
            'offer_id' => $r->offer_id !== null ? (int) $r->offer_id : null,
            'offer_title' => $r->offer_title,
            'qr_link_id' => $r->qr_link_id !== null ? (int) $r->qr_link_id : null,
            'qr_label' => $r->qr_label,
            'qr_channel' => $r->qr_channel,
            'source_id' => $r->referral_source_id,
            'headline' => $r->headline,
            'conversations' => (int) $r->conversations,
            'quotes' => (int) $r->quotes,
            'won' => (int) $r->won,
        ])->values()->all()];
    }

    private static function toolStatusCount($usage, string $status): int
    {
        return $usage->sum(fn ($u) => count(array_filter((array) $u->tools_used, fn ($t) => str_starts_with((string) $t, $status.':'))));
    }

    /** حجم نتائج الأدوات (E15.7): المتوسط وp90 بالكيلوبايت للردود التي استعملت أداة */
    private static function toolBytesMetrics($usage): array
    {
        if (!\Illuminate\Support\Facades\Schema::hasColumn('wa_bot_usage', 'tool_result_bytes')) {
            return [];
        }
        $bytes = $usage->pluck('tool_result_bytes')->map(fn ($b) => (int) $b)->filter()->sort()->values();
        $n = $bytes->count();

        return [
            ['key' => 'tool_bytes_avg', 'label' => 'متوسط حجم نتائج الأدوات', 'value' => $n ? round($bytes->avg() / 1024, 1) : 0, 'unit' => 'KB', 'good' => 'low'],
            ['key' => 'tool_bytes_p90', 'label' => 'p90 لحجم نتائج الأدوات', 'value' => $n ? round($bytes[min($n - 1, (int) floor($n * 0.9))] / 1024, 1) : 0, 'unit' => 'KB', 'good' => 'low'],
        ];
    }
}
