<?php

namespace App\Http\Controllers;

use App\Enums\AuditAction;
use App\Enums\AuditSeverity;
use App\Models\AuditLog;
use App\Models\User;
use App\Models\WaAdminAction;
use App\Models\WaAdminNumber;
use App\Support\AuditActor;
use App\Support\Csv;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * صفحة سجل التدقيق (E09): قراءة فقط، خلف العلم audit.page وصلاحية audit.view، والتصدير بصلاحية audit.export
 * ويُدقَّق قبل أن يبدأ. لا مسار هنا يعدّل صفاً أو يحذفه (والجدول للإلحاق فقط أصلاً).
 */
class AuditLogController extends Controller
{
    private const SUMMARY_CHARS = 300;

    public function index(Request $request)
    {
        $this->guard('audit.view');
        $filters = $this->filters($request);
        $admin = $request->query('tab') === 'admin';
        $names = $this->adminNames();

        return Inertia::render('AuditLogs/Index', [
            'tab' => $admin ? 'admin' : 'general',
            'filters' => $filters,
            'logs' => $admin ? null : $this->query($filters)
                ->orderByDesc('created_at')->orderByDesc('id')
                ->paginate(50)->withQueryString()
                ->through(fn (AuditLog $log) => $this->present($log, $names)),
            'adminActions' => $admin ? $this->adminActions($request) : null,
            'actions' => collect(AuditAction::cases())
                ->map(fn (AuditAction $a) => ['value' => $a->value, 'label' => $a->label(), 'category' => $a->category()])->values(),
            'entityTypes' => AuditLog::query()->distinct()->orderBy('entity_type')->pluck('entity_type'),
            'users' => User::query()->select('id', 'name')->orderBy('name')->get(),
            'adminNumbers' => WaAdminNumber::query()->select('id', 'name')->orderBy('name')->get(),
            'canExport' => $request->user()->can('audit.export'),
            'exportMax' => (int) config('audit.export_max_rows', 50000),
        ]);
    }

    public function show(AuditLog $auditLog)
    {
        $this->guard('audit.view');

        return response()->json($this->present($auditLog, $this->adminNames()) + ['changes' => $auditLog->changes]);
    }

    /** تاريخ كيان واحد (العرض، الفاتورة...): آخر 50 صفاً */
    public function entity(Request $request)
    {
        $this->guard('audit.view');
        $data = $request->validate(['type' => 'required|string|max:50', 'id' => 'required|integer|min:1']);
        $names = $this->adminNames();

        return response()->json(AuditLog::query()
            ->where('entity_type', $data['type'])->where('entity_id', $data['id'])
            ->orderByDesc('created_at')->orderByDesc('id')->limit(50)->get()
            ->map(fn (AuditLog $log) => $this->present($log, $names)));
    }

