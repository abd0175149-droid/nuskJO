<?php

namespace App\Http\Controllers;

use App\Enums\AuditAction;
use App\Jobs\ProcessWhatsAppReply;
use App\Models\AuditLog;
use App\Models\WaBotSetting;
use App\Models\WaConversation;
use App\Models\WaConversationPin;
use App\Services\WhatsApp\InboxFollowup;
use App\Services\WhatsApp\ReplyState;
use App\Services\WhatsApp\StuckReplies;
use App\Support\FeatureFlags;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
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

    /**
     * «🔁 إعادة معالجة» لرد عالق (E17.5) لمن يملك whatsapp.settings: ما يقابل force-kill في OpenWA بلا قتل.
     * لا يُحرَّر قفل أحدث من مهلة الوظيفة أبداً، والرمز الأحدث يُبطل أي وظيفة منتظرة، وكل إعادة تُدقَّق.
     */
    public function reprocess(WaConversation $conversation)
    {
        abort_unless(auth()->user()->can('whatsapp.settings'), 403);
        if (!FeatureFlags::on('reprocess_button')) {
            return response()->json(['error' => 'زر إعادة المعالجة غير مفعّل'], 403);
        }

        $key = 'wa:reprocess:'.$conversation->id;
        Cache::add($key, 0, now()->addMinutes(10));
        if (Cache::increment($key) > 3) {
            return response()->json(['error' => 'ثلاث محاولات في 10 دقائق — انتظر قليلاً أو رد يدوياً'], 429);
        }

        $s = WaBotSetting::current();
        $fail = match (true) {
            !$conversation->isWindowOpen() => 'نافذة الرد مغلقة — لا يمكن الرد على العميل حتى يكتب من جديد',
            !$s->botReady() || !$conversation->botActive() => 'البوت موقوف لهذه المحادثة — فعّله أو رد يدوياً',
            !ReplyState::hasPending($conversation) => 'لا رسالة معلّقة',
            // بوابة الحداثة (E07): وظيفة كهذه تنتهي «متأخرة» بلا رد
            ReplyState::isStale($conversation, $s) => 'الرسالة أقدم من '.ReplyState::maxAgeMinutes($s).' دقيقة؛ البوت لن يرد عليها آلياً — رد يدوياً',
            default => null,
        };
        if ($fail !== null) {
            return response()->json(['error' => $fail], 422);
        }

        $running = (int) Cache::get(ProcessWhatsAppReply::runningKey($conversation->id), 0);
        $age = StuckReplies::lockAge($conversation->id);
        if (($running && time() - $running < ProcessWhatsAppReply::RUNNING_SECONDS) || ($age !== null && $age <= ProcessWhatsAppReply::TIMEOUT)) {
            return response()->json(['error' => 'معالجة جارية الآن — انتظر دقيقة'], 409);
        }

        $diagnosis = StuckReplies::diagnose($conversation);
        $released = false;
        if ($age !== null) {
            // قفل أقدم من المهلة: عامل قُتل وتركه، ولا وظيفة حيّة تمسكه
            Cache::lock(StuckReplies::lockName($conversation->id))->forceRelease();
            $released = true;
        }
        foreach (ReplyState::pendingQuery($conversation)->pluck('id') as $id) {
            Cache::forget("wa:recon:{$conversation->id}:{$id}");
        }
        ProcessWhatsAppReply::schedule($conversation->id);

        AuditLog::safe(AuditAction::WaReprocess, 'wa_conversation', $conversation->id, null, [
            'lock_released' => $released, 'diagnosis' => $diagnosis['state'], 'waiting_min' => $diagnosis['waiting_min'],
        ]);

        return response()->json(['ok' => true, 'lock_released' => $released, 'message' => 'أُعيدت الجدولة — يصل الرد خلال دقيقة إن نجح']);
    }

    private function state(WaConversation $c)
    {
        $c->refresh();
        $pinned = WaConversationPin::where('user_id', auth()->id())->where('conversation_id', $c->id)->exists();

        return response()->json(['ok' => true, 'unread' => (int) $c->unread_count] + InboxFollowup::state($c, $pinned));
    }
}
