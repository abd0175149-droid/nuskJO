<?php

namespace App\Http\Controllers;

use App\Models\WaConversation;
use App\Services\WhatsApp\ConversationPanel;

/**
 * اللوحة الجانبية للمحادثة (العلم conversation_panel): رحلات العميل وفواتيره وطلباته وملاحظاته.
 * المحادثة تُحل بربط محصور بسياج الرؤية (Route::bind)، فلا تُقرأ لوحة محادثة خارج نطاق الموظف.
 */
class WhatsAppConversationPanelController extends Controller
{
    public function show(WaConversation $conversation)
    {
        abort_unless(auth()->user()->can('whatsapp.view'), 403);
        abort_unless(ConversationPanel::enabled(), 404);

        return response()->json(ConversationPanel::for($conversation, auth()->user()));
    }
}