    public function export(Request $request)
    {
        $this->guard('audit.export');
        $filters = $this->filters($request);
        $max = (int) config('audit.export_max_rows', 50000);

        // من صدّر ماذا: يُكتب قبل البث، وفشله يمنع التصدير
        AuditLog::log(AuditAction::AuditExport, 'audit_log', null, null, ['filters' => array_filter($filters), 'max' => $max]);

        $query = $this->query($filters);
        $names = $this->adminNames();

        return response()->streamDownload(function () use ($query, $max, $names) {
            $h = fopen('php://output', 'w');
            Csv::bom($h);
            Csv::put($h, ['الوقت', 'الخطورة', 'الفعل', 'الفاعل', 'نوع الفاعل', 'الكيان', 'المعرّف', 'التسمية', 'التفاصيل', 'IP', 'الطريقة', 'المسار', 'معرّف الطلب']);

            // دفعات مستقلة بالمعرّف: لا قفل قراءة طويل على SQLite
            $n = 0;
            foreach ($query->lazyByIdDesc(500) as $log) {
                if ($n >= $max) {
                    Csv::put($h, ['⚠ اقتُطع التصدير عند '.number_format($max).' صف — ضيّق الفلاتر']);
                    break;
                }
                $row = $this->present($log, $names);
                Csv::put($h, [
                    $row['created_at'], $row['severity'], $row['action_label'], $row['actor_label'], $row['actor_type'],
                    $log->entity_type, $log->entity_id, $log->entity_label,
                    $log->changes === null ? '' : json_encode($log->changes, JSON_UNESCAPED_UNICODE),
                    $log->ip_address, $log->method, $log->path, $log->request_id,
                ]);
                $n++;
            }
            fclose($h);
        }, 'audit-log-'.now()->format('Y-m-d-His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function guard(string $permission): void
    {
        // العلم مطفأ: الصفحة غير موجودة بعد (لا 403 تملأ السجل برفض وصول)
        abort_unless(config('audit.page'), 404);
        abort_unless(auth()->user()->can($permission), 403);
    }

    /** @return array<string, mixed> */
    private function filters(Request $request): array
    {
        $data = $request->validate([
            'user_id' => 'nullable|integer',
            'action' => 'nullable|array',
            'action.*' => ['string', Rule::in(array_map(fn (AuditAction $a) => $a->value, AuditAction::cases()))],
            'entity_type' => 'nullable|string|max:50',
            'entity_id' => 'nullable|integer',
            'severity' => ['nullable', Rule::enum(AuditSeverity::class)],
            'actor_type' => ['nullable', Rule::in(AuditActor::TYPES)],
            'request_id' => 'nullable|string|max:64',
            'from' => 'nullable|date',
            'to' => 'nullable|date',
        ]);

        return array_merge(array_fill_keys(['user_id', 'entity_type', 'entity_id', 'severity', 'actor_type', 'request_id', 'from', 'to'], null), ['action' => []], $data);
    }

    private function query(array $f): Builder
    {
        return AuditLog::query()
            ->when($f['user_id'], fn ($q, $v) => $q->where('user_id', $v))
            ->when($f['action'], fn ($q, $v) => $q->whereIn('action', $v))
            ->when($f['entity_type'], fn ($q, $v) => $q->where('entity_type', $v))
            ->when($f['entity_id'], fn ($q, $v) => $q->where('entity_id', $v))
            ->when($f['severity'], fn ($q, $v) => $q->where('severity', $v))
            ->when($f['actor_type'], fn ($q, $v) => $q->where('actor_type', $v))
            ->when($f['request_id'], fn ($q, $v) => $q->where('request_id', $v))
            ->when($f['from'], fn ($q, $v) => $q->where('created_at', '>=', Carbon::parse($v)->startOfDay()))
            ->when($f['to'], fn ($q, $v) => $q->where('created_at', '<=', Carbon::parse($v)->endOfDay()));
    }

    private function adminActions(Request $request)
    {
        $data = $request->validate([
            'status' => 'nullable|string|max:20',
            'tool' => 'nullable|string|max:40',
            'admin_number_id' => 'nullable|integer',
        ]);

        return WaAdminAction::query()->with('adminNumber:id,name')
            ->when($data['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($data['tool'] ?? null, fn ($q, $v) => $q->where('tool', $v))
            ->when($data['admin_number_id'] ?? null, fn ($q, $v) => $q->where('admin_number_id', $v))
            ->orderByDesc('id')->paginate(50)->withQueryString()
            ->through(fn (WaAdminAction $a) => [
                'id' => $a->id,
                'created_at' => $a->created_at?->toIso8601String(),
                'admin' => $a->adminNumber?->name,
                'conversation_id' => $a->conversation_id,
                'tool' => $a->tool,
                'action' => $a->action,
                'status' => $a->status,
                'summary' => $a->summary,
                'input' => $a->input,
            ]);
    }

    /** @return array<int, string> */
    private function adminNames(): array
    {
        return WaAdminNumber::query()->pluck('name', 'id')->all();
    }

    /** @param  array<int, string>  $adminNames */
    private function present(AuditLog $log, array $adminNames): array
    {
        $action = $log->actionEnum();
        $summary = $log->changes === null ? null : json_encode($log->changes, JSON_UNESCAPED_UNICODE);

        return [
            'id' => $log->id,
            'created_at' => $log->created_at?->toIso8601String(),
            'severity' => $log->severity ?? 'info',
            'action' => $log->action,
            'action_label' => $action?->label() ?? $log->action,
            'category' => $action?->category(),
            'actor_type' => $log->actor_type ?? 'user',
            'actor_ref' => $log->actor_ref,
            'actor_label' => $this->actorLabel($log, $adminNames),
            'user_id' => $log->user_id,
            'entity_type' => $log->entity_type,
            'entity_id' => $log->entity_id,
            'entity_label' => $log->entity_label,
            'summary' => $summary === null ? null : mb_strimwidth($summary, 0, self::SUMMARY_CHARS, '…'),
            'ip_address' => $log->ip_address,
            'method' => $log->method,
            'path' => $log->path,
            'request_id' => $log->request_id,
        ];
    }

    /** @param  array<int, string>  $adminNames */
    private function actorLabel(AuditLog $log, array $adminNames): string
    {
        return match ($log->actor_type) {
            'bot' => '🤖 البوت',
            'admin_number' => '👔 رقم إدارة: '.($adminNames[(int) $log->actor_ref] ?? '#'.$log->actor_ref),
            'meta' => 'ميتا',
            'system' => 'النظام'.($log->actor_ref ? ' ('.$log->actor_ref.')' : ''),
            default => $log->user_name ? '👤 '.$log->user_name : '—',
        };
    }
}
