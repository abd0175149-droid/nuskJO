<?php

namespace App\Http\Controllers;

use App\Enums\AuditAction;
use App\Jobs\DeliverIntegrationWebhook;
use App\Models\AuditLog;
use App\Models\IntegrationEndpoint;
use App\Models\IntegrationOutboxRow;
use App\Models\PersonalAccessToken;
use App\Models\User;
use App\Services\Integrations\CloudflareAccess;
use App\Services\Integrations\GuardRejection;
use App\Services\Integrations\IntegrationEvents;
use App\Services\Integrations\IntegrationOutbox;
use App\Services\Integrations\OutboundUrlGuard;
use App\Services\Integrations\WebhookSigner;
use App\Support\FeatureFlags;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * صفحة «🔌 التكاملات» (E40.7، ومعها C070 وC139): الوجهات وسرها الذي يُعرض مرة، والتسليمات وإعادتها، ورموز MCP.
 * كل دالة تفحص صلاحيتها هنا (لا بإخفاء الواجهة)، والوجهات خلف العلم integration_outbox والرموز خلف mcp_server (404).
 * الإنشاء والتعديل والحذف والتدوير وإصدار رمز MCP خلف التحقق المعزّز (config/security.php). الأسرار والرموز تعود
 * في رد JSON واحد بلا تخزين (no-store)، فلا تمر بالجلسة ولا props الصفحة ولا سجل التدقيق.
 */
