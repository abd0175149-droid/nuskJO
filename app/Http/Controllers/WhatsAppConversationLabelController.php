<?php

namespace App\Http\Controllers;

use App\Models\WaConversation;
use App\Models\WaLabel;
use App\Services\WhatsApp\LabelService;

/**
 * وضع تصنيف على محادثة وإزالته (E29): لمن يرد عليها، والمحادثة عبر الربط المقيّد (خارج النطاق 404). التصنيفات
 * لا تغيّر من يرى المحادثة ولا من وُكّلت إليه. العلم wa_labels مطفأ: 404.
 */
class WhatsAppConversationLabelController extends Controller
{
    public function attach(WaConversation $conversation, WaLabel $label)
    {
        abort_unless(auth()->user()->can('whatsapp.reply'), 403);
        abort_unless(LabelService::enabled(), 404);

        return response()->json(['labels' => LabelService::attach($conversation, $label, auth()->user())]);
    }

    public function detach(WaConversation $conversation, WaLabel $label)
    {
        abort_unless(auth()->user()->can('whatsapp.reply'), 403);
        abort_unless(LabelService::enabled(), 404);

        return response()->json(['labels' => LabelService::detach($conversation, $label, auth()->user())]);
    }
}
