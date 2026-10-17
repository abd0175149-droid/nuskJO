<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Notification extends Model
{
    use MassPrunable;

    public $timestamps = false; // الجدول فيه created_at فقط

    /** المقروء بعد 90 يوماً، وكل إشعار بعد 180 (E13): تحمل أسماء عملاء وتفاصيل طلباتهم */
    public function prunable(): Builder
    {
        return static::where(fn ($q) => $q->where('is_read', true)->where('created_at', '<', now()->subDays((int) config('retention.notifications_read_days', 90))))
            ->orWhere('created_at', '<', now()->subDays((int) config('retention.notifications_unread_days', 180)));
    }

    protected $fillable = [
        'user_id', 'title', 'body', 'type', 'icon',
        'action_url', 'data', 'is_read', 'read_at',
    ];

    protected $casts = [
        'data' => 'array',
        'is_read' => 'boolean',
        'read_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    protected static function booted()
    {
        static::creating(function ($notification) {
            $notification->created_at = $notification->created_at ?? now();
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeUnread($query)
    {
        return $query->where('is_read', false);
    }
}