class IntegrationController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $manage = $user->can('integrations.manage');
        $mcp = $user->can('mcp.use');
        abort_unless($manage || $mcp, 403);
        $outboxOn = FeatureFlags::on('integration_outbox');
        $mcpOn = FeatureFlags::on('mcp_server');
        abort_unless(($manage && $outboxOn) || ($mcp && $mcpOn), 404);

        return Inertia::render('Settings/Integrations', [
            'flags' => ['outbox' => $outboxOn, 'mcp' => $mcpOn],
            'can' => ['manage' => $manage && $outboxOn, 'mcp' => $mcp && $mcpOn],
            'endpoints' => $manage && $outboxOn ? $this->endpoints() : [],
            'catalog' => IntegrationEvents::catalog(),
            'counts' => $manage && $outboxOn ? $this->counts() : null,
            // استثناء داخلي من البيئة: تحذير دائم في الصفحة ما دام مضبوطاً
            'internalAllow' => $manage ? (config('integrations.internal_allow') ?: null) : null,
            'mcp' => $mcp && $mcpOn ? [
                'tokens' => $this->tokens($user),
                'max_days' => (int) config('integrations.mcp.token_max_days', 30),
                'url' => url('/mcp/owner'),
                'cloudflare' => CloudflareAccess::configured(),
            ] : null,
        ]);
    }

    public function store(Request $request, OutboundUrlGuard $guard): JsonResponse
    {
        $this->authorizeOutbox($request);
        $data = $this->validated($request, $guard);
        $secret = WebhookSigner::generateSecret();

        $endpoint = DB::transaction(function () use ($data, $secret, $request) {
            $endpoint = new IntegrationEndpoint;
            $endpoint->forceFill($data + ['secret' => $secret, 'is_active' => false, 'created_by' => $request->user()->id])->save();
            AuditLog::log(AuditAction::IntegrationEndpointCreate, 'integration_endpoint', $endpoint->id, $endpoint->name, [
                'host' => $endpoint->host(), 'events' => $endpoint->events, 'include_amounts' => $endpoint->include_amounts,
            ]);

            return $endpoint;
        });

        return response()->json(['endpoint' => $this->present($endpoint->fresh()), 'secret' => $secret], 201)->header('Cache-Control', 'no-store');
    }

    /** لا يغيّر السر. تغيير الرابط يوقف الوجهة حتى ping ناجح على الرابط الجديد */
    public function update(Request $request, IntegrationEndpoint $endpoint, OutboundUrlGuard $guard): JsonResponse
    {
        $this->authorizeOutbox($request);
        $data = $this->validated($request, $guard, requireUrl: false);
        $before = ['host' => $endpoint->host(), 'url' => $endpoint->url];

        DB::transaction(function () use ($endpoint, $data, $before) {
            $endpoint->forceFill($data);
            $changed = array_keys($endpoint->getDirty());
            if ($endpoint->url !== $before['url']) {
                $endpoint->forceFill(['is_active' => false, 'last_ping_ok_at' => null, 'paused_reason' => 'الرابط تغيّر: أرسل ping ثم شغّلها']);
            }
            $endpoint->save();
            AuditLog::log(AuditAction::IntegrationEndpointUpdate, 'integration_endpoint', $endpoint->id, $endpoint->name, [
                'fields' => $changed, 'host_from' => $before['host'], 'host_to' => $endpoint->host(), 'events' => $endpoint->events,
            ]);
        });

        return response()->json(['endpoint' => $this->present($endpoint->fresh())]);
    }

    /** المعلّق يمنع الحذف إلا بخيار «إسقاط المعلّق» صراحة */
    public function destroy(Request $request, IntegrationEndpoint $endpoint): JsonResponse
    {
        $this->authorizeOutbox($request);
        $drop = $request->boolean('drop_pending');
        $pending = $endpoint->deliveries()->whereIn('status', [IntegrationOutboxRow::PENDING, IntegrationOutboxRow::DELIVERING])->count();
        if ($pending > 0 && !$drop) {
            return response()->json(['code' => 'HAS_PENDING', 'pending' => $pending, 'message' => "للوجهة {$pending} تسليماً معلّقاً: اختر «إسقاط المعلّق» للحذف"], 409);
        }

        DB::transaction(function () use ($endpoint, $pending) {
            $rows = $endpoint->deliveries()->delete();
            AuditLog::log(AuditAction::IntegrationEndpointDelete, 'integration_endpoint', $endpoint->id, $endpoint->name, [
                'host' => $endpoint->host(), 'dropped_pending' => $pending, 'rows' => $rows,
            ]);
            $endpoint->delete();
        });

        return response()->json(['ok' => true]);
    }

    /** السر الحالي يبقى صالحاً للتوقيع 24 ساعة مع الجديد، فلا انقطاع عند المستقبل */
    public function rotate(Request $request, IntegrationEndpoint $endpoint): JsonResponse
    {
        $this->authorizeOutbox($request);
        $secret = WebhookSigner::generateSecret();
        $until = now()->addHours((int) config('integrations.rotation_hours', 24));

        DB::transaction(function () use ($endpoint, $secret, $until) {
            $current = rescue(fn () => $endpoint->secret, null, false);
            $endpoint->forceFill([
                'previous_secret' => $current, 'previous_secret_expires_at' => $current ? $until : null,
                'secret' => $secret, 'secret_rotated_at' => now(),
            ])->save();
            AuditLog::log(AuditAction::IntegrationEndpointRotate, 'integration_endpoint', $endpoint->id, $endpoint->name, [
                'host' => $endpoint->host(), 'previous_valid_until' => $current ? $until->toIso8601String() : null,
            ]);
        });

        return response()->json(['endpoint' => $this->present($endpoint->fresh()), 'secret' => $secret])->header('Cache-Control', 'no-store');
    }

    /** حدث ping يُرسل الآن متزامناً (محاولة واحدة) ويعيد رمز الحالة. نجاحه شرط تشغيل الوجهة */
    public function ping(Request $request, IntegrationEndpoint $endpoint, OutboundUrlGuard $guard): JsonResponse
    {
        $this->authorizeOutbox($request);
        $target = $guard->check((string) $endpoint->url);
        if ($target instanceof GuardRejection) {
            return response()->json(['ok' => false, 'status' => null, 'error' => $target->message()], 422);
        }

        $ids = IntegrationOutbox::record('ping', 'ping_'.$endpoint->id.'_'.Str::lower((string) Str::ulid()), [
            'message' => 'اختبار اتصال من نسك', 'sent_at' => now()->format(DATE_ATOM),
        ], $endpoint, dispatch: false);
        abort_if($ids === [], 409, 'تعذّر تسجيل ping');
        app()->call([new DeliverIntegrationWebhook($ids[0]), 'handle']);
        $row = IntegrationOutboxRow::query()->findOrFail($ids[0]);

        return response()->json([
            'ok' => $row->status === IntegrationOutboxRow::DELIVERED,
            'status' => $row->last_status_code,
            'error' => $row->last_error,
            'endpoint' => $this->present($endpoint->fresh()),
        ]);
    }

    /** التشغيل يتطلب ping ناجحاً؛ والمعلّق يُعاد جدولته بالمُصالِح خلال 5 دقائق. الإيقاف لا يُعاق */
    public function toggle(Request $request, IntegrationEndpoint $endpoint): JsonResponse
    {
        $this->authorizeOutbox($request);
        $active = (bool) $request->validate(['active' => 'required|boolean'])['active'];
        if ($active && !$endpoint->last_ping_ok_at) {
            throw ValidationException::withMessages(['active' => 'أرسل ping ناجحاً قبل التشغيل']);
        }

        DB::transaction(function () use ($endpoint, $active) {
            $endpoint->forceFill($active
                ? ['is_active' => true, 'paused_reason' => null, 'consecutive_failures' => 0]
                : ['is_active' => false, 'paused_reason' => 'أوقفها مدير'])->save();
            if ($active) {
                $endpoint->deliveries()->where('status', IntegrationOutboxRow::PENDING)->update(['next_attempt_at' => now(), 'dispatched_at' => null]);
            }
            AuditLog::log(AuditAction::IntegrationEndpointToggle, 'integration_endpoint', $endpoint->id, $endpoint->name, ['active' => $active, 'host' => $endpoint->host()]);
        });

        return response()->json(['endpoint' => $this->present($endpoint->fresh())]);
    }

    /** سجل التسليمات: الحمولة البيضاء نفسها التي خرجت، بلا ترويسات ولا روابط */
    public function deliveries(Request $request): JsonResponse
    {
        $this->authorizeOutbox($request);
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(IntegrationOutboxRow::STATUSES)],
            'endpoint' => 'nullable|integer',
            'event' => ['nullable', Rule::in(IntegrationEvents::ALLOWED)],
        ]);

        $page = IntegrationOutboxRow::query()->with('endpoint:id,name')
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($filters['endpoint'] ?? null, fn ($q, $e) => $q->where('endpoint_id', $e))
            ->when($filters['event'] ?? null, fn ($q, $e) => $q->where('event', $e))
            ->orderByDesc('id')
            ->paginate(25);

        return response()->json([
            'data' => $page->getCollection()->map(fn (IntegrationOutboxRow $r) => [
                'id' => $r->id,
                'endpoint' => $r->endpoint?->name,
                'event' => $r->event,
                'key' => $r->idempotency_key,
                'status' => $r->status,
                'attempts' => $r->attempts,
                'last_status_code' => $r->last_status_code,
                'last_error' => $r->last_error,
                'next_attempt_at' => $r->next_attempt_at?->toIso8601String(),
                'delivered_at' => $r->delivered_at?->toIso8601String(),
                'created_at' => $r->created_at?->toIso8601String(),
                'payload' => $r->payload,
            ])->values(),
            'meta' => ['page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    /** الفاشل أو المعلّق يعود معلّقاً بعدّاد صفر ويُرسل الآن. المُسلَّم لا يُعاد */
    public function redrive(Request $request, IntegrationOutboxRow $row): JsonResponse
    {
        $this->authorizeOutbox($request);
        if (!in_array($row->status, [IntegrationOutboxRow::DEAD, IntegrationOutboxRow::PENDING], true)) {
            return response()->json(['message' => 'لا يُعاد إلا الفاشل أو المعلّق'], 422);
        }
        if (!$row->endpoint?->is_active) {
            return response()->json(['message' => 'الوجهة موقوفة: شغّلها أولاً بعد ping ناجح'], 422);
        }

        DB::transaction(function () use ($row) {
            $from = $row->status;
            $row->forceFill(['status' => IntegrationOutboxRow::PENDING, 'attempts' => 0, 'next_attempt_at' => now(), 'dispatched_at' => now(), 'last_error' => null])->save();
            AuditLog::log(AuditAction::IntegrationDeliveryRedrive, 'integration_delivery', $row->id, $row->event, ['from' => $from, 'endpoint' => $row->endpoint_id]);
            DeliverIntegrationWebhook::dispatchFor($row->id);
        });

        return response()->json(['ok' => true, 'status' => IntegrationOutboxRow::PENDING]);
    }

    // ==================== رموز MCP ====================

    /** القدرة mcp:read وحدها، و30 يوماً حداً أقصى. النص الصريح مرة واحدة في هذا الرد */
    public function createToken(Request $request): JsonResponse
    {
        $user = $this->authorizeMcp($request);
        $data = $request->validate([
            'name' => 'required|string|max:60',
            'days' => 'required|integer|min:1|max:'.(int) config('integrations.mcp.token_max_days', 30),
        ]);

        $created = DB::transaction(function () use ($user, $data) {
            $created = $user->createToken(trim($data['name']), ['mcp:read'], now()->addDays((int) $data['days']));
            AuditLog::log(AuditAction::McpTokenCreate, 'mcp_access', $created['accessToken']->id, mb_substr(trim($data['name']), 0, 60), ['days' => (int) $data['days']]);

            return $created;
        });

        return response()->json([
            'token' => $created['plainTextToken'],
            'item' => $this->presentToken($created['accessToken']->fresh(), $user),
        ], 201)->header('Cache-Control', 'no-store');
    }

    /** إبطال رمز: صاحبه، أو المدير لأي رمز. حماية لا تُعاق، فبلا تحقق معزّز */
    public function revokeToken(Request $request, PersonalAccessToken $mcpToken): JsonResponse
    {
        $user = $this->authorizeMcp($request);
        $own = $mcpToken->tokenable_type === User::class && (int) $mcpToken->tokenable_id === $user->id;
        abort_unless($own || $user->can('admin'), 404);

        DB::transaction(function () use ($mcpToken) {
            AuditLog::log(AuditAction::McpTokenRevoke, 'mcp_access', $mcpToken->id, mb_substr((string) $mcpToken->name, 0, 60), ['owner' => (int) $mcpToken->tokenable_id]);
            $mcpToken->delete();
        });

        return response()->json(['ok' => true]);
    }

    // ==================== مساعدات ====================

    private function authorizeOutbox(Request $request): void
    {
        abort_unless($request->user()->can('integrations.manage'), 403);
        abort_unless(FeatureFlags::on('integration_outbox'), 404);
    }

    private function authorizeMcp(Request $request): User
    {
        abort_unless($request->user()->can('mcp.use'), 403);
        abort_unless(FeatureFlags::on('mcp_server'), 404);

        return $request->user();
    }

    /**
     * الرابط لا يعود للواجهة بعد الحفظ (قد يحمل معاملات سرية)، فالتعديل بلا رابط يبقي القديم.
     *
     * @return array{name: string, url?: string, events: list<string>, include_amounts: bool}
     */
    private function validated(Request $request, OutboundUrlGuard $guard, bool $requireUrl = true): array
    {
        $data = $request->validate([
            'name' => 'required|string|max:80',
            'url' => ($requireUrl ? 'required' : 'nullable').'|string|max:'.OutboundUrlGuard::MAX_LENGTH,
            'events' => 'required|array|min:1',
            'events.*' => ['string', Rule::in(IntegrationEvents::SUBSCRIBABLE)],
            'include_amounts' => 'boolean',
        ]);
        $out = [
            'name' => trim($data['name']),
            'events' => array_values(array_unique($data['events'])),
            'include_amounts' => (bool) ($data['include_amounts'] ?? false),
        ];
        if (trim((string) ($data['url'] ?? '')) !== '') {
            $check = $guard->check($data['url']);
            if ($check instanceof GuardRejection) {
                throw ValidationException::withMessages(['url' => $check->message()]);
            }
            $out['url'] = trim($data['url']);
        }

        return $out;
    }

    private function endpoints(): array
    {
        $counts = IntegrationOutboxRow::query()->selectRaw('endpoint_id, status, count(*) as n')->groupBy('endpoint_id', 'status')->get()
            ->groupBy('endpoint_id')->map(fn ($g) => $g->pluck('n', 'status')->map(fn ($n) => (int) $n));

        return IntegrationEndpoint::query()->orderBy('id')->get()
            ->map(fn (IntegrationEndpoint $e) => $this->present($e, ($counts[$e->id] ?? collect())->all()))->all();
    }

    /** بلا السر ولا الرابط الكامل: المضيف وحده (الرابط قد يحمل معاملات سرية) */
    private function present(IntegrationEndpoint $e, ?array $counts = null): array
    {
        $counts ??= $e->deliveries()->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status')->map(fn ($n) => (int) $n)->all();

        return [
            'id' => $e->id,
            'name' => $e->name,
            'host' => $e->host(),
            'events' => array_values((array) $e->events),
            'include_amounts' => (bool) $e->include_amounts,
            'is_active' => (bool) $e->is_active,
            'paused_reason' => $e->paused_reason,
            'consecutive_failures' => (int) $e->consecutive_failures,
            'last_success_at' => $e->last_success_at?->toIso8601String(),
            'last_failure_at' => $e->last_failure_at?->toIso8601String(),
            'last_ping_ok_at' => $e->last_ping_ok_at?->toIso8601String(),
            'secret_rotated_at' => $e->secret_rotated_at?->toIso8601String(),
            'previous_secret_until' => $e->previous_secret_expires_at?->isFuture() ? $e->previous_secret_expires_at->toIso8601String() : null,
            'pending' => ($counts['pending'] ?? 0) + ($counts['delivering'] ?? 0),
            'dead' => $counts['dead'] ?? 0,
        ];
    }

    private function counts(): array
    {
        $rows = IntegrationOutboxRow::query()->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');

        return collect(IntegrationOutboxRow::STATUSES)->mapWithKeys(fn ($s) => [$s => (int) ($rows[$s] ?? 0)])->all();
    }

    private function tokens(User $user): array
    {
        return PersonalAccessToken::query()->with('tokenable')
            ->where('tokenable_type', User::class)
            ->when(!$user->can('admin'), fn ($q) => $q->where('tokenable_id', $user->id))
            ->orderByDesc('id')->get()
            ->map(fn (PersonalAccessToken $t) => $this->presentToken($t, $user))->all();
    }

    private function presentToken(PersonalAccessToken $t, User $viewer): array
    {
        return [
            'id' => $t->id,
            'name' => $t->name,
            'owner' => (int) $t->tokenable_id === $viewer->id ? null : $t->tokenable?->name,
            'abilities' => (array) $t->abilities,
            'last_used_at' => $t->last_used_at?->toIso8601String(),
            'expires_at' => $t->expires_at?->toIso8601String(),
            'expired' => $t->expired(),
            'created_at' => $t->created_at?->toIso8601String(),
        ];
    }
}
