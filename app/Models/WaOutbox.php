<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * طابور الإرسال الاستباقي (رسائل النظام والمتابعات المجدولة): صف لكل رسالة مستحقة، بمفتاح send_key فريد
 * (نوع:مصدر:تكرار) فلا تتكرر عند إعادة المسح. لا يمر به رد البوت ولا رد الموظف (مسار سريع).
 * عند الاستحقاق يحسم SendGovernor (الحدود)، ثم يرسل WhatsAppChannel::send فيُكتب في سجل wa_messages كأي صادر.
 */
class WaOutbox extends Model
{
    protected $table = 'wa_outbox';

    protected $fillable = [
        'channel_id', 'conversation_id', 'client_id', 'phone', 'type_key', 'lane', 'payload', 'priority',
        'due_at', 'expires_at', 'status', 'defer_reason', 'attempts', 'lease_until', 'send_key',
        'source_type', 'source_id', 'is_test', 'cold', 'approved_by', 'approved_at', 'message_id', 'last_error', 'sent_at', 'created_by',
    ];

    protected $casts = [
        'payload' => 'array', 'priority' => 'integer', 'attempts' => 'integer', 'is_test' => 'boolean', 'cold' => 'boolean',
        'due_at' => 'datetime', 'expires_at' => 'datetime', 'lease_until' => 'datetime', 'approved_at' => 'datetime', 'sent_at' => 'datetime',
    ];

    public const PENDING = 'pending';
    public const DEFERRED = 'deferred';
    public const SENDING = 'sending';
    public const SENT = 'sent';
    public const FAILED = 'failed';
    public const EXPIRED = 'expired';
    public const CANCELLED = 'cancelled';
    public const DENIED = 'denied';
    public const AWAITING = 'awaiting_approval';

    /** ما زال ينتظر الإرسال */
    public const OPEN = [self::PENDING, self::DEFERRED, self::AWAITING];

    public const LANES = ['system', 'followup', 'staff'];

    public function channel(): BelongsTo { return $this->belongsTo(WaChannel::class, 'channel_id'); }

    public function conversation(): BelongsTo { return $this->belongsTo(WaConversation::class, 'conversation_id'); }

    public function client(): BelongsTo { return $this->belongsTo(Client::class, 'client_id'); }

    public function message(): BelongsTo { return $this->belongsTo(WaMessage::class, 'message_id'); }

    /** مستحق الآن ولا عقد حجز قائم */
    public function scopeClaimable(Builder $q): Builder
    {
        return $q->whereIn('status', [self::PENDING, self::DEFERRED])->where('due_at', '<=', now())
            ->where(fn ($w) => $w->whereNull('lease_until')->orWhere('lease_until', '<', now()));
    }

    public function scopeOpen(Builder $q): Builder
    {
        return $q->whereIn('status', self::OPEN);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN, true);
    }
}
