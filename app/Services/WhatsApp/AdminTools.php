<?php

namespace App\Services\WhatsApp;

use App\Models\Client;
use App\Models\Disbursement;
use App\Models\Invoice;
use App\Models\QuoteRequest;
use App\Models\Receipt;
use App\Models\User;
use App\Models\WaAdminAction;
use App\Models\WaAdminNumber;
use App\Models\WaBotSetting;
use App\Models\WaConversation;
use App\Models\WaKnowledgeEntry;
use App\Services\TravelersReport;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * أدوات المساعد الإداري — لأرقام الإدارة وحدها، وكلٌّ بما سُمح له.
 *
 * ثلاث قواعد تحكم كل أداة:
 *  ① الصلاحية تُفحص مرّتين: الأداة لا تُعرض للنموذج إن لم تُسمح، وتُرفض عند
 *     التنفيذ إن لم تُسمح — فلا يكفي أن يقتنع النموذج.
 *  ② كل تعديل على مرحلتين: الاستدعاء الأوّل يحفظ «إجراءً معلّقاً» ويعيد معاينة،
 *     والتنفيذ لا يتمّ إلا بعد أن يردّ الموظف بتأكيد في رسالة جديدة. وما يُنفَّذ
 *     هو المحفوظ عند المعاينة حرفياً، لا ما يرسله النموذج عند التأكيد.
 *  ③ كل استدعاء يُسجَّل في wa_admin_actions: نُفّذ، رُفض، عُرضت معاينته، أو فشل.
 */
class AdminTools
{
    /** الصلاحيات كما تظهر في لوحة التحكم — مجموعة، اسم، وصف، وهل تُعدّل */
    public const CATALOG = [
        'report.daily'     => ['group' => 'التقارير', 'label' => 'ملخّص اليوم', 'desc' => 'مبيعات اليوم والمقبوضات وما ينتظر الاعتماد ومسافرو اليوم والمحادثات.', 'write' => false],
        'report.sales'     => ['group' => 'التقارير', 'label' => 'تقرير المبيعات والأرباح', 'desc' => 'المبيعات المعتمدة والربح لفترة، وأعلى البائعين.', 'write' => false],
        'report.travelers' => ['group' => 'التقارير', 'label' => 'المسافرون بتاريخ', 'desc' => 'المسافرون في يوم محدّد مع المتبقي على كل فاتورة.', 'write' => false],
        'report.approvals' => ['group' => 'التقارير', 'label' => 'ما ينتظر الاعتماد', 'desc' => 'الفواتير وسندات القبض والصرف المعلّقة.', 'write' => false],
        'report.quotes'    => ['group' => 'التقارير', 'label' => 'طلبات التسعير المفتوحة', 'desc' => 'طلبات التسعير التي لم تُرسل بعد ومن تُسند إليه.', 'write' => false],
        'report.client'    => ['group' => 'التقارير', 'label' => 'بحث عن عميل ورصيده', 'desc' => 'بالاسم أو الهاتف أو الرمز: الرصيد وآخر الفواتير.', 'write' => false],
        'kb.view'          => ['group' => 'قاعدة المعرفة', 'label' => 'استعراض المعرفة', 'desc' => 'قائمة المعلومات، وعرض معلومة، والبحث فيها.', 'write' => false],
        'kb.add'           => ['group' => 'قاعدة المعرفة', 'label' => 'إضافة معلومة', 'desc' => 'يضيف معلومة يقرؤها بوت العملاء فوراً.', 'write' => true],
        'kb.update'        => ['group' => 'قاعدة المعرفة', 'label' => 'تعديل معلومة', 'desc' => 'يغيّر عنوان معلومة أو نصّها أو يوقفها مؤقّتاً.', 'write' => true],
        'kb.delete'        => ['group' => 'قاعدة المعرفة', 'label' => 'حذف معلومة', 'desc' => 'يحذف معلومة نهائياً (تبقى نسختها في سجلّ الإجراءات).', 'write' => true],
        'tools.view'       => ['group' => 'إدارة الأدوات', 'label' => 'عرض أدوات بوت العملاء', 'desc' => 'أيّ الأدوات مفعّلة لبوت العملاء الآن.', 'write' => false],
        'tools.toggle'     => ['group' => 'إدارة الأدوات', 'label' => 'تفعيل أو إيقاف أداة', 'desc' => 'يشغّل أو يوقف أداة لبوت العملاء (مثل إرسال البطاقات).', 'write' => true],
        'bot.toggle'       => ['group' => 'إدارة الأدوات', 'label' => 'تشغيل أو إيقاف بوت العملاء', 'desc' => 'يوقف ردود البوت على كل العملاء أو يعيدها.', 'write' => true],
    ];

