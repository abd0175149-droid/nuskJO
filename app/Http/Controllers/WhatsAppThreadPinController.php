<?php

namespace App\Http\Controllers;

use App\Models\WaConversation;
use App\Models\WaMessage;
use App\Services\WhatsApp\ConversationFacts;
use App\Services\WhatsApp\ThreadPins;
use Illuminate\Http\Request;

/**
 * الملاحظة المثبتة والرسائل المثبتة وبطاقة الحقائق (E29) خلف العلم thread_pins. كل مسار بعد الربط المقيّد بالنطاق،
 * والرسالة من المحادثة نفسها وحدها (رسالة محادثة أخرى 404 ولو كانت في نطاقك). داخلي لا يراه العميل ولا البوت.
 */
class WhatsAppThreadPinController extends Controller
{
    /** بطاقة الحقائق: عند فتح المحادثة وبعد أي تعديل، لا مع كل استطلاع */
    public function facts(WaConversation $conversation)
    {
        abort_unless(auth()->user()->can('whatsapp.view'), 403);
        abort_unless(ThreadPins::enabled(), 404);

        return response()->json(ConversationFacts::for($conversation, auth()->user()));
    }

    public function saveNote(Request $request, WaConversation $conversation)
    {
        abort_unless(auth()->user()->can('whatsapp.reply'), 403);
        abort_unless(ThreadPins::enabled(), 404);
        $request->validate(['note' => 'required|string|max:2000']);

        ThreadPins::saveNote($conversation, $request->input('note'), auth()->user());

        return response()->json(['pinned' => ThreadPins::summary($conversation)]);
    }

    public function clearNote(WaConversation $conversation)
    {
        abort_unless(auth()->user()->can('whatsapp.reply'), 403);
        abort_unless(ThreadPins::enabled(), 404);

        ThreadPins::clearNote($conversation, auth()->user());

        return response()->json(['pinned' => ThreadPins::summary($conversation)]);
    }

    public function pinMessage(WaConversation $conversation, WaMessage $message)
    {
        abort_unless(auth()->user()->can('whatsapp.reply'), 403);
        abort_unless(ThreadPins::enabled(), 404);
        self::ownMessage($conversation, $message);

        ThreadPins::pinMessage($conversation, $message, auth()->user());

        return response()->json(['pinned' => ThreadPins::summary($conversation)]);
    }

    public function unpinMessage(WaConversation $conversation, WaMessage $message)
    {
        abort_unless(auth()->user()->can('whatsapp.reply'), 403);
        abort_unless(ThreadPins::enabled(), 404);
        self::ownMessage($conversation, $message);

        ThreadPins::unpinMessage($conversation, $message, auth()->user());

        return response()->json(['pinned' => ThreadPins::summary($conversation)]);
    }

    /**
     * Route::bind('message') صريح يحل الرسالة من أي محادثة في النطاق، فيسبق scopeBindings؛ القيد هنا:
     * الرسالة من هذه المحادثة وحدها، وإلا 404.
     */
    public static function ownMessage(WaConversation $conversation, WaMessage $message): void
    {
        abort_unless((int) $message->conversation_id === (int) $conversation->id, 404);
    }
}
