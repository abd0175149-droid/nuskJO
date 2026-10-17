<?php

namespace App\Models;

use App\Services\SellOption;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Collection;

/**
 * العرض — المصدر الواحد لكل ما يخصّه.
 *
 * يحرّره الموظف من صفحة واحدة، ويقرؤه: البوت (forBot + toBotArray)، والبطاقة،
 * وقائمة العروض الجاهزة (is_ready)، وفاتورة العرض (OfferPricing)، والقيود.
 * لا نسخة منه في أي مكان آخر؛ ما يبدو رقماً مكرراً (المتاح، «يبدأ من»،
 * خيارات البوت) يُشتق عند الطلب.
 */
class Offer extends Model
{
    use HasFactory;

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

    public const TRAVEL_MODES = [
        'land' => 'برًا',
        'air' => 'جوًا',
    ];

    /** نمط الفنادق: قائمة لكل مدينة، أو باقات تضمّ فنادقها بسعر واحد */
    public const PRICING_MODES = [
        'city' => 'مستقل لكل مدينة',
        'bundle' => 'باقات جاهزة',
    ];

    /** حالات الفاتورة التي تحجز مقاعد */
    public const SEAT_STATUSES = ['pending', 'editing', 'approved'];

    protected $fillable = [
        'title', 'category', 'route_mode', 'description_client',
        'includes', 'excludes', 'notes_public', 'requirements',
        'valid_from', 'valid_to',
        'nights', 'airline',
        'visa_validity', 'visa_entries', 'visa_processing', 'price_per_person',
        'is_active', 'is_bot_visible', 'sort_order', 'created_by',
        'hero_path', 'card_path', 'card_generated_at', 'custom_card_path',
        'is_ready', 'trip_date', 'return_date', 'travel_mode', 'destination', 'total_seats',
        'base_sell_pp', 'pricing_mode', 'settlement_status',
        // E38: بطاقة الحالة 9:16 والرمز المطبوع على بطاقة A4
        'story_card_path', 'story_generated_at', 'card_qr_link_id',
    ];

