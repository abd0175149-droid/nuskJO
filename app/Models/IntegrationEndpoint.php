<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * وجهة تكامل صادر (E40): رابط https يضبطه صاحب integrations.manage، وأحداث من IntegrationEvents::SUBSCRIBABLE.
 * السر whsec_ مشفّر في القاعدة ومخفي من أي تسلسل، ولا يغادر الخادم إلا مرة واحدة عند توليده أو تدويره.
 * تُنشأ موقوفة (is_active=false) ولا تُشغَّل إلا بعد ping ناجح.
 */
class IntegrationEndpoint extends Model
{
    protected $fillable = ['name', 'url', 'events', 'include_amounts', 'created_by'];

    protected $hidden = ['secret', 'previous_secret'];

    protected function casts(): array
    {
        return [
            'events' => 'array',
            'include_amounts' => 'boolean',
            'is_active' => 'boolean',
            'secret' => 'encrypted',
            'previous_secret' => 'encrypted',
            'previous_secret_expires_at' => 'datetime',
            'secret_rotated_at' => 'datetime',
            'consecutive_failures' => 'integer',
            'last_success_at' => 'datetime',
            'last_failure_at' => 'datetime',
            'last_ping_ok_at' => 'datetime',
        ];
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(IntegrationOutboxRow::class, 'endpoint_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** الأحداث مخزّنة مصفوفة JSON صغيرة: التصفية بعد القراءة (جدول من صفوف قليلة) */
    public function subscribedTo(string $event): bool
    {
        return in_array($event, (array) $this->events, true);
    }

    /** المضيف وحده: الرابط الكامل قد يحمل معاملات سرية، فلا يُعرض ولا يُسجَّل */
    public function host(): string
    {
        return strtolower((string) parse_url((string) $this->url, PHP_URL_HOST));
    }

    /**
     * أسرار التوقيع الآن: الحالي، ومعه السابق خلال نافذة التدوير (24 ساعة). يُقرأ من الصف في كل محاولة، فيسري التدوير فوراً.
     *
     * @return list<string>
     */
    public function signingSecrets(): array
    {
        $secrets = [(string) $this->secret];
        if ($this->previous_secret && $this->previous_secret_expires_at?->isFuture()) {
            $secrets[] = (string) $this->previous_secret;
        }

        return $secrets;
    }
}