    /** أسماء الأدوات التي يراها النموذج — لحارس تسريب الأسماء في النصّ */
    public const TOOL_NAMES = [
        'get_daily_summary', 'get_sales_report', 'get_travelers', 'get_pending_approvals',
        'get_open_quotes', 'find_client', 'manage_knowledge', 'manage_bot_tools', 'set_customer_bot',
    ];

    private const PENDING_TTL_MINUTES = 10;

    // ==================== الإعلانات ====================

    /** الأدوات المسموحة لهذا الرقم فقط — الباقي لا يعلم النموذج بوجوده */
    public static function declarations(WaAdminNumber $a): array
    {
        $t = [];

        if ($a->allows('report.daily')) {
            $t[] = self::decl('get_daily_summary', 'ملخّص اليوم: المبيعات المعتمدة اليوم، والمقبوضات، وما ينتظر الاعتماد، ومسافرو اليوم، ومحادثات واتساب وطلبات التسعير الجديدة.', []);
        }
        if ($a->allows('report.sales')) {
            $t[] = self::decl('get_sales_report', 'تقرير المبيعات المعتمدة لفترة: العدد والإجمالي والربح وأعلى البائعين. الافتراضي: الشهر الحالي.', [
                'from' => ['type' => 'string', 'description' => 'من تاريخ YYYY-MM-DD'],
                'to' => ['type' => 'string', 'description' => 'إلى تاريخ YYYY-MM-DD'],
            ]);
        }
        if ($a->allows('report.travelers')) {
            $t[] = self::decl('get_travelers', 'المسافرون في تاريخ محدّد مع المتبقي على كل فاتورة. الافتراضي: اليوم.', [
                'date' => ['type' => 'string', 'description' => 'التاريخ YYYY-MM-DD'],
            ]);
        }
        if ($a->allows('report.approvals')) {
            $t[] = self::decl('get_pending_approvals', 'الفواتير وسندات القبض وسندات الصرف التي تنتظر الاعتماد.', []);
        }
        if ($a->allows('report.quotes')) {
            $t[] = self::decl('get_open_quotes', 'طلبات التسعير المفتوحة (جديدة أو مسعّرة لم تُرسل) ومن تُسند إليه.', []);
        }
        if ($a->allows('report.client')) {
            $t[] = self::decl('find_client', 'ابحث عن عميل بالاسم أو الهاتف أو الرمز، واعرض رصيده وآخر فواتيره.', [
                'query' => ['type' => 'string', 'description' => 'اسم أو جزء منه، أو رقم هاتف، أو رمز مثل CLT-041'],
            ], ['query']);
        }

        $kbActions = self::kbActions($a);
        if ($kbActions) {
            $t[] = self::decl('manage_knowledge',
                'إدارة قاعدة معرفة بوت العملاء (ما يقرؤه البوت مع كل رسالة). '
                . 'list: قائمة المعلومات بأرقامها. view: نصّ معلومة برقمها. search: بحث بكلمة. '
                . 'add / update / delete: تعديلات تمرّ بمعاينة ثم تأكيد من الموظف (confirm=true بعد موافقته فقط).', [
                    'action' => ['type' => 'string', 'enum' => $kbActions],
                    'id' => ['type' => 'integer', 'description' => 'رقم المعلومة (view/update/delete)'],
                    'query' => ['type' => 'string', 'description' => 'كلمة البحث (search)'],
                    'title' => ['type' => 'string', 'description' => 'عنوان المعلومة (add/update)'],
                    'content' => ['type' => 'string', 'description' => 'نصّ المعلومة كاملاً (add/update)'],
                    'is_active' => ['type' => 'boolean', 'description' => 'إيقاف المعلومة مؤقّتاً أو إعادتها (update)'],
                    'confirm' => ['type' => 'boolean', 'description' => 'true فقط بعد أن يوافق الموظف على المعاينة'],
                ], ['action']);
        }

        $toolActions = array_merge(
            $a->allows('tools.view') || $a->allows('tools.toggle') ? ['list'] : [],
            $a->allows('tools.toggle') ? ['enable', 'disable'] : [],
        );
        if ($toolActions) {
            $t[] = self::decl('manage_bot_tools', 'عرض أدوات بوت العملاء وحالتها، أو تفعيل أداة أو إيقافها (بمعاينة ثم تأكيد).', [
                'action' => ['type' => 'string', 'enum' => $toolActions],
                'tool' => ['type' => 'string', 'description' => 'مفتاح الأداة كما يظهر في القائمة'],
                'confirm' => ['type' => 'boolean', 'description' => 'true فقط بعد موافقة الموظف'],
            ], ['action']);
        }

        if ($a->allows('bot.toggle')) {
            $t[] = self::decl('set_customer_bot', 'حالة بوت العملاء، أو تشغيله أو إيقافه لكل العملاء (بمعاينة ثم تأكيد).', [
                'action' => ['type' => 'string', 'enum' => ['status', 'on', 'off']],
                'confirm' => ['type' => 'boolean', 'description' => 'true فقط بعد موافقة الموظف'],
            ], ['action']);
        }

        return $t;
    }

