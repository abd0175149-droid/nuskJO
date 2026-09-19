<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WaMessage extends Model
{
    protected $fillable = [
        'conversation_id', 'wamid', 'direction', 'source', 'msg_type',
        'body', 'payload', 'status', 'error_message', 'staff_id',
    ];

    protected $casts = ['payload' => 'array'];

    public function conversation(): BelongsTo { return $this->belongsTo(WaConversation::class, 'conversation_id'); }

    public function isInbound(): bool { return $this->direction === 'in'; }
}
