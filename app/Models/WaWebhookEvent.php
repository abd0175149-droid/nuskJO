<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * توصيلة ويبهوك موقّعة من ميتا (E20). الجسم مشفّر بـ APP_KEY، ولا يُسلسل ولا يُعرض في أي مسار:
 * للمتحكم وأوامر الإعادة والمحو وحدها. يُمحى فور النجاح، ولا يبقى للفاشل بعد purge_after (72 ساعة).
 */
class WaWebhookEvent extends Model
{
    public const PENDING = 'pending';

    public const PROCESSED = 'processed';

    public const FAILED = 'failed';

    public const DEAD = 'dead';

    public const REJECTED = 'rejected';

    public const MALFORMED = 'malformed';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected $hidden = ['raw_body'];

    protected function casts(): array
    {
        return [
            'raw_body' => 'encrypted',
            'item_errors' => 'array',
            'attempts' => 'integer',
            'received_at' => 'datetime',
            'next_attempt_at' => 'datetime',
            'last_attempt_at' => 'datetime',
            'processed_at' => 'datetime',
            'purge_after' => 'datetime',
        ];
    }

    /** ما ينتظر الإعادة: فاشل حان موعده، أو pending انقطع طلبه. لا malformed ولا rejected أبداً */
    public function scopeReplayable(Builder $q): Builder
    {
        return $q->whereNotNull('raw_body')
            ->where('attempts', '<', (int) config('whatsapp.webhook.max_attempts', 3))
            ->where(fn ($w) => $w
                ->where(fn ($f) => $f->where('status', self::FAILED)->where('next_attempt_at', '<=', now()))
                ->orWhere(fn ($p) => $p->where('status', self::PENDING)
                    ->where('received_at', '<', now()->subMinutes((int) config('whatsapp.webhook.pending_stale_minutes', 2)))));
    }
}