    private static function kbActions(WaAdminNumber $a): array
    {
        if (!$a->allowsAny(['kb.view', 'kb.add', 'kb.update', 'kb.delete'])) {
            return [];
        }

        // التعديل يحتاج رؤية ما يُعدَّل — فأيّ صلاحية معرفة تتضمّن الاستعراض
        return array_merge(
            ['list', 'view', 'search'],
            $a->allows('kb.add') ? ['add'] : [],
            $a->allows('kb.update') ? ['update'] : [],
            $a->allows('kb.delete') ? ['delete'] : [],
        );
    }

    private static function decl(string $name, string $desc, array $props, array $required = []): array
    {
        $schema = ['type' => 'object', 'properties' => $props];
        if ($required) {
            $schema['required'] = $required;
        }

        return ['name' => $name, 'description' => $desc, 'input_schema' => $schema];
    }

    // ==================== التنفيذ ====================

    public static function execute(string $name, array $in, WaConversation $conv, WaAdminNumber $a): array
    {
        $action = (string) ($in['action'] ?? '');

        try {
            $needed = self::permissionFor($name, $action);
            if ($needed === null || !$a->allowsAny((array) $needed)) {
                self::log($a, $conv, $name, $action, 'denied', 'غير مسموح لهذا الرقم', $in);

                return ['error' => 'غير مسموح', 'note' => 'أخبر الموظف أنّ هذا غير مسموح لرقمه، وأنّ المدير يستطيع منحه الصلاحية من لوحة التحكم.'];
            }

            $out = match ($name) {
                'get_daily_summary' => self::dailySummary(),
                'get_sales_report' => self::salesReport($in),
                'get_travelers' => self::travelers($in),
                'get_pending_approvals' => self::pendingApprovals(),
                'get_open_quotes' => self::openQuotes(),
                'find_client' => self::findClient($in),
                'manage_knowledge' => self::knowledge($in, $conv, $a),
                'manage_bot_tools' => self::botTools($in, $conv, $a),
                'set_customer_bot' => self::customerBot($in, $conv, $a),
            };

            // التعديلات تُسجَّل بتفاصيلها داخل دوالّها؛ القراءات تُسجَّل هنا
            if (!self::isWrite($name, $action)) {
                self::log($a, $conv, $name, $action ?: null, isset($out['error']) ? 'error' : 'done', null, $in);
            }

            return $out;
        } catch (\Throwable $e) {
            Log::error("admin tool {$name} failed: " . $e->getMessage());
            self::log($a, $conv, $name, $action ?: null, 'error', mb_substr($e->getMessage(), 0, 300), $in);

            return ['error' => 'تعذّر تنفيذ الطلب', 'note' => 'اعتذر بإيجاز واقترح المحاولة من لوحة التحكم.'];
        }
    }

    /** الصلاحية المطلوبة لكل أداة/فعل — null = غير معروفة فتُرفض */
    private static function permissionFor(string $name, string $action): string|array|null
    {
        return match ($name) {
            'get_daily_summary' => 'report.daily',
            'get_sales_report' => 'report.sales',
            'get_travelers' => 'report.travelers',
            'get_pending_approvals' => 'report.approvals',
            'get_open_quotes' => 'report.quotes',
            'find_client' => 'report.client',
            'manage_knowledge' => match ($action) {
                'list', 'view', 'search' => ['kb.view', 'kb.add', 'kb.update', 'kb.delete'],
                'add' => 'kb.add',
                'update' => 'kb.update',
                'delete' => 'kb.delete',
                default => null,
            },
            'manage_bot_tools' => match ($action) {
                'list' => ['tools.view', 'tools.toggle'],
                'enable', 'disable' => 'tools.toggle',
                default => null,
            },
            'set_customer_bot' => in_array($action, ['status', 'on', 'off'], true) ? 'bot.toggle' : null,
            default => null,
        };
    }

    private static function isWrite(string $name, string $action): bool
    {
        return ($name === 'manage_knowledge' && in_array($action, ['add', 'update', 'delete'], true))
            || ($name === 'manage_bot_tools' && in_array($action, ['enable', 'disable'], true))
            || ($name === 'set_customer_bot' && in_array($action, ['on', 'off'], true));
    }

    // ==================== التقارير ====================

