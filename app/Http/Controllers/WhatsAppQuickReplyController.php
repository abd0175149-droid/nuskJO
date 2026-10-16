<?php

namespace App\Http\Controllers;

use App\Enums\AuditAction;
use App\Http\Requests\QuickReplyRequest;
use App\Models\AuditLog;
use App\Models\User;
use App\Models\WaConversation;
use App\Models\WaQuickReply;
use App\Services\WhatsApp\QuickReplyRenderer;
use App\Services\WhatsApp\QuickReplyText;
use App\Support\FeatureFlags;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

/**
 * الردود الجاهزة (E17.7): نصوص داخلية يدرجها الموظف في المنشئ بعد ملء متغيراتها في الخادم، ثم يرسلها بالمسار
 * العادي داخل النافذة. ليست قوالب ميتا ولا إرسالاً مباشراً. الإدارة بـ whatsapp.quick_replies، واعتماد نص
 * يذكر بيانات دفع بـ whatsapp.settings. الصفحة تعمل قبل العلم (لإعداد النصوص)، والمنتقي والملء خلفه.
 */
class WhatsAppQuickReplyController extends Controller
{
    public function index()
    {
        abort_unless(auth()->user()->can('whatsapp.quick_replies'), 403);

        $replies = WaQuickReply::orderBy('sort_order')->orderBy('id')->get();
        $names = User::whereIn('id', $replies->pluck('approved_by')->merge($replies->pluck('updated_by'))->filter()->unique())->pluck('name', 'id');

        return Inertia::render('WhatsApp/QuickReplies', [
            'title' => 'الردود الجاهزة',
            'replies' => $replies->map(fn (WaQuickReply $r) => [
                'id' => $r->id,
                'title' => $r->title,
                'body' => $r->body,
                'category' => $r->category,
                'is_active' => $r->is_active,
                'needs_approval' => $r->needs_approval,
                'approved_by' => $r->approved_by ? ($names[$r->approved_by] ?? null) : null,
                'updated_by' => $r->updated_by ? ($names[$r->updated_by] ?? null) : null,
                'updated_at' => $r->updated_at?->format('Y-m-d H:i'),
            ])->values(),
            'categories' => WaQuickReply::CATEGORIES,
            'variables' => QuickReplyText::VARIABLES,
            'canApprove' => auth()->user()->can('whatsapp.settings'),
            'enabled' => FeatureFlags::on('quick_replies'),
        ]);
    }

    public function store(QuickReplyRequest $request)
    {
        $data = $request->clean();
        $payment = QuickReplyText::mentionsPayment($data['body']);
        $r = WaQuickReply::create($data + [
            // نص يذكر بيانات دفع لا يُفعَّل قبل اعتماد المدير
            'needs_approval' => $payment,
            'is_active' => $request->boolean('is_active', true) && !$payment,
            'sort_order' => (int) WaQuickReply::max('sort_order') + 1,
            'created_by' => auth()->id(),
            'updated_by' => auth()->id(),
        ]);
        AuditLog::safe(AuditAction::WaQuickReplyCreate, 'wa_quick_reply', $r->id, $r->title, ['needs_approval' => $payment]);

        return back()->with('success', $payment ? "حُفظ «{$r->title}» وينتظر اعتماد المدير لأنه يذكر بيانات دفع" : "أُضيف الرد «{$r->title}»");
    }

    public function update(QuickReplyRequest $request, WaQuickReply $quickReply)
    {
        $data = $request->clean();
        $before = $quickReply->only(['title', 'body', 'category', 'is_active']);
        $bodyChanged = $data['body'] !== $quickReply->body;
        // أي تعديل لنص يذكر بيانات دفع يعيده للاعتماد
        $payment = QuickReplyText::mentionsPayment($data['body']);
        $reapprove = $payment && ($bodyChanged || $quickReply->needs_approval);

        $quickReply->update($data + [
            'needs_approval' => $reapprove,
            'approved_by' => $reapprove ? null : $quickReply->approved_by,
            'approved_at' => $reapprove ? null : $quickReply->approved_at,
            'is_active' => $request->boolean('is_active', $quickReply->is_active) && !$reapprove,
            'updated_by' => auth()->id(),
        ]);
        AuditLog::safe(AuditAction::WaQuickReplyUpdate, 'wa_quick_reply', $quickReply->id, $quickReply->title, ['before' => $before, 'needs_approval' => $reapprove]);

        return back()->with('success', $reapprove ? "حُفظ «{$quickReply->title}» وينتظر اعتماد المدير" : "حُفظ الرد «{$quickReply->title}»");
    }

    public function destroy(WaQuickReply $quickReply)
    {
        abort_unless(auth()->user()->can('whatsapp.quick_replies'), 403);

        AuditLog::safe(AuditAction::WaQuickReplyDelete, 'wa_quick_reply', $quickReply->id, $quickReply->title, $quickReply->only(['title', 'body', 'category']));
        $quickReply->delete();

        return back()->with('success', 'حُذف الرد');
    }

    public function reorder(Request $request)
    {
        abort_unless(auth()->user()->can('whatsapp.quick_replies'), 403);
        $ids = $request->validate(['ids' => 'required|array', 'ids.*' => 'integer'])['ids'];

        DB::transaction(function () use ($ids) {
            foreach (array_values($ids) as $i => $id) {
                WaQuickReply::whereKey($id)->update(['sort_order' => $i + 1]);
            }
        });

        return back()->with('success', 'حُفظ الترتيب');
    }

    /** اعتماد نص يذكر بيانات دفع، ثم يُفعَّل */
    public function approve(WaQuickReply $quickReply)
    {
        abort_unless(auth()->user()->can('whatsapp.settings'), 403);

        $quickReply->update(['needs_approval' => false, 'approved_by' => auth()->id(), 'approved_at' => now(), 'is_active' => true]);
        AuditLog::safe(AuditAction::WaQuickReplyApprove, 'wa_quick_reply', $quickReply->id, $quickReply->title, ['body' => $quickReply->body]);

        return back()->with('success', "اعتُمد الرد «{$quickReply->title}» وفُعّل");
    }

    /** للمنتقي في المنشئ: المفعّل المعتمد فقط */
    public function usable()
    {
        abort_unless(auth()->user()->can('whatsapp.reply'), 403);
        abort_unless(FeatureFlags::on('quick_replies'), 404);

        return response()->json([
            'replies' => WaQuickReply::usable()->orderBy('sort_order')->orderBy('id')->get(['id', 'title', 'category', 'body']),
            'categories' => WaQuickReply::CATEGORIES,
        ]);
    }

    /** ملء الرد لمحادثة (الربط المقيّد بالنطاق، E04). قراءة بلا أثر جانبي */
    public function render(Request $request, WaConversation $conversation)
    {
        abort_unless(auth()->user()->can('whatsapp.reply'), 403);
        abort_unless(FeatureFlags::on('quick_replies'), 404);
        $id = $request->validate(['reply' => 'required|integer'])['reply'];
        $reply = WaQuickReply::usable()->findOrFail($id);

        return response()->json(QuickReplyRenderer::render($reply, $conversation, auth()->user()) + [
            'window_open' => $conversation->isWindowOpen(),
        ]);
    }
}
