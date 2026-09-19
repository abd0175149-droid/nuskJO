<?php

namespace App\Http\Controllers;

use App\Models\WaBotSetting;
use App\Models\WaConversation;
use App\Services\WhatsApp\BotEngine;
use App\Services\WhatsApp\WhatsAppChannel;
use Illuminate\Http\Request;
use Inertia\Inertia;

class WhatsAppController extends Controller
{
    // ==================== الإنبوكس ====================

    public function inbox(Request $request)
    {
        abort_unless(auth()->user()->can('whatsapp.view'), 403);

        $s = WaBotSetting::current();

        return Inertia::render('WhatsApp/Inbox', [
            'title' => 'محادثات الواتساب',
            'conversations' => $this->conversationList($request),
            'filters' => $request->only(['filter', 'search']),
            'status' => [
                'channel_ready' => $s->channelReady(),
                'bot_ready' => $s->botReady(),
                'bot_enabled' => $s->enabled,
                'suspended' => $s->wa_suspended,
            ],
            'canReply' => auth()->user()->can('whatsapp.reply'),
        ]);
    }

    /** JSON — لتحديث القائمة دورياً */
    public function conversations(Request $request)
    {
        abort_unless(auth()->user()->can('whatsapp.view'), 403);

        return response()->json([
            'conversations' => $this->conversationList($request),
            'unread_total' => WaConversation::sum('unread_count'),
        ]);
    }

    private function conversationList(Request $request): array
    {
        $filter = $request->get('filter', 'all');

        return WaConversation::query()
            ->with(['client:id,name,code', 'assignee:id,name'])
            ->when($request->search, function ($q, $s) {
                $q->where(function ($w) use ($s) {
                    $w->where('phone', 'like', "%{$s}%")
                      ->orWhere('display_name', 'like', "%{$s}%");
                });
            })
            ->when($filter === 'unread', fn ($q) => $q->where('unread_count', '>', 0))
            ->when($filter === 'attention', fn ($q) => $q->where('needs_attention', true))
            ->when($filter === 'bot', fn ($q) => $q->where('bot_enabled', true))
            ->when($filter === 'human', fn ($q) => $q->where('bot_enabled', false))
            ->orderByDesc('last_message_at')
            ->limit(80)
            ->get()
            ->map(fn ($c) => [
                'id' => $c->id,
                'phone' => $c->phone,
                'name' => $c->display_name ?: $c->client?->name ?: $c->phone,
                'client' => $c->client?->name,
                'client_id' => $c->client_id,
                'bot_enabled' => (bool) $c->bot_enabled,
                'paused' => $c->bot_paused_until && $c->bot_paused_until->isFuture(),
                'needs_attention' => (bool) $c->needs_attention,
                'unread' => (int) $c->unread_count,
                'preview' => $c->last_message_preview,
                'last_at' => $c->last_message_at?->diffForHumans(),
                'window_open' => $c->isWindowOpen(),
                'assignee' => $c->assignee?->name,
            ])->all();
    }

    public function messages(WaConversation $conversation)
    {
        abort_unless(auth()->user()->can('whatsapp.view'), 403);

        $msgs = $conversation->messages()
            ->orderBy('id')->limit(300)
            ->get(['id', 'direction', 'source', 'msg_type', 'body', 'status', 'error_message', 'created_at'])
            ->map(fn ($m) => [
                'id' => $m->id,
                'direction' => $m->direction,
                'source' => $m->source,
                'type' => $m->msg_type,
                'body' => $m->body,
                'status' => $m->status,
                'error' => $m->error_message,
                'at' => $m->created_at?->format('Y-m-d H:i'),
            ]);

        $conversation->update(['unread_count' => 0]);

        return response()->json([
            'messages' => $msgs,
            'conversation' => [
                'id' => $conversation->id,
                'name' => $conversation->display_name ?: $conversation->phone,
                'phone' => $conversation->phone,
                'client' => $conversation->client?->name,
                'bot_enabled' => (bool) $conversation->bot_enabled,
                'needs_attention' => (bool) $conversation->needs_attention,
                'window_open' => $conversation->isWindowOpen(),
            ],
            'notes' => $conversation->notes()->orderByDesc('id')->limit(10)->pluck('note'),
        ]);
    }

    /** ردّ الموظف — يوقف البوت مؤقتاً تلقائياً داخل بوّابة الإرسال */
    public function send(Request $request, WaConversation $conversation)
    {
        abort_unless(auth()->user()->can('whatsapp.reply'), 403);

        $data = $request->validate(['body' => 'required|string|max:4000']);

        $res = WhatsAppChannel::sendText($conversation, $data['body'], 'staff', auth()->id());

        return response()->json($res, $res['ok'] ? 200 : 422);
    }