    private static function dailySummary(): array
    {
        $today = now()->toDateString();
        $approvedToday = Invoice::where('status', 'approved')->whereDate('approved_at', $today);
        $travelers = TravelersReport::rowsForDate($today);

        return [
            'date' => $today,
            'invoices_approved_today' => ['count' => (clone $approvedToday)->count(), 'total_jod' => round((float) (clone $approvedToday)->sum('total_sell_jod'), 3)],
            'invoices_created_today' => Invoice::whereDate('created_at', $today)->count(),
            'receipts_approved_today_jod' => round((float) Receipt::where('status', 'approved')->whereDate('approved_at', $today)->sum('amount_jod'), 3),
            'pending_approval' => [
                'invoices' => Invoice::where('status', 'pending')->count(),
                'receipts' => Receipt::where('status', 'pending')->count(),
                'disbursements' => Disbursement::where('status', 'pending')->count(),
            ],
            'travelers_today' => ['invoices' => count($travelers), 'pax' => array_sum(array_column($travelers, 'pax'))],
            'whatsapp' => [
                'conversations_today' => WaConversation::whereDate('last_inbound_at', $today)->count(),
                'needs_attention' => WaConversation::where('needs_attention', true)->count(),
                'new_quote_requests' => QuoteRequest::where('status', 'new')->count(),
            ],
            'note' => 'اعرض الملخّص بنقاط قصيرة. الأرقام كما هي — لا تحسب ولا تقرّب.',
        ];
    }

    private static function salesReport(array $in): array
    {
        $from = self::date($in['from'] ?? null) ?? now()->startOfMonth()->toDateString();
        $to = self::date($in['to'] ?? null) ?? now()->toDateString();

        $rows = Invoice::where('status', 'approved')
            ->whereBetween('invoice_date', [$from, $to])
            ->get(['id', 'total_sell_jod', 'profit_jod', 'sold_by']);

        $names = \App\Models\Employee::with('user:id,name')->whereIn('id', $rows->pluck('sold_by')->filter()->unique())
            ->get()->mapWithKeys(fn ($e) => [$e->id => $e->user?->name ?? ('موظف #' . $e->id)]);

        $bySeller = $rows->groupBy(fn ($r) => $r->sold_by ?: 0)
            ->map(fn ($g, $k) => [
                'seller' => $k ? ($names[$k] ?? 'موظف #' . $k) : 'غير محدّد',
                'invoices' => $g->count(),
                'sales_jod' => round((float) $g->sum('total_sell_jod'), 3),
                'profit_jod' => round((float) $g->sum('profit_jod'), 3),
            ])->sortByDesc('sales_jod')->take(5)->values()->all();

        return [
            'from' => $from, 'to' => $to,
            'invoices' => $rows->count(),
            'sales_jod' => round((float) $rows->sum('total_sell_jod'), 3),
            'profit_jod' => round((float) $rows->sum('profit_jod'), 3),
            'top_sellers' => $bySeller,
            'note' => 'اذكر الفترة والأرقام كما وردت.',
        ];
    }

    private static function travelers(array $in): array
    {
        $date = self::date($in['date'] ?? null) ?? now()->toDateString();
        $rows = TravelersReport::rowsForDate($date);

        return [
            'date' => $date,
            'count' => count($rows),
            'pax' => array_sum(array_column($rows, 'pax')),
            'travelers' => array_map(fn ($r) => [
                'client' => $r['client'], 'phone' => $r['phone'], 'invoice' => $r['invoice_number'],
                'pax' => $r['pax'], 'remaining_jod' => $r['remaining'],
            ], array_slice($rows, 0, 30)),
            'note' => count($rows) > 30 ? 'القائمة مقطوعة عند 30 — القائمة الكاملة في صفحة «المسافرون بتاريخ».' : 'اعرضهم بإيجاز.',
        ];
    }

    private static function pendingApprovals(): array
    {
        $inv = Invoice::with('client:id,name')->where('status', 'pending')->orderBy('id')->limit(8)->get();
        $rec = Receipt::with('client:id,name')->where('status', 'pending')->orderBy('id')->limit(8)->get();
        $dis = Disbursement::where('status', 'pending')->orderBy('id')->limit(8)->get();

        return [
            'invoices' => [
                'count' => Invoice::where('status', 'pending')->count(),
                'items' => $inv->map(fn ($i) => ['number' => $i->invoice_number, 'client' => $i->client?->name, 'total_jod' => round((float) $i->total_sell_jod, 3)])->all(),
            ],
            'receipts' => [
                'count' => Receipt::where('status', 'pending')->count(),
                'items' => $rec->map(fn ($r) => ['number' => $r->receipt_number, 'client' => $r->client?->name, 'amount_jod' => round((float) $r->amount_jod, 3)])->all(),
            ],
            'disbursements' => [
                'count' => Disbursement::where('status', 'pending')->count(),
                'items' => $dis->map(fn ($d) => ['number' => $d->disbursement_number, 'description' => $d->description, 'amount' => round((float) $d->amount, 3), 'currency' => $d->currency])->all(),
            ],
            'note' => 'الاعتماد نفسه من لوحة التحكم — المساعد لا يعتمد شيئاً.',
        ];
    }

