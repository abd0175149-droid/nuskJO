<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WaMessage extends Model
{
    use HasFactory;

    protected $fillable = [
        'conversation_id', 'wamid', 'direction', 'source', 'msg_type',
        'body', 'payload', 'status', 'error_message', 'staff_id', 'meta_code', 'fbtrace_id', 'meta_at',
    ];

    // meta_at: وقت ميتا للوارد (timestamp الويبهوك)، لا وقت وصوله إلينا
    protected $casts = ['payload' => 'array', 'meta_at' => 'datetime'];

    public function conversation(): BelongsTo { return $this->belongsTo(WaConversation::class, 'conversation_id'); }

    /** الموظف الذي أرسل الرسالة — للرسائل من نوع staff */
    public function staff(): BelongsTo { return $this->belongsTo(User::class, 'staff_id'); }

    public function isInbound(): bool { return $this->direction === 'in'; }
}
