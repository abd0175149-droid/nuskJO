<?php

namespace App\Http\Controllers;

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\WaConversation;
use App\Models\WaConversationPin;
use App\Services\WhatsApp\InboxFollowup;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * حالة المتابعة لمحادثة (E17): إغلاق وإعادة فتح، وتأجيل مقيّد بالنافذة، وتثبيت شخصي، و«غير مقروء» داخلي.
 * {conversation} يمر بالربط المقيّد بالنطاق (E04): 404 خارجه. لا شيء هنا ينادي ميتا.
 */
class WhatsAppConversationStateController extends Controller
{
    public function close(WaConversation $conversation)
    {
        abort_unless(auth()->user()->can('whatsapp.reply'), 403);
        if ($conversation->status === 'merged') {
            return response()->json(['error' => 'محادثة مدموجة في أخرى: افتح المحادثة الأصلية'], 422);
        }

        // المحتاجة تدخّلاً تُغلق لكنها تبقى ظاهرة في «الكل» حتى تُعالج
        $conversation->forceFill([
            'status' => 'closed', 'closed_at' => now(), 'closed_by' => auth()->id(),
            'snoozed_until' => null, 'snoozed_by' => null,
        ])->save();
        AuditLog::safe(AuditAction::WaConvClose, 'wa_conversation', $conversation->id, null, ['needs_attention' => (bool) $conversation->needs_attention]);

        return $this->state($conversation);
    }

    public function reopen(WaConversation $conversation)
    {
        abort_unless(auth()->user()->can('whatsapp.reply'), 403);
        if ($conversation->status === 'merged') {
            return response()->json(['error' => 'محادثة مدموجة في أخرى: افتح المحادثة الأصلية'], 422);
        }

        $conversation->forceFill(['status' => 'open', 'closed_at' => null, 'closed_by' => null])->save();
        AuditLog::safe(AuditAction::WaConvReopen, 'wa_conversation', $conversation->id);

        return $this->state($conversation);
    }

    /** until (وقت) أو minutes (من الآن)؛ بين 10 دقائق وقبل إغلاق النافذة بربع ساعة */
    public function snooze(Request $request, WaConversation $conversation)
    {
        abort_unless(auth()->user()->can('whatsapp.reply'), 403);
        $data = $request->validate([
            'until' => ['required_without:minutes', 'nullable', 'date'],
            'minutes' => ['required_without:until', 'nullable', 'integer', 'min:1', 'max:1440'],
        ]);

        $limit = InboxFollowup::snoozeLimit($conversation);
        if (!$limit) {
            return response()->json(['error' => 'لا يمكن التأجيل بعد إغلاق نافذة الرد — لا قوالب لدينا'], 422);
        }
        $until = isset($data['until'])
            ? Carbon::parse($data['until'])->setTimezone(config('app.timezone'))
            : now()->addMinutes((int) $data['minutes']);
        if ($until->lt(now()->addMinutes(InboxFollowup::SNOOZE_MIN_MINUTES))) {
            return response()->json(['error' => 'التأجيل '.InboxFollowup::SNOOZE_MIN_MINUTES.' دقائق على الأقل'], 422);
        }
        if ($until->gt($limit)) {
            return response()->json(['error' => 'أبعد موعد ممكن '.$limit->format('H:i').' — قبل إغلاق نافذة الرد بربع ساعة'], 422);
        }

        $conversation->forceFill(['snoozed_until' => $until, 'snoozed_by' => auth()->id()])->save();
        AuditLog::safe(AuditAction::WaConvSnooze, 'wa_conversation', $conversation->id, null, ['until' => $until->toIso8601String()]);

        return $this->state($conversation);
    }

    public function unsnooze(WaConversation $conversation)
    {
        abort_unless(auth()->user()->can('whatsapp.reply'), 403);

        $conversation->forceFill(['snoozed_until' => null, 'snoozed_by' => null])->save();
        AuditLog::safe(AuditAction::WaConvSnooze, 'wa_conversation', $conversation->id, null, ['until' => null]);

        return $this->state($conversation);
    }

    /** تثبيت شخصي: حالة عرض للموظف وحده، لا يوسّع رؤية أحد */
    public function pin(WaConversation $conversation)
    {
        abort_unless(auth()->user()->can('whatsapp.view'), 403);
        $me = auth()->id();

        $full = DB::transaction(function () use ($conversation, $me) {
            if (WaConversationPin::where('user_id', $me)->where('conversation_id', $conversation->id)->exists()) {
                return false;
            }
            if (WaConversationPin::where('user_id', $me)->count() >= WaConversationPin::MAX) {
                return true;
            }
            WaConversationPin::create(['user_id' => $me, 'conversation_id' => $conversation->id]);

            return false;
        });
        if ($full) {
            return response()->json(['error' => 'ألغِ تثبيت محادثة أولاً (الحد '.WaConversationPin::MAX.')'], 422);
        }

        return $this->state($conversation);
    }

    public function unpin(WaConversation $conversation)
    {
        abort_unless(auth()->user()->can('whatsapp.view'), 403);
        WaConversationPin::where('user_id', auth()->id())->where('conversation_id', $conversation->id)->delete();

        return $this->state($conversation);
    }

    /**
     * «غير مقروء» داخلي: هذه حالة داخلية لا تعني شيئاً للعميل. إيصال القراءة لميتا (status:read) لا تراجع عنه،
     * ومكانه E35. يمحوه الفتح الصريح (POST /read) أو رد الموظف، لا الاستطلاع.
     */
    public function markUnread(WaConversation $conversation)
    {
        abort_unless(auth()->user()->can('whatsapp.view'), 403);

        $conversation->forceFill([
            'unread_count' => max(1, (int) $conversation->unread_count),
            'marked_unread_at' => now(),
            'marked_unread_by' => auth()->id(),
        ])->save();

        return $this->state($conversation);
    }

    private function state(WaConversation $c)
    {
        $c->refresh();
        $pinned = WaConversationPin::where('user_id', auth()->id())->where('conversation_id', $c->id)->exists();

        return response()->json(['ok' => true, 'unread' => (int) $c->unread_count] + InboxFollowup::state($c, $pinned));
    }
}
