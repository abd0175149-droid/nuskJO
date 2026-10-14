<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WaConversation extends Model
{
    use HasFactory;

    protected $fillable = [
        'phone', 'wa_phone', 'display_name', 'client_id', 'user_id',
        'bot_enabled', 'bot_paused_until', 'last_inbound_at', 'last_outbound_at',
        'last_message_at', 'last_message_preview', 'unread_count',
        'needs_attention', 'assigned_to', 'status',
    ];

    // is_admin_chat في casts لا في fillable: يضبطه WaAdminNumber وحده (علَم لاصق)
    protected $casts = [
        'bot_enabled' => 'boolean',
        'needs_attention' => 'boolean',
        'is_admin_chat' => 'boolean',
        'bot_paused_until' => 'datetime',
        'last_inbound_at' => 'datetime',
        'last_outbound_at' => 'datetime',
        'last_message_at' => 'datetime',
    ];

    /**
     * المحادثات التي يراها المستخدم (السياج في TopicRouter::scopeVisible).
     * scope محلي عن قصد، لا global scope: الويبهوك والطابور والبوت يعملون بلا مستخدم.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return \App\Services\WhatsApp\TopicRouter::scopeVisible($query, $user);
    }

    public function messages(): HasMany { return $this->hasMany(WaMessage::class, 'conversation_id'); }
    public function notes(): HasMany { return $this->hasMany(WaCustomerNote::class, 'conversation_id'); }
    public function client(): BelongsTo { return $this->belongsTo(Client::class); }
    public function assignee(): BelongsTo { return $this->belongsTo(User::class, 'assigned_to'); }

    /** نافذة ميتا: 24 ساعة من آخر رسالة واردة */
    public function isWindowOpen(): bool
    {
        return $this->last_inbound_at && $this->last_inbound_at->gt(now()->subDay());
    }

    /** الدقائق الباقية قبل إغلاق النافذة — null إن كانت مغلقة */
    public function windowMinutesLeft(): ?int
    {
        if (!$this->isWindowOpen()) {
            return null;
        }

        return (int) max(0, now()->diffInMinutes($this->last_inbound_at->copy()->addDay(), false));
    }

    /** هل البوت فعّال لهذه المحادثة الآن؟ */
    public function botActive(): bool
    {
        if (!$this->bot_enabled) return false;
        if ($this->bot_paused_until && $this->bot_paused_until->isFuture()) return false;
        return true;
    }

    public function pauseBot(int $minutes): void
    {
        $this->update(['bot_paused_until' => now()->addMinutes($minutes)]);
    }
}
