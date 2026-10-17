<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * مكالمة واتساب واحدة (E39، العلم calls_webhook): السجل وحده. لا SDP ولا صوت ولا عنوان IP في أي عمود،
 * ولا رقم المتصل (المحادثة تعرفه). CallEventHandler وحده يكتب هنا.
 */
class WaCall extends Model
{
    public const STATUSES = ['ringing', 'accepted', 'completed', 'missed', 'rejected'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'connect_at' => 'datetime',
            'start_time' => 'datetime',
            'end_time' => 'datetime',
            'duration_seconds' => 'integer',
            'missed' => 'boolean',
            'rejected_by_us' => 'boolean',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(WaConversation::class, 'conversation_id');
    }
}
