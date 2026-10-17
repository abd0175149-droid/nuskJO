<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * صف في الصندوق الصادر (E40): حدث واحد لوجهة واحدة، بحمولة بيضاء ثابتة عبر المحاولات (الغلاف كاملاً في payload).
 * الحالات: pending → delivering → delivered، أو dead بعد 6 محاولات أو خطأ إعداد عند المستقبل.
 * التقليم: المُسلَّم بعد 30 يوماً والميت بعد 90 (integrations:prune، وmodel:prune في E13).
 */
class IntegrationOutboxRow extends Model
{
    use MassPrunable;

    public const PENDING = 'pending';

    public const DELIVERING = 'delivering';

    public const DELIVERED = 'delivered';

    public const DEAD = 'dead';

    public const STATUSES = [self::PENDING, self::DELIVERING, self::DELIVERED, self::DEAD];

    protected $table = 'integration_outbox';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'attempts' => 'integer',
            'next_attempt_at' => 'datetime',
            'dispatched_at' => 'datetime',
            'last_status_code' => 'integer',
            'delivered_at' => 'datetime',
        ];
    }

    public function endpoint(): BelongsTo
    {
        return $this->belongsTo(IntegrationEndpoint::class, 'endpoint_id');
    }

    public function prunable(): Builder
    {
        return static::query()->where(fn (Builder $q) => $q
            ->where(fn (Builder $w) => $w->where('status', self::DELIVERED)->where('updated_at', '<', now()->subDays((int) config('integrations.delivered_days', 30))))
            ->orWhere(fn (Builder $w) => $w->where('status', self::DEAD)->where('updated_at', '<', now()->subDays((int) config('integrations.dead_days', 90)))));
    }
}
