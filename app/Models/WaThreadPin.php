<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * تثبيت داخل الخيط (E29): ملاحظة مثبتة واحدة لكل محادثة (kind=note) وحتى 3 رسائل مثبتة (kind=message).
 * داخلي لا يراه العميل ولا يقرؤه البوت (BotNeverReadsInternalNotesTest)، ولا يُكتب في wa_customer_notes.
 * ليس «تثبيت المحادثة أعلى القائمة» (WaConversationPin، E17).
 */
class WaThreadPin extends Model
{
    public const MAX_MESSAGES = 3;

    public const MAX_NOTE = 500;

    protected $fillable = ['conversation_id', 'kind', 'message_id', 'note', 'created_by', 'updated_by'];

    public function message(): BelongsTo
    {
        return $this->belongsTo(WaMessage::class, 'message_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
