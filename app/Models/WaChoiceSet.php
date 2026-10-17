<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;

/**
 * مجموعة اختيارات أرسلها البوت (E36): قائمة أو أزرار أو نموذج طلب التسعير. المعرّفات يولّدها الخادم وحده
 * (ch:{nonce}:{key})، والنموذج لا يكتب عنواناً ولا معرّفاً. الخيارات من بيانات العروض (ChoiceBuilder)، ولا بيانات عملاء.
 * التقليم: بعد 30 يوماً (E13، قائمة retention.prunable_models)، فالنقرة على مجموعة محذوفة «خيار قديم».
 */
class WaChoiceSet extends Model
{
    use MassPrunable;

    public const KINDS = ['offers_menu', 'category', 'offer_option', 'room_type', 'booking_actions', 'quote_flow'];

    public const PRUNE_DAYS = 30;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'options' => 'array',
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    /** أحدث مجموعة من نوعها في المحادثة (لرفض النقر على قائمة أقدم) */
    public function scopeLatestOfKind(Builder $q, int $conversationId, string $kind): Builder
    {
        return $q->where('conversation_id', $conversationId)->where('kind', $kind)->orderByDesc('id');
    }

    public function isExpired(): bool
    {
        return $this->expires_at === null || $this->expires_at->isPast();
    }

    /** @return array{key: string, title: string, description?: string, ref: array}|null */
    public function option(string $key): ?array
    {
        foreach ((array) $this->options as $o) {
            if (is_array($o) && ($o['key'] ?? null) === $key) {
                return $o;
            }
        }

        return null;
    }

    public function prunable(): Builder
    {
        return static::where('created_at', '<', now()->subDays(self::PRUNE_DAYS));
    }
}
