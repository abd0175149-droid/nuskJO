<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * رمز QR ورابط قصير رسمي من message_qrdls (E38). offer_id = null رمز عام (ملصق المكتب، صفحات التواصل).
 * الرسالة المعبأة يبنيها OfferQrService من عنوان العرض العام والمرجع وحدهما، وتُحفظ كما أعادتها ميتا.
 *
 * status: pending (قبل رد ميتا) · active · failed · deleted.
 */
class WaQrLink extends Model
{
    /** حد ميتا للرموز والروابط لكل رقم، والتنبيه قبله */
    public const MAX_PER_NUMBER = 2000;

    public const WARN_AT = 1800;

    /** قنوات رموز العروض؛ office للرموز العامة */
    public const CHANNELS = ['print' => 'طباعة', 'status' => 'حالة واتساب', 'social' => 'منشور', 'ad' => 'إعلان', 'office' => 'ملصق المكتب'];

    public const OFFER_CHANNELS = ['print', 'status', 'social', 'ad'];

    public const GENERAL_CHANNELS = ['office', 'social', 'print', 'ad'];

    public const STATUSES = ['pending', 'active', 'failed', 'deleted'];

    protected $fillable = [
        'offer_id', 'channel', 'label', 'code', 'prefilled_message', 'deep_link_url',
        'qr_png_path', 'qr_svg_path', 'token_ok', 'status', 'last_error', 'created_by',
    ];

    protected $casts = ['token_ok' => 'boolean', 'offer_id' => 'integer'];

    public function offer(): BelongsTo
    {
        return $this->belongsTo(Offer::class);
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('status', 'active');
    }

    /** ما يُحسب من حد الـ2000: المعلّق والفعّال */
    public function scopeCounted(Builder $q): Builder
    {
        return $q->whereIn('status', ['pending', 'active']);
    }

    public function isGeneral(): bool
    {
        return $this->offer_id === null;
    }

    public function pngUrl(): ?string
    {
        return $this->qr_png_path ? Storage::disk('public')->url($this->qr_png_path) : null;
    }
}
