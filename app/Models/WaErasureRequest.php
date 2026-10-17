<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * طلب حذف بيانات (E13): بصمة الرقم لا الرقم، وأعداد ما حُذف فقط. يبقى «صفاً شاهداً» بعد التنفيذ.
 */
class WaErasureRequest extends Model
{
    public const OPEN = ['received', 'verified', 'running'];

    public const METHODS = ['same_number_whatsapp', 'email_reply', 'in_person', 'id_check'];

    protected $fillable = [
        'status', 'channel', 'conversation_id', 'subject_hmac', 'wa_user_hmac', 'received_at', 'due_at',
        'verified_at', 'verified_by', 'verification_method', 'executed_at', 'executed_by', 'counts', 'reason_code',
    ];

    protected $casts = [
        'counts' => 'array',
        'received_at' => 'datetime',
        'due_at' => 'datetime',
        'verified_at' => 'datetime',
        'executed_at' => 'datetime',
    ];

    public function conversation()
    {
        return $this->belongsTo(WaConversation::class);
    }
}