    private static function openQuotes(): array
    {
        $rows = QuoteRequest::whereIn('status', ['new', 'priced'])->orderBy('id')->limit(15)->get();
        $users = User::whereIn('id', $rows->pluck('assigned_to')->filter()->unique())->pluck('name', 'id');

        return [
            'count' => QuoteRequest::whereIn('status', ['new', 'priced'])->count(),
            'requests' => $rows->map(fn ($q) => [
                'number' => $q->request_number,
                'customer' => $q->customer_name ?: $q->phone,
                'type' => $q->type,
                'status' => $q->status,
                'details' => mb_substr((string) $q->details, 0, 160),
                'assigned_to' => $q->assigned_to ? ($users[$q->assigned_to] ?? null) : 'غير مُسند',
                'since' => $q->created_at?->diffForHumans(),
            ])->all(),
        ];
    }

    private static function findClient(array $in): array
    {
        $q = trim((string) ($in['query'] ?? ''));
        if (mb_strlen($q) < 2) {
            return ['error' => 'كلمة البحث قصيرة', 'note' => 'اطلب اسماً أو رقماً أوضح.'];
        }
        $digits = preg_replace('/\D/', '', $q);

        $clients = Client::with('account:id,balance')
            ->where(function ($w) use ($q, $digits) {
                $w->where('name', 'like', "%{$q}%")->orWhere('code', 'like', "%{$q}%");
                if (strlen($digits) >= 6) {
                    $w->orWhere('phone', 'like', '%' . substr($digits, -9) . '%');
                }
            })
            ->limit(5)->get();

        return [
            'count' => $clients->count(),
            'clients' => $clients->map(fn ($c) => [
                'name' => $c->name, 'code' => $c->code, 'phone' => $c->phone,
                'balance_jod' => round((float) ($c->account?->balance ?? 0), 3),
                'last_invoices' => Invoice::where('client_id', $c->id)->where('status', 'approved')
                    ->orderByDesc('invoice_date')->limit(3)->get(['invoice_number', 'invoice_date', 'trip_date', 'total_sell_jod'])
                    ->map(fn ($i) => [
                        'number' => $i->invoice_number, 'date' => $i->invoice_date?->toDateString(),
                        'trip' => $i->trip_date?->toDateString(), 'total_jod' => round((float) $i->total_sell_jod, 3),
                    ])->all(),
            ])->all(),
            'note' => $clients->isEmpty() ? 'لا نتائج — اقترح صيغة أخرى للبحث.' : 'الرصيد الموجب = مبلغ على العميل.',
        ];
    }

    // ==================== قاعدة المعرفة ====================

    private static function knowledge(array $in, WaConversation $conv, WaAdminNumber $a): array
    {
        $action = (string) ($in['action'] ?? '');

        if (in_array($action, ['add', 'update', 'delete'], true) && !empty($in['confirm'])) {
            return self::commit('manage_knowledge', $conv, $a);
        }

        switch ($action) {
            case 'list':
                $all = WaKnowledgeEntry::orderBy('sort_order')->orderBy('id')->get();

                return [
                    'total_active_chars' => WaKnowledgeEntry::activeChars(),
                    'limit_chars' => WaKnowledgeEntry::MAX_TOTAL,
                    'entries' => $all->map(fn ($e) => [
                        'id' => $e->id, 'title' => $e->title, 'active' => $e->is_active, 'chars' => mb_strlen($e->content),
                    ])->all(),
                    'note' => 'اعرض القائمة بأرقامها وعناوينها فقط. للنصّ الكامل استدعِ view بالرقم.',
                ];

            case 'view':
                $e = WaKnowledgeEntry::find((int) ($in['id'] ?? 0));

                return $e
                    ? ['id' => $e->id, 'title' => $e->title, 'active' => $e->is_active, 'content' => $e->content,
                       'updated' => $e->updated_at?->diffForHumans(), 'note' => 'اعرض النصّ كما هو.']
                    : ['error' => 'لا توجد معلومة بهذا الرقم', 'note' => 'اعرض القائمة ليختار الرقم الصحيح.'];

            case 'search':
                $q = trim((string) ($in['query'] ?? ''));
                $hits = $q === '' ? collect() : WaKnowledgeEntry::where('title', 'like', "%{$q}%")
                    ->orWhere('content', 'like', "%{$q}%")->limit(10)->get();

                return [
                    'query' => $q,
                    'results' => $hits->map(fn ($e) => ['id' => $e->id, 'title' => $e->title, 'snippet' => self::snippet($e->content, $q)])->all(),
                ];

            case 'add':
                $title = trim((string) ($in['title'] ?? ''));
                $content = trim((string) ($in['content'] ?? ''));
                if ($err = self::validateEntry($title, $content, null)) {
                    return $err;
                }

                return self::stage($conv, $a, 'manage_knowledge', 'add',
                    ['title' => $title, 'content' => $content],
                    "إضافة معلومة جديدة لقاعدة المعرفة:\n«{$title}»\n{$content}");

            case 'update':
                $e = WaKnowledgeEntry::find((int) ($in['id'] ?? 0));
                if (!$e) {
                    return ['error' => 'لا توجد معلومة بهذا الرقم', 'note' => 'اعرض القائمة ليختار الرقم الصحيح.'];
                }
                $title = array_key_exists('title', $in) && trim((string) $in['title']) !== '' ? trim((string) $in['title']) : $e->title;
                $content = array_key_exists('content', $in) && trim((string) $in['content']) !== '' ? trim((string) $in['content']) : $e->content;
                $active = array_key_exists('is_active', $in) ? (bool) $in['is_active'] : $e->is_active;
                if ($title === $e->title && $content === $e->content && $active === $e->is_active) {
                    return ['error' => 'لا تغيير', 'note' => 'اسأله ماذا يريد أن يغيّر بالضبط.'];
                }
                if ($err = self::validateEntry($title, $content, $active ? $e->id : null, $active)) {
                    return $err;
                }

                $preview = "تعديل المعلومة #{$e->id} «{$e->title}»";
                if ($title !== $e->title) $preview .= "\nالعنوان الجديد: «{$title}»";
                if ($content !== $e->content) $preview .= "\nالنصّ الجديد:\n{$content}";
                if ($active !== $e->is_active) $preview .= $active ? "\nإعادة تفعيلها" : "\nإيقافها مؤقّتاً (لا يقرؤها البوت)";

                return self::stage($conv, $a, 'manage_knowledge', 'update',
                    ['id' => $e->id, 'title' => $title, 'content' => $content, 'is_active' => $active], $preview);

            case 'delete':
                $e = WaKnowledgeEntry::find((int) ($in['id'] ?? 0));
                if (!$e) {
                    return ['error' => 'لا توجد معلومة بهذا الرقم', 'note' => 'اعرض القائمة ليختار الرقم الصحيح.'];
                }

                return self::stage($conv, $a, 'manage_knowledge', 'delete', ['id' => $e->id],
                    "حذف المعلومة #{$e->id} «{$e->title}» نهائياً من قاعدة المعرفة.");
        }

        return ['error' => 'فعل غير معروف'];
    }