    public function toggleBot(WaConversation $conversation)
    {
        abort_unless(auth()->user()->can('whatsapp.bot_toggle'), 403);

        $conversation->update([
            'bot_enabled' => !$conversation->bot_enabled,
            'needs_attention' => false,
            'bot_paused_until' => null,
        ]);

        return response()->json(['bot_enabled' => (bool) $conversation->fresh()->bot_enabled]);
    }

    public function resolve(WaConversation $conversation)
    {
        abort_unless(auth()->user()->can('whatsapp.reply'), 403);
        $conversation->update(['needs_attention' => false]);
        return response()->json(['ok' => true]);
    }

    // ==================== الإعدادات ====================

    public function settings()
    {
        abort_unless(auth()->user()->can('whatsapp.settings'), 403);

        $s = WaBotSetting::current();

        return Inertia::render('WhatsApp/Settings', [
            'title' => 'إعدادات بوت الواتساب',
            'settings' => [
                'enabled' => (bool) $s->enabled,
                'wa_suspended' => (bool) $s->wa_suspended,
                'wa_phone_number_id' => $s->wa_phone_number_id,
                'wa_verify_token' => $s->wa_verify_token,
                'model' => $s->model,
                'system_prompt' => $s->system_prompt ?: BotEngine::DEFAULT_PROMPT,
                'knowledge_base' => $s->knowledge_base,
                'context_messages' => (int) $s->context_messages,
                'pause_minutes' => (int) $s->pause_minutes,
                'max_tool_loops' => (int) $s->max_tool_loops,
                'rate_limit_per_hour' => (int) $s->rate_limit_per_hour,
                'fail_message' => $s->fail_message,
                'fail_handoff' => (bool) $s->fail_handoff,
                'tools_config' => $s->tools_config ?? [],
                // الأسرار: نكشف وجودها فقط لا قيمتها
                'has_token' => !empty($s->token()),
                'has_app_secret' => !empty($s->appSecret()),
                'has_api_key' => !empty($s->llmKey()),
                'env_token' => !empty(env('WA_TOKEN')),
                'env_api_key' => !empty(env('ANTHROPIC_API_KEY')),
            ],
            'webhookUrl' => url('/api/whatsapp/webhook'),
            'defaultPrompt' => BotEngine::DEFAULT_PROMPT,
            'toolList' => [
                'get_offers' => 'عرض العروض والباقات',
                'get_offer_details' => 'تفاصيل عرض',
                'get_my_balance' => 'رصيد ذمّة العميل',
                'get_my_invoices' => 'فواتير العميل والمتبقي',
                'get_my_trips' => 'رحلات العميل القادمة',
                'request_quote' => 'طلب تسعير ← تحويل لموظف',
                'confirm_booking' => 'تأكيد حجز ← تحويل لموظف',
                'save_note' => 'حفظ ملاحظة عن العميل',
                'handoff_to_human' => 'تحويل لموظف',
            ],
        ]);
    }

    public function updateSettings(Request $request)
    {
        abort_unless(auth()->user()->can('whatsapp.settings'), 403);

        $data = $request->validate([
            'enabled' => 'boolean',
            'wa_suspended' => 'boolean',
            'wa_phone_number_id' => 'nullable|string|max:64',
            'wa_verify_token' => 'nullable|string|max:128',
            'wa_token' => 'nullable|string',
            'wa_app_secret' => 'nullable|string',
            'api_key' => 'nullable|string',
            'model' => 'nullable|string|max:64',
            'system_prompt' => 'nullable|string|max:20000',
            'knowledge_base' => 'nullable|string|max:40000',
            'context_messages' => 'integer|min:4|max:60',
            'pause_minutes' => 'integer|min:1|max:1440',
            'max_tool_loops' => 'integer|min:1|max:8',
            'rate_limit_per_hour' => 'integer|min:1|max:200',
            'fail_message' => 'nullable|string|max:500',
            'fail_handoff' => 'boolean',
            'tools_config' => 'nullable|array',
        ]);

        // الأسرار الفارغة لا تمسح المحفوظ
        foreach (['wa_token', 'wa_app_secret', 'api_key'] as $secret) {
            if (blank($data[$secret] ?? null)) {
                unset($data[$secret]);
            }
        }

        WaBotSetting::current()->update($data);

        return back()->with('success', 'تم حفظ إعدادات البوت');
    }
}