    protected $casts = [
        'includes' => 'array',
        'excludes' => 'array',
        'valid_from' => 'date',
        'valid_to' => 'date',
        'trip_date' => 'date',
        'return_date' => 'date',
        'is_active' => 'boolean',
        'is_bot_visible' => 'boolean',
        'is_ready' => 'boolean',
        'total_seats' => 'integer',
        'base_sell_pp' => 'float',
        'card_generated_at' => 'datetime',
        'story_generated_at' => 'datetime',
        'card_qr_link_id' => 'integer',
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

    public function isClosed(): bool
    {
        return $this->settlement_status === 'approved';
    }

    // ==================== العلاقات ====================

    /** كل خيارات العرض (فندقية وغيرها) — للتوافق مع الكود القديم */
    public function options(): HasMany
    {
        return $this->hasMany(OfferOption::class)->orderBy('sort_order')->orderBy('id');
    }

    /** مجموعات الاختيار: كل واحدة قائمة منسدلة في فاتورة العرض */
    public function groups(): HasMany
    {
        return $this->hasMany(OfferGroup::class)->orderBy('sort_order')->orderBy('id');
    }

    /** تكاليف كانت تُخزَّن على العرض قبل نقلها إلى الفاتورة — باقية في القاعدة ولا تُقرأ */
    public function costs(): HasMany
    {
        return $this->hasMany(OfferCost::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function settlement(): HasOne
    {
        return $this->hasOne(OfferSettlement::class);
    }

    // ==================== النطاقات ====================

    /**
     * العروض المسموح بظهورها للبوت.
     * الشرطان الأخيران نفسهما يحجبان العرض في فاتورة العرض، فلا يعرض البوت ما لا يُباع.
     */
    public function scopeForBot($q)
    {
        $today = now()->toDateString();

        return $q->where('is_active', true)
            ->where('is_bot_visible', true)
            // whereDate: التاريخ مخزّن «2026-10-12 00:00:00»، ومقارنته نصاً بـ «2026-10-12» كانت تُخفي
            // العرض الذي يبدأ اليوم حتى الغد
            ->where(function ($w) use ($today) {
                $w->whereNull('valid_to')->orWhereDate('valid_to', '>=', $today);
            })
            ->where(function ($w) use ($today) {
                $w->whereNull('valid_from')->orWhereDate('valid_from', '<=', $today);
            })
            ->where(function ($w) {
                $w->whereNull('settlement_status')->orWhere('settlement_status', '!=', 'approved');
            })
            ->where(function ($w) use ($today) {
                $w->where('is_ready', false)->orWhere(function ($r) use ($today) {
                    $r->whereDate('trip_date', '>', $today)
                        ->whereRaw(
                            'total_seats > (select coalesce(sum(i.offer_seats), 0) from invoices i'
                            . ' where i.offer_id = offers.id and i.status in (?, ?, ?) and i.deleted_at is null)',
                            self::SEAT_STATUSES
                        );
                });
            });
    }

    /** العروض الجاهزة — تصفية للجدول نفسه */
    public function scopeReady($q)
    {
        return $q->where('is_ready', true);
    }

    // ==================== المقاعد ====================

    /** مقاعد الفواتير الحيّة: المعلّقة وتحت التعديل والمعتمدة */
    public function bookedSeats(?int $ignoreInvoiceId = null): int
    {
        return (int) Invoice::where('offer_id', $this->id)
            ->whereIn('status', self::SEAT_STATUSES)
            ->when($ignoreInvoiceId, fn ($q) => $q->where('id', '!=', $ignoreInvoiceId))
            ->sum('offer_seats');
    }

    /** المتاح = الكلي − المحجوز. null للعرض بلا حدّ مقاعد */
    public function availableSeats(?int $ignoreInvoiceId = null): ?int
    {
        return $this->total_seats ? $this->total_seats - $this->bookedSeats($ignoreInvoiceId) : null;
    }

    /** ما يقوله البوت عن التوفّر — بلا رقم دقيق */
    public function availabilityLabel(): ?string
    {
        $a = $this->availableSeats();
        if ($a === null) {
            return null;
        }
        if ($a <= 0) {
            return 'مكتمل';
        }

        return $a <= max(3, (int) ceil($this->total_seats * 0.15)) ? 'مقاعد محدودة' : 'متوفر';
    }

    // ==================== السعر ====================

    /** السعر الأساسي للفرد: سعر التأشيرة، أو الأساسي الذي يُضاف لأسعار الخيارات */
    public function basePrice(): float
    {
        return $this->isVisa() ? (float) ($this->price_per_person ?? 0) : (float) ($this->base_sell_pp ?? 0);
    }

    /**
     * خيارات البيع كما يراها العميل — إسقاط واحد يقرؤه البوت والبطاقة و«يبدأ من».
     *
     * نمط الباقات: خيارات مجموعة الإقامة كما هي وبترتيبها.
     * نمط المدن: كل تركيبة من فندق في كل مدينة، بسعر مجموعها، مرتّبة بالأرخص.
     * السعر = الأساسي + أسعار الفنادق المختارة + الخيار الافتراضي لكل مجموعة غير فندقية.
     *
     * @return Collection<int, SellOption>
     */
    public function sellOptions(): Collection
    {
        if ($this->isVisa()) {
            return collect();
        }

        $this->loadMissing('groups.options.stays');
        $rooms = array_keys(OfferOption::ROOM_TYPES);

        $defaults = [];
        $defaultFlat = 0.0;
        foreach ($this->groups->reject->isStay() as $g) {
            $first = $g->activeOptions()->first();
            if ($first) {
                $defaults[$g->id] = $first->id;
                $defaultFlat += (float) $first->sell_flat;
            }
        }

        $stayGroups = $this->groups->filter->isStay()->values();
        if ($stayGroups->isEmpty()) {
            return collect();
        }

        $start = round($this->basePrice() + $defaultFlat, 3);
        $acc = [[
            'sel' => $defaults,
            'stays' => collect(),
            'notes' => [],
            'labels' => [],
            'prices' => array_fill_keys($rooms, $start),
        ]];

        foreach ($stayGroups as $g) {
            $next = [];
            foreach ($acc as $c) {
                foreach ($g->activeOptions() as $opt) {
                    $prices = [];
                    foreach ($rooms as $room) {
                        $s = $opt->sellFor($room);
                        $prices[$room] = ($c['prices'][$room] !== null && $s !== null) ? round($c['prices'][$room] + $s, 3) : null;
                    }
                    $stays = $opt->stays->map(function ($s) use ($g) {
                        if ($this->pricing_mode === 'city' && $g->city) {
                            $s = clone $s;
                            $s->city = $g->city;
                        }

                        return $s;
                    });
                    $next[] = [
                        'sel' => $c['sel'] + [$g->id => $opt->id],
                        'stays' => $c['stays']->concat($stays),
                        'notes' => array_values(array_filter([...$c['notes'], $opt->includes_note])),
                        'labels' => array_values(array_filter([...$c['labels'], $opt->label])),
                        'prices' => $prices,
                    ];
                }
            }
            $acc = array_slice($next, 0, 48);
        }

        $out = collect($acc)
            ->filter(fn ($c) => collect($c['prices'])->filter(fn ($v) => $v !== null && $v > 0)->isNotEmpty())
            ->map(fn ($c) => new SellOption(
                ref: implode('+', array_map(fn ($g) => $c['sel'][$g->id], $stayGroups->all())),
                sel: $c['sel'],
                prices: $c['prices'],
                stays: $c['stays']->values(),
                includes_note: $c['notes'] ? implode('، ', $c['notes']) : null,
                label: $c['labels'] ? implode(' + ', $c['labels']) : null,
            ))
            ->values();

        if ($stayGroups->count() > 1) {
            $out = $out->sortBy(fn ($o) => $o->minPrice() ?? INF)->values();
        }

        return $out->take(12)->values();
    }

    /** الإضافات: بدائل الخيار الافتراضي في المجموعات غير الفندقية (باص VIP +30) */
    public function supplements(): array
    {
        if ($this->isVisa()) {
            return [];
        }
        $this->loadMissing('groups.options');

        $out = [];
        foreach ($this->groups->reject->isStay() as $g) {
            $active = $g->activeOptions();
            if ($active->count() < 2) {
                continue;
            }
            $default = $active->first();
            foreach ($active->slice(1) as $opt) {
                $out[] = [
                    'ref' => (string) $opt->id,
                    'group' => $g->label,
                    'option' => $opt->label,
                    'instead_of' => $default->label,
                    'price_per_person_jod' => round((float) $opt->sell_flat - (float) $default->sell_flat, 3),
                ];
            }
        }

        return $out;
    }

    /** «يبدأ من» — سعر التأشيرة، أو أقل سعر للفرد في خيارات البيع */
    public function priceFrom(): ?float
    {
        if ($this->isVisa()) {
            return $this->price_per_person !== null && (float) $this->price_per_person > 0 ? (float) $this->price_per_person : null;
        }

        $mins = $this->sellOptions()->map->minPrice()->filter()->all();

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

        return $this->touchedAfter($this->card_generated_at);
    }

    /** بطاقة الحالة 9:16 (E38): غير مولّدة، أو أقدم من آخر تعديل على العرض أو خياراته (الفحص نفسه) */
    public function storyIsStale(): bool
    {
        if (!$this->story_card_path || !$this->story_generated_at) {
            return true;
        }

        return $this->touchedAfter($this->story_generated_at);
    }

    /** آخر تعديل على العرض أو خياراته أو إقاماته أو مجموعاته */
    private function touchedAt(): ?\Carbon\CarbonInterface
    {
        $this->loadMissing(['options.stays', 'groups']);

        return collect([
            $this->updated_at,
            $this->options->max('updated_at'),
            $this->options->flatMap->stays->max('updated_at'),
            $this->groups->max('updated_at'),
        ])->filter()->max();
    }

    private function touchedAfter(\Carbon\CarbonInterface $generatedAt): bool
    {
        $touched = $this->touchedAt();

        return $touched !== null && $generatedAt->lt($touched);
    }

    /** الرمز المطبوع على بطاقة A4 (E38) */
    public function cardQrLink(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(WaQrLink::class, 'card_qr_link_id');
    }

    /** مدن أعمدة البطاقة: مسار العمرة، أو مدن مجموعات الفنادق في نمط المدن */
    public function cardCities(): array
    {
        if ($this->isMultiCity()) {
            return $this->cities();
        }
        $this->loadMissing('groups');
        if ($this->pricing_mode === 'city') {
            $cities = $this->groups->filter->isStay()->pluck('city')->filter()->unique()->values()->all();

            return count($cities) > 1 ? $cities : [];
        }

        return [];
    }

    // ==================== البوت ====================

    /** شروط ظهور العرض للبوت — للعرض في صفحة العرض (الشرط نفسه في scopeForBot) */
    public function forBotChecks(): array
    {
        $today = now()->toDateString();
        $rows = [
            ['label' => 'العرض فعّال', 'ok' => (bool) $this->is_active, 'code' => 'is_active'],
            ['label' => 'ظاهر للبوت', 'ok' => (bool) $this->is_bot_visible, 'code' => 'is_bot_visible'],
            ['label' => 'بدأت مدة العرض', 'ok' => !$this->valid_from || $this->valid_from->toDateString() <= $today, 'code' => 'valid_from <= today'],
            ['label' => 'لم تنتهِ مدة العرض', 'ok' => !$this->valid_to || $this->valid_to->toDateString() >= $today, 'code' => 'valid_to >= today'],
            ['label' => 'غير مُقفل', 'ok' => !$this->isClosed(), 'code' => 'settlement_status'],
        ];
        if ($this->is_ready) {
            $a = $this->availableSeats();
            $rows[] = ['label' => 'لم تُقلع الرحلة', 'ok' => $this->trip_date && $this->trip_date->toDateString() > $today, 'code' => 'trip_date > today'];
            $rows[] = ['label' => 'فيه مقاعد متاحة', 'ok' => $a !== null && $a > 0, 'code' => 'total_seats > Σ offer_seats'];
        }

        return $rows;
    }

    /**
     * ما يُعاد للبوت. لا يوجد أي حقل داخلي (وكيل، تكلفة، عدد مقاعد) إطلاقاً،
     * والحمولة تتبع شكل التصنيف فلا تُرسل حقولاً لا معنى لها.
     */
    /**
     * ملخص العرض في قائمة get_offers (E15.7): قائمة بيضاء مضغوطة. الخيارات والإقامات والإضافات في
     * get_offer_details وحده، فلا تُقصّ قائمة طويلة فيظن النموذج عرضاً موجوداً غير متاح.
     */
    public function toBotSummary(): array
    {
        $isVisa = $this->isVisa();

        return array_filter([
            'id' => $this->id,
            'title' => $this->title,
            'category' => $this->categoryLabel(),
            'price_from_per_person_jod' => $this->priceFrom(),
            'trip_date' => $this->is_ready ? $this->trip_date?->toDateString() : null,
            'nights' => $isVisa ? null : $this->nights,
            'availability' => $this->is_ready ? $this->availabilityLabel() : null,
            'pricing_note' => $isVisa ? 'السعر للفرد الواحد.' : $this->pricingNote($this->sellOptions(), $this->supplements()),
        ], fn ($v) => $v !== null && $v !== '');
    }

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

        $keep = fn ($v) => $v !== null && $v !== '';

        // العرض الجاهز: التاريخ والوسيلة والوجهة والتوفّر التقريبي — لا عدد مقاعد
        $ready = $this->is_ready ? array_filter([
            'trip_date' => $this->trip_date?->toDateString(),
            'return_date' => $this->return_date?->toDateString(),
            'travel_mode' => self::TRAVEL_MODES[$this->travel_mode] ?? null,
            'destination' => $this->destination,
            'availability' => $this->availabilityLabel(),
        ], $keep) : [];

        if ($this->isVisa()) {
            return array_merge($base, $ready, array_filter([
                'requirements' => $this->requirements,
                'visa_validity' => $this->visa_validity,
                'visa_entries' => $this->visa_entries,
                'processing_time' => $this->visa_processing,
                'price_per_person_jod' => $this->priceFrom(),
                'pricing_note' => 'السعر للفرد الواحد.',
            ], $keep));
        }

        $options = $this->sellOptions();
        $supplements = $this->supplements();

        return array_merge($base, $ready, array_filter([
            'nights' => $this->nights,
            'airline' => $this->airline,
            'route' => self::ROUTE_MODES[$this->route_mode] ?? null,
            'price_from_per_person_jod' => $this->priceFrom(),
            'pricing_note' => $this->pricingNote($options, $supplements),
            'options' => $options->map(fn ($o) => $o->toBotArray($this->usesHaramDistance()))->values()->all(),
            'supplements' => $supplements ? array_map(fn ($s) => [
                'ref' => $s['ref'],
                'group' => $s['group'],
                'option' => $s['option'],
                'price_per_person_jod' => $s['price_per_person_jod'],
            ], $supplements) : null,
        ], $keep));
    }

    /** ملاحظة الأسعار للبوت — نصّ اليوم كما هو ما لم يكن في العرض نقل افتراضي أو إضافات */
    private function pricingNote(Collection $options, array $supplements): string
    {
        $multi = $this->isMultiCity()
            || $this->groups->filter->isStay()->count() > 1
            || $options->contains(fn ($o) => $o->stays->count() > 1);

        $defaults = [];
        foreach ($this->groups->reject->isStay() as $g) {
            $first = $g->activeOptions()->first();
            if ($first && $first->label) {
                $defaults[] = $first->label;
            }
        }

        if (!$defaults && !$supplements) {
            return $multi
                ? 'الأسعار للفرد الواحد وتشمل الإقامة في الفندقين معاً، وتختلف حسب سعة الغرفة.'
                : 'الأسعار للفرد الواحد وتختلف حسب سعة الغرفة والفندق.';
        }

        $note = $multi ? 'الأسعار للفرد الواحد وتشمل الإقامة في الفندقين معاً' : 'الأسعار للفرد الواحد';
        if ($defaults) {
            $note .= ' و' . implode(' و', $defaults);
        }
        $note .= $multi ? '، وتختلف حسب سعة الغرفة.' : '، وتختلف حسب سعة الغرفة والفندق.';
        foreach ($supplements as $s) {
            $note .= " {$s['option']} بدل {$s['instead_of']} بفرق " . rtrim(rtrim(number_format($s['price_per_person_jod'], 3, '.', ''), '0'), '.') . ' د.أ للفرد.';
        }

        return $note;
    }
}
