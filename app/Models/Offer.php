<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Offer extends Model
{
    /** التصنيفات وتسمياتها */
    public const CATEGORIES = [
        'package' => 'باقة',
        'umrah' => 'عمرة',
        'hajj' => 'حج',
        'flight' => 'تذاكر طيران',
        'visa' => 'تأشيرات',
        'hotel' => 'فنادق',
        'transport' => 'نقل',
        'tour' => 'رحلات سياحية',
    ];

    /** مسارات العمرة */
    public const ROUTE_MODES = [
        'makkah_only' => 'مكة فقط',
        'makkah_madinah' => 'مكة والمدينة',
    ];

    /** مدن الإقامة المقترحة حسب المسار */
    public const ROUTE_CITIES = [
        'makkah_only' => ['مكة'],
        'makkah_madinah' => ['مكة', 'المدينة'],
    ];

    protected $fillable = [
        'title', 'category', 'route_mode', 'description_client',
        'includes', 'excludes', 'notes_public', 'requirements',
        'valid_from', 'valid_to',
        'nights', 'airline',
        'visa_validity', 'visa_entries', 'visa_processing', 'price_per_person',
        'is_active', 'is_bot_visible', 'sort_order', 'created_by',
        'hero_path', 'card_path', 'card_generated_at', 'custom_card_path',
    ];

    protected $casts = [
        'includes' => 'array',
        'excludes' => 'array',
        'valid_from' => 'date',
        'valid_to' => 'date',
        'is_active' => 'boolean',
        'is_bot_visible' => 'boolean',
        'card_generated_at' => 'datetime',
    ];

    // ==================== شكل النموذج حسب التصنيف ====================

    /** التأشيرات: بلا فنادق، بسعر واحد للفرد وحقول إصدار */
    public function isVisa(): bool
    {
        return $this->category === 'visa';
    }

    /** التصنيفات المبنية على جدول فنادق وأسعار حسب سعة الغرفة */
    public function usesHotels(): bool
    {
        return !$this->isVisa();
    }

    /** المسافة عن الحرم تخصّ العمرة والحج دون غيرهما */
    public function usesHaramDistance(): bool
    {
        return in_array($this->category, ['umrah', 'hajj'], true);
    }

    /** هل يضمّ الخيار الواحد أكثر من إقامة؟ (عمرة/حج مكة والمدينة) */
    public function isMultiCity(): bool
    {
        return $this->route_mode === 'makkah_madinah';
    }

    /** المدن المقترحة لإقامات هذا العرض */
    public function cities(): array
    {
        return self::ROUTE_CITIES[$this->route_mode] ?? [];
    }

    public function categoryLabel(): string
    {
        return self::CATEGORIES[$this->category] ?? $this->category;
    }

    // ==================== العلاقات ====================

    public function options(): HasMany
    {
        return $this->hasMany(OfferOption::class)->orderBy('sort_order')->orderBy('id');
    }

    /** العروض المسموح بظهورها للبوت والصالحة زمنياً */
    public function scopeForBot($q)
    {
        $today = now()->toDateString();

        return $q->where('is_active', true)
            ->where('is_bot_visible', true)
            ->where(function ($w) use ($today) {
                $w->whereNull('valid_to')->orWhere('valid_to', '>=', $today);
            })
            ->where(function ($w) use ($today) {
                $w->whereNull('valid_from')->orWhere('valid_from', '<=', $today);
            });
    }

    // ==================== السعر ====================

    /** «يبدأ من» — سعر التأشيرة، أو أقل سعر للفرد عبر كل خيارات العرض */
    public function priceFrom(): ?float
    {
        if ($this->isVisa()) {
            return $this->price_per_person !== null ? (float) $this->price_per_person : null;
        }

        $mins = $this->options->map(fn ($o) => $o->minPrice())->filter()->all();

        return $mins ? min($mins) : null;
    }

    // ==================== البطاقة ====================

    /** البطاقة المعتمدة: المرفوعة يدوياً تتقدّم على المولّدة */
    public function cardImage(): ?string
    {
        return $this->custom_card_path ?: $this->card_path;
    }

    /** هل البطاقة المولّدة أقدم من آخر تعديل على العرض أو خياراته؟ */
    public function cardIsStale(): bool
    {
        if ($this->custom_card_path) {
            return false; // بطاقة يدوية — لا تُولَّد
        }
        if (!$this->card_path || !$this->card_generated_at) {
            return true;
        }

        $this->loadMissing('options.stays');
        $touched = $this->options->max('updated_at');
        $stayTouched = $this->options->flatMap->stays->max('updated_at');

        return $this->card_generated_at->lt($this->updated_at)
            || ($touched && $this->card_generated_at->lt($touched))
            || ($stayTouched && $this->card_generated_at->lt($stayTouched));
    }

    // ==================== البوت ====================

    /**
     * ما يُعاد للبوت. لا يوجد أي حقل داخلي في العروض إطلاقاً،
     * والحمولة تتبع شكل التصنيف فلا تُرسل حقولاً لا معنى لها.
     */
    public function toBotArray(): array
    {
        $base = [
            'id' => $this->id,
            'title' => $this->title,
            'category' => $this->categoryLabel(),
            'description' => $this->description_client,
            'valid_from' => $this->valid_from?->toDateString(),
            'valid_to' => $this->valid_to?->toDateString(),
            'includes' => $this->includes ?: [],
            'excludes' => $this->excludes ?: [],
            'notes' => $this->notes_public,
            'has_card_image' => (bool) $this->cardImage(),
        ];

        if ($this->isVisa()) {
            return array_merge($base, array_filter([
                'requirements' => $this->requirements,
                'visa_validity' => $this->visa_validity,
                'visa_entries' => $this->visa_entries,
                'processing_time' => $this->visa_processing,
                'price_per_person_jod' => $this->priceFrom(),
                'pricing_note' => 'السعر للفرد الواحد.',
            ], fn ($v) => $v !== null && $v !== ''));
        }

        $this->loadMissing('options.stays');

        return array_merge($base, array_filter([
            'nights' => $this->nights,
            'airline' => $this->airline,
            'route' => self::ROUTE_MODES[$this->route_mode] ?? null,
            'price_from_per_person_jod' => $this->priceFrom(),
            'pricing_note' => $this->isMultiCity()
                ? 'الأسعار للفرد الواحد وتشمل الإقامة في الفندقين معاً، وتختلف حسب سعة الغرفة.'
                : 'الأسعار للفرد الواحد وتختلف حسب سعة الغرفة والفندق.',
            'options' => $this->options
                ->map(fn ($o) => $o->toBotArray($this->usesHaramDistance()))
                ->values()->all(),
        ], fn ($v) => $v !== null && $v !== ''));
    }
}
