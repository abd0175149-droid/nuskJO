<?php

namespace App\Http\Controllers;

use App\Models\WaBlock;
use App\Models\WaBotSetting;
use App\Models\WaConversation;
use App\Services\WhatsApp\BlockService;
use App\Services\WhatsApp\BlockSync;
use App\Support\FeatureFlags;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * الحظر الرسمي (E31): الحظر وفكه من الصندوق، وصفحة «المحظورون» ومزامنتها القرائية مع ميتا.
 * whatsapp.block مفحوصة في كل دالة فوق الربط المقيّد (E04: {conversation} خارج النطاق 404)، والعلم wa_block مطفأ = 404.
 */
class WhatsAppBlockController extends Controller
{
    private function authorizeBlock(): void
    {
        abort_unless(auth()->user()->can('whatsapp.block'), 403);
        abort_unless(FeatureFlags::on('wa_block') && BlockService::available(), 404);
    }

    /** POST api/whatsapp/conversations/{conversation}/block: سبب وتفاصيل وتأكيد بآخر 4 أرقام (أو «حظر» بلا رقم) */
    public function block(Request $request, WaConversation $conversation): JsonResponse
    {
        $this->authorizeBlock();
        $data = $request->validate([
            'reason_code' => ['required', 'string', Rule::in(array_keys(BlockService::REASONS))],
            'reason' => 'required|string|min:'.BlockService::REASON_MIN.'|max:'.BlockService::REASON_MAX,
            'confirm' => 'required|string|max:20',
        ], [
            'reason_code.*' => 'اختر سبب الحظر.',
            'reason.*' => 'اكتب تفاصيل السبب من '.BlockService::REASON_MIN.' إلى '.BlockService::REASON_MAX.' حرف.',
            'confirm.*' => 'اكتب التأكيد.',
        ]);
        if (!BlockService::confirmMatches($conversation, $data['confirm'])) {
            return response()->json(['ok' => false, 'code' => 'CONFIRM_MISMATCH', 'message' => 'التأكيد لا يطابق.', 'error' => 'التأكيد لا يطابق.',
                'errors' => ['confirm' => ['التأكيد لا يطابق.']]], 422);
        }

        $r = BlockService::block($conversation, auth()->user(), $data['reason_code'], $data['reason']);

        return response()->json($r, $r['ok'] ? 200 : 422);
    }

    /** DELETE api/whatsapp/conversations/{conversation}/block: فك الحظر القائم بسبب إلزامي */
    public function unblockConversation(Request $request, WaConversation $conversation): JsonResponse
    {
        $this->authorizeBlock();
        $data = $request->validate(['reason' => 'required|string|min:'.BlockService::UNBLOCK_REASON_MIN.'|max:'.BlockService::REASON_MAX],
            ['reason.*' => 'اكتب سبب فك الحظر من '.BlockService::UNBLOCK_REASON_MIN.' إلى '.BlockService::REASON_MAX.' حرف.']);
        $block = WaBlock::active()->where('conversation_id', $conversation->id)->orderByDesc('id')->first();
        if (!$block) {
            return response()->json(['ok' => false, 'code' => 'NOT_BLOCKED', 'error' => 'هذه المحادثة غير محظورة.'], 422);
        }

        $r = BlockService::unblock($block, auth()->user(), $data['reason']);

        return response()->json($r, $r['ok'] ? 200 : 422);
    }

    /** صفحة «المحظورون»: الفعّالة والسجل وآخر مزامنة */
    public function index()
    {
        $this->authorizeBlock();

        $rows = WaBlock::query()->with(['conversation', 'blocker:id,name', 'unblocker:id,name', 'adminNumber:id,name'])
            ->orderByDesc('id')->limit(500)->get();

        return Inertia::render('WhatsApp/Blocks', [
            'title' => 'المحظورون على واتساب',
            'active' => $rows->filter->isActive()->map(fn ($b) => $this->row($b))->values(),
            'history' => $rows->reject->isActive()->map(fn ($b) => $this->row($b))->values(),
            'lastSync' => BlockSync::lastSummary(),
            'reasons' => BlockService::REASONS,
            'channelReady' => WaBotSetting::current()->channelReady(),
        ]);
    }

    /** POST api/whatsapp/blocks/sync: GET block_users ومقارنة، بلا أي تغيير عند ميتا */
    public function sync(): JsonResponse
    {
        $this->authorizeBlock();
        $r = BlockSync::run('manual');

        return response()->json($r, $r['ok'] ? 200 : 422);
    }