    /** الحدود: العنوان والنصّ، والحجم الكلّي للمعلومات الفعّالة */
    private static function validateEntry(string $title, string $content, ?int $exceptId, bool $active = true): ?array
    {
        if ($title === '' || $content === '') {
            return ['error' => 'العنوان والنصّ مطلوبان', 'note' => 'اطلب منه العنوان والنصّ.'];
        }
        if (mb_strlen($title) > WaKnowledgeEntry::MAX_TITLE || mb_strlen($content) > WaKnowledgeEntry::MAX_CONTENT) {
            return ['error' => 'المعلومة أطول من المسموح', 'note' => 'العنوان حتى ' . WaKnowledgeEntry::MAX_TITLE . ' حرفاً والنصّ حتى ' . WaKnowledgeEntry::MAX_CONTENT . '. اقترح تقسيمها.'];
        }
        if ($active && WaKnowledgeEntry::activeChars($exceptId) + mb_strlen($title) + mb_strlen($content) > WaKnowledgeEntry::MAX_TOTAL) {
            return ['error' => 'قاعدة المعرفة ممتلئة', 'note' => 'الحدّ الكلّي ' . WaKnowledgeEntry::MAX_TOTAL . ' حرف لأنها تُرسل مع كل رسالة. اقترح اختصار معلومات أخرى أو حذفها.'];
        }

        return null;
    }

    // ==================== إدارة أدوات بوت العملاء ====================

    private static function botTools(array $in, WaConversation $conv, WaAdminNumber $a): array
    {
        $action = (string) ($in['action'] ?? '');
        $s = WaBotSetting::current();

        if (in_array($action, ['enable', 'disable'], true) && !empty($in['confirm'])) {
            return self::commit('manage_bot_tools', $conv, $a);
        }

        if ($action === 'list') {
            return [
                'tools' => collect(BotTools::LABELS)->map(fn ($label, $key) => [
                    'key' => $key, 'label' => $label, 'enabled' => $s->toolEnabled($key),
                ])->values()->all(),
            ];
        }

        $key = (string) ($in['tool'] ?? '');
        if (!array_key_exists($key, BotTools::LABELS)) {
            return ['error' => 'أداة غير معروفة', 'note' => 'اعرض القائمة ليختار المفتاح الصحيح.'];
        }
        $enable = $action === 'enable';
        if ($s->toolEnabled($key) === $enable) {
            return ['error' => 'لا تغيير', 'note' => 'الأداة ' . ($enable ? 'مفعّلة' : 'موقوفة') . ' أصلاً.'];
        }

        return self::stage($conv, $a, 'manage_bot_tools', $action, ['tool' => $key, 'enabled' => $enable],
            ($enable ? 'تفعيل' : 'إيقاف') . ' أداة «' . BotTools::LABELS[$key] . "» لبوت العملاء.");
    }

