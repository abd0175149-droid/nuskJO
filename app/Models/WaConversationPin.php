<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** تثبيت شخصي لمحادثة في صندوق موظف (E17): حالة عرض لا توسّع رؤية أحد، بحد MAX لكل موظف */
class WaConversationPin extends Model
{
    public const MAX = 5;

    public const UPDATED_AT = null;

    protected $fillable = ['user_id', 'conversation_id'];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(WaConversation::class, 'conversation_id');
    }
}