    /** POST api/whatsapp/blocks/{block}/unblock من الصفحة (والمحادثة قد تكون حُذفت) */
    public function unblock(Request $request, WaBlock $block): JsonResponse
    {
        $this->authorizeBlock();
        $data = $request->validate(['reason' => 'required|string|min:'.BlockService::UNBLOCK_REASON_MIN.'|max:'.BlockService::REASON_MAX],
            ['reason.*' => 'اكتب سبب فك الحظر من '.BlockService::UNBLOCK_REASON_MIN.' إلى '.BlockService::REASON_MAX.' حرف.']);

        $r = BlockService::unblock($block, auth()->user(), $data['reason']);

        return response()->json($r, $r['ok'] ? 200 : 422);
    }

    /** POST api/whatsapp/blocks/{block}/reblock: حظر لم تجده ميتا يُعاد عندها (النافذة مفتوحة) */
    public function reblock(WaBlock $block): JsonResponse
    {
        $this->authorizeBlock();
        $r = BlockService::reblock($block, auth()->user());

        return response()->json($r, $r['ok'] ? 200 : 422);
    }

    /** POST api/whatsapp/blocks/untracked/register: «تسجيل سبب» لمحظور عند ميتا بلا سجل عندنا. المرجع من آخر مزامنة */
    public function registerUntracked(Request $request): JsonResponse
    {
        $this->authorizeBlock();
        $data = $request->validate([
            'ref' => 'required|string|size:24',
            'reason_code' => ['required', 'string', Rule::in(array_keys(BlockService::REASONS))],
            'reason' => 'required|string|min:'.BlockService::REASON_MIN.'|max:'.BlockService::REASON_MAX,
        ], ['reason_code.*' => 'اختر سبب الحظر.', 'reason.*' => 'اكتب تفاصيل السبب من '.BlockService::REASON_MIN.' إلى '.BlockService::REASON_MAX.' حرف.']);
        $entry = BlockSync::untracked($data['ref']);
        if ($entry === null) {
            return response()->json(['ok' => false, 'code' => 'SYNC_EXPIRED', 'error' => 'انتهت نتيجة المزامنة: شغّلها من جديد.'], 422);
        }

        $r = BlockService::registerUntracked($entry, auth()->user(), $data['reason_code'], $data['reason']);
        if ($r['ok']) {
            BlockSync::forgetUntracked($data['ref']);
        }

        return response()->json($r, $r['ok'] ? 200 : 422);
    }

    /** POST api/whatsapp/blocks/untracked/unblock: فك حظر عند ميتا بلا سجل عندنا، بسبب إلزامي */
    public function unblockUntracked(Request $request): JsonResponse
    {
        $this->authorizeBlock();
        $data = $request->validate([
            'ref' => 'required|string|size:24',
            'reason' => 'required|string|min:'.BlockService::UNBLOCK_REASON_MIN.'|max:'.BlockService::REASON_MAX,
        ], ['reason.*' => 'اكتب سبب فك الحظر من '.BlockService::UNBLOCK_REASON_MIN.' إلى '.BlockService::REASON_MAX.' حرف.']);
        $entry = BlockSync::untracked($data['ref']);
        if ($entry === null) {
            return response()->json(['ok' => false, 'code' => 'SYNC_EXPIRED', 'error' => 'انتهت نتيجة المزامنة: شغّلها من جديد.'], 422);
        }

        $r = BlockService::unblockUntracked($entry, auth()->user(), $data['reason']);
        if ($r['ok']) {
            BlockSync::forgetUntracked($data['ref']);
        }

        return response()->json($r, $r['ok'] ? 200 : 422);
    }

    /** صف للعرض: الاسم من label() نصاً غير موثوق (Vue يهرّبه)، وآخر 4 أرقام وحدها */
    private function row(WaBlock $b): array
    {
        $conv = $b->conversation && !$b->conversation->erased_at ? $b->conversation : null;

        return [
            'id' => $b->id,
            'conversation_id' => $conv?->id,
            'name' => $conv?->noticeLabel(),
            'last4' => $b->last4(),
            'kind' => $b->erased_at ? 'erased' : ($b->phone ? 'phone' : 'bsuid'),
            'reason_code' => $b->reason_code,
            'reason_label' => BlockService::reasonLabel($b->reason_code),
            'reason' => $b->reason,
            'by' => $b->blocker?->name ?: ($b->adminNumber ? 'رقم الإدارة «'.$b->adminNumber->name.'»' : null),
            'via' => $b->blocked_via,
            'blocked_at' => $b->blocked_at?->toIso8601String(),
            'meta_state' => $b->meta_state,
            'meta_checked_at' => $b->meta_checked_at?->toIso8601String(),
            'meta_error_code' => $b->meta_error_code,
            'window_open' => $conv?->isWindowOpen() ?? false,
            'unblocked_at' => $b->unblocked_at?->toIso8601String(),
            'unblocked_by' => $b->unblocker?->name,
            'unblock_reason' => $b->unblock_reason,
        ];
    }
}