    private static function customerBot(array $in, WaConversation $conv, WaAdminNumber $a): array
    {
        $action = (string) ($in['action'] ?? '');
        $s = WaBotSetting::current();

        if (in_array($action, ['on', 'off'], true) && !empty($in['confirm'])) {
            return self::commit('set_customer_bot', $conv, $a);
        }
        if ($action === 'status') {
            return ['enabled' => (bool) $s->enabled, 'sending_suspended' => (bool) $s->wa_suspended, 'model' => $s->model];
        }
        if ((bool) $s->enabled === ($action === 'on')) {
            return ['error' => 'لا تغيير', 'note' => 'البوت ' . ($s->enabled ? 'يعمل' : 'موقوف') . ' أصلاً.'];
        }

        return self::stage($conv, $a, 'set_customer_bot', $action, ['enabled' => $action === 'on'],
            $action === 'on' ? 'تشغيل بوت العملاء (يعود للردّ على كل العملاء).' : 'إيقاف بوت العملاء (لا يردّ على أي عميل حتى يُعاد تشغيله).');
    }

    // ==================== المعاينة والتأكيد ====================

    private static function pendingKey(WaConversation $conv): string
    {
        return "wa:admin:pending:{$conv->id}";
    }

    private static function lastInbound(WaConversation $conv)
    {
        return $conv->messages()->where('direction', 'in')->orderByDesc('id')->first();
    }

    /** يحفظ الإجراء المعلّق ويعيد معاينته — لا يُنفَّذ شيء هنا */
    private static function stage(WaConversation $conv, WaAdminNumber $a, string $tool, string $action, array $payload, string $preview): array
    {
        Cache::put(self::pendingKey($conv), [
            'tool' => $tool, 'action' => $action, 'payload' => $payload, 'preview' => $preview,
            'after' => (int) self::lastInbound($conv)?->id, 'admin' => $a->id,
        ], now()->addMinutes(self::PENDING_TTL_MINUTES));

        self::log($a, $conv, $tool, $action, 'preview', $preview, $payload);

        return [
            'needs_confirmation' => true,
            'preview' => $preview,
            'note' => 'لم يُنفَّذ شيء بعد. اعرض المعاينة على الموظف كما هي، واطلب منه الردّ بكلمة «تأكيد» للتنفيذ أو «إلغاء». '
                . 'لا تستدعِ الأداة مع confirm=true قبل أن يردّ بالموافقة في رسالة جديدة. المعاينة صالحة ' . self::PENDING_TTL_MINUTES . ' دقائق.',
        ];
    }

    /**
     * ينفّذ الإجراء المعلّق — بشرط أن يكون الموظف قد أكّد في رسالة جديدة بعد المعاينة.
     * ما يُنفَّذ هو المحفوظ عند المعاينة، لا ما يرسله النموذج الآن.
     */
    private static function commit(string $tool, WaConversation $conv, WaAdminNumber $a): array
    {
        $p = Cache::get(self::pendingKey($conv));
        if (!$p || $p['tool'] !== $tool || (int) $p['admin'] !== $a->id) {
            return ['error' => 'لا يوجد إجراء بانتظار التأكيد', 'note' => 'المعاينة انتهت أو لم تُطلب. ابدأ الطلب من جديد لتظهر معاينة.'];
        }

        // الصلاحية قد تُسحب بين المعاينة والتأكيد — تُفحص لما سيُنفَّذ فعلاً
        $needed = self::permissionFor($tool, (string) $p['action']);
        if ($needed === null || !$a->fresh()?->allowsAny((array) $needed)) {
            Cache::forget(self::pendingKey($conv));
            self::log($a, $conv, $tool, $p['action'], 'denied', 'سُحبت الصلاحية قبل التأكيد', $p['payload']);

            return ['error' => 'غير مسموح', 'note' => 'الصلاحية لم تعد متاحة لهذا الرقم، فلم يُنفَّذ شيء.'];
        }

        $last = self::lastInbound($conv);
        if (!$last || (int) $last->id <= (int) $p['after']) {
            return ['error' => 'لم يؤكّد الموظف بعد', 'note' => 'اعرض المعاينة وانتظر ردّه بكلمة «تأكيد». لا تنفّذ من تلقاء نفسك.'];
        }
        if (!self::isAffirmative((string) $last->body)) {
            Cache::forget(self::pendingKey($conv));
            self::log($a, $conv, $tool, $p['action'], 'denied', 'لم يكن ردّ الموظف تأكيداً — أُلغي الإجراء', $p['payload']);

            return ['cancelled' => true, 'note' => 'ردّ الموظف لم يكن تأكيداً صريحاً، فأُلغي الإجراء ولم يُنفَّذ شيء. أخبره بذلك.'];
        }

        Cache::forget(self::pendingKey($conv));
        $result = self::apply($p, $a);
        self::log($a, $conv, $tool, $p['action'], 'done', $result['summary'], $result['input'] ?? $p['payload']);

        return ['done' => true, 'result' => $result['summary'], 'note' => 'أبلغ الموظف بالنتيجة في جملة قصيرة.'];
    }

