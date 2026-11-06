<?php

namespace App\Http\Controllers;

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\WaConversation;
use App\Models\WaNotificationPref;
use App\Services\WhatsApp\System\Catalog;
use App\Services\WhatsApp\System\Scheduler;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * رسائل النظام من المحادثة نفسها (اللوحة والكاتب، 2026-11-06): متابعة يجدولها الموظف بنصه (lead.followup، تُلغى إن رد
 * العميل قبلها)، وكتم رسائل النظام للعميل أو رفعه. المحادثة بربط محصور بسياج الرؤية، فلا يجدول موظف لعميل خارج نطاقه.
 */
class WhatsAppConversationSystemController extends Controller
{
    public function followUp(Request $request, WaConversation $conversation)
    {
        $data = $request->validate([
            'text' => ['required', 'string', 'min:2', 'max:1500'],
            'at' => ['required', 'date', 'after:now', 'before:'.now()->addDays(60)->toDateString()],
        ], ['at.after' => 'اختر موعداً في المستقبل.']);
        if (!$conversation->channel_id) {
            return response()->json(['ok' => false, 'error' => 'لا رقم واتساب لهذه المحادثة.'], 422);
        }
        $at = Carbon::parse($data['at'], 'Asia/Amman')->setTimezone(config('app.timezone'));
        $item = Scheduler::schedule('lead.followup', 'conversation', (int) $conversation->id, $at, (string) Str::ulid(), [],
            ['text' => trim($data['text']), 'created_by' => $request->user()->id, 'phone' => $conversation->phone]);
        if ($item === null) {
            return response()->json(['ok' => false, 'error' => 'المتابعات المجدولة مطفأة من صفحة رسائل النظام، أو لا رقم هاتف للعميل.'], 422);
        }
        AuditLog::safe(AuditAction::WaOutboxAction, 'wa_outbox', $item->id, 'follow_up', ['conversation_id' => $conversation->id, 'due_at' => $at->toIso8601String()]);

        return response()->json(['ok' => true, 'id' => $item->id, 'is_test' => (bool) $item->is_test,
            'note' => $item->is_test ? 'نوع «المتابعة» في مرحلة الاختبار: ستصل أرقام الاختبار لا العميل حتى يُطلق النوع.' : null]);
    }

    public function mute(Request $request, WaConversation $conversation)
    {
        $data = $request->validate(['mute' => ['required', 'boolean'], 'group' => ['nullable', Rule::in(array_keys(array_diff_key(Catalog::GROUPS, ['admin' => 1])))]]);
        if (!$conversation->client_id) {
            return response()->json(['ok' => false, 'error' => 'اربط المحادثة بعميل أولاً.'], 422);
        }
        $group = $data['group'] ?? null;
        if ($data['mute']) {
            WaNotificationPref::updateOrCreate(['client_id' => $conversation->client_id, 'group' => $group, 'type_key' => null],
                ['muted_until' => null, 'source' => 'staff', 'created_by' => $request->user()->id]);
        } else {
            WaNotificationPref::where('client_id', $conversation->client_id)->when($group !== null, fn ($q) => $q->where('group', $group))->delete();
        }
        AuditLog::safe(AuditAction::WaOutboxAction, 'client', $conversation->client_id, $data['mute'] ? 'mute' : 'unmute', ['group' => $group]);

        return response()->json(['ok' => true]);
    }
}
