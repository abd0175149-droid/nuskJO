<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * موقع معتمد يُرسل دبوساً للعميل (E33): المكتب ونقاط تجمع الفوج والفنادق. يديره المدير وحده، والإحداثيات من هذا
 * الصف وحده: لا يقبل WhatsAppChannel::sendLocation أرقاماً حرة، وOutboundPolicy تطابق الحمولة مع الصف حرفياً.
 * الحذف تعطيل (is_active=false) لأن رسائل قديمة تشير إليه.
 */
class WaPlace extends Model
{
    public const KINDS = ['office' => 'المكتب', 'meeting' => 'نقطة تجمع', 'hotel' => 'فندق', 'other' => 'أخرى'];

    /** افتراضات الجدول نفسها، فيعرفها الصف قبل إعادة قراءته */
    protected $attributes = ['kind' => 'office', 'is_default' => false, 'bot_allowed' => false, 'is_active' => true, 'sort_order' => 0];

    protected $fillable = ['name', 'address', 'latitude', 'longitude', 'kind', 'is_default', 'bot_allowed', 'is_active',
        'sort_order', 'created_by', 'updated_by'];

    protected $casts = ['latitude' => 'decimal:7', 'longitude' => 'decimal:7', 'is_default' => 'boolean', 'bot_allowed' => 'boolean',
        'is_active' => 'boolean', 'sort_order' => 'integer'];

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeBotAllowed(Builder $query): Builder
    {
        return $query->where('bot_allowed', true);
    }

    /** مكتب البوت: فعّال ومسموح للبوت ومن نوع office وافتراضي (واحد يفرضه الحفظ) */
    public static function defaultOffice(): ?self
    {
        return static::active()->botAllowed()->where('kind', 'office')->where('is_default', true)->orderBy('id')->first();
    }

    /** كائن location الرسمي: الإحداثيات نصاً كما في الصف (decimal:7)، والاسم حتى 60 حرفاً */
    public function toPayload(): array
    {
        return array_filter([
            'latitude' => (string) $this->latitude,
            'longitude' => (string) $this->longitude,
            'name' => mb_substr((string) $this->name, 0, 60),
            'address' => $this->address !== null && trim($this->address) !== '' ? mb_substr($this->address, 0, 160) : null,
        ], fn ($v) => $v !== null);
    }

    /** رابط «تحقق على الخرائط»: من أرقام فقط، بلا خريطة مضمّنة ولا سكربت خارجي (CSP من E26) */
    public function mapsUrl(): string
    {
        return self::mapsUrlFor((float) $this->latitude, (float) $this->longitude);
    }

    public static function mapsUrlFor(float $lat, float $lng): string
    {
        return 'https://www.google.com/maps/search/?api=1&query='.round($lat, 7).','.round($lng, 7);
    }
}