    private static function apply(array $p, WaAdminNumber $a): array
    {
        $d = $p['payload'];

        switch ($p['tool'] . ':' . $p['action']) {
            case 'manage_knowledge:add':
                $e = WaKnowledgeEntry::create([
                    'title' => $d['title'], 'content' => $d['content'], 'is_active' => true,
                    'sort_order' => (int) WaKnowledgeEntry::max('sort_order') + 1,
                    'source' => 'whatsapp', 'updated_by_admin_id' => $a->id, 'updated_by_user_id' => $a->user_id,
                ]);

                return ['summary' => "أُضيفت المعلومة #{$e->id} «{$e->title}» — يقرؤها بوت العملاء من الرسالة التالية."];

            case 'manage_knowledge:update':
                $e = WaKnowledgeEntry::findOrFail($d['id']);
                $before = $e->only(['title', 'content', 'is_active']);
                $e->update([
                    'title' => $d['title'], 'content' => $d['content'], 'is_active' => $d['is_active'],
                    'source' => 'whatsapp', 'updated_by_admin_id' => $a->id, 'updated_by_user_id' => $a->user_id,
                ]);

                return ['summary' => "عُدّلت المعلومة #{$e->id} «{$e->title}».", 'input' => ['before' => $before, 'after' => $d]];

            case 'manage_knowledge:delete':
                $e = WaKnowledgeEntry::findOrFail($d['id']);
                $copy = $e->only(['id', 'title', 'content', 'is_active', 'sort_order']);
                $e->delete();

                // النسخة الكاملة في السجلّ — تُستعاد منه عند الحاجة
                return ['summary' => "حُذفت المعلومة #{$copy['id']} «{$copy['title']}».", 'input' => ['deleted' => $copy]];

            case 'manage_bot_tools:enable':
            case 'manage_bot_tools:disable':
                $s = WaBotSetting::current();
                $cfg = $s->tools_config ?? [];
                $cfg[$d['tool']] = (bool) $d['enabled'];
                $s->update(['tools_config' => $cfg]);

                return ['summary' => ($d['enabled'] ? 'فُعّلت' : 'أُوقفت') . ' أداة «' . BotTools::LABELS[$d['tool']] . '» لبوت العملاء.'];

            case 'set_customer_bot:on':
            case 'set_customer_bot:off':
                WaBotSetting::current()->update(['enabled' => (bool) $d['enabled']]);

                return ['summary' => $d['enabled'] ? 'شُغّل بوت العملاء.' : 'أُوقف بوت العملاء — لن يردّ على أي عميل حتى يُعاد تشغيله.'];
        }

        throw new \RuntimeException('إجراء معلّق غير معروف');
    }

    /** موافقة صريحة فقط — الأولى في الرسالة */
    public static function isAffirmative(string $text): bool
    {
        $t = HandoffGuard::normalize($text);

        return (bool) preg_match('/^(نعم|ايوه|ايوا|اه|اكيد|تاكيد|اكد|اكدت|موافق|تمام|اوكي|اوك|نفذ|ماشي|يلا|بنفذ|ok|okay|yes|yep|confirm|confirmed|sure)(\s|$)/u', $t);
    }

    // ==================== أدوات مساعدة ====================

    private static function log(WaAdminNumber $a, WaConversation $conv, string $tool, ?string $action, string $status, ?string $summary, array $input = []): void
    {
        try {
            WaAdminAction::create([
                'admin_number_id' => $a->id,
                'conversation_id' => $conv->id,
                'tool' => $tool,
                'action' => $action ?: null,
                'status' => $status,
                'summary' => $summary ? mb_substr($summary, 0, 2000) : null,
                'input' => $input ?: null,
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('admin action log failed: ' . $e->getMessage());
        }
    }

    private static function snippet(string $content, string $q): string
    {
        $pos = $q !== '' ? mb_stripos($content, $q) : false;
        $start = $pos === false ? 0 : max(0, $pos - 40);

        return ($start > 0 ? '…' : '') . mb_substr($content, $start, 160) . (mb_strlen($content) > $start + 160 ? '…' : '');
    }

    private static function date(?string $d): ?string
    {
        if (!$d) return null;
        try {
            return \Illuminate\Support\Carbon::parse($d)->toDateString();
        } catch (\Throwable $e) {
            return null;
        }
    }
}
