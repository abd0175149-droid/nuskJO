<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuoteRequest extends Model
{
    use HasFactory;

    protected $fillable = [
        'request_number', 'conversation_id', 'client_id', 'offer_id', 'phone', 'customer_name',
        'type', 'route_from', 'route_to', 'depart_date', 'return_date',
        'pax_adults', 'pax_children', 'pax_infants', 'details', 'status',
        'quoted_price_jod', 'quoted_note', 'quoted_by', 'quoted_at', 'assigned_to',
        // اختيار العميل من حمولة البوت: {sel: {group_id: option_id}, room, pax}
        'offer_selection', 'invoice_id',
    ];

    protected $casts = [
        'depart_date' => 'date',
        'return_date' => 'date',
        'quoted_at' => 'datetime',
        'quoted_price_jod' => 'decimal:3',
        'offer_selection' => 'array',
    ];

    /**
     * الطلبات التي يراها المستخدم حين يُفعَّل services.whatsapp.fence_quotes: الموكَل له، أو ما سعّره،
     * أو ما محادثته في نطاقه. ومن يملك whatsapp.view_all يرى الكل. المفتاح مطفأ: الكل كما اليوم.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if (!config('services.whatsapp.fence_quotes') || $user->can('whatsapp.view_all')) {
            return $query;
        }

        return $query->where(fn ($w) => $w->where('quote_requests.assigned_to', $user->id)
            ->orWhere('quote_requests.quoted_by', $user->id)
            ->orWhereHas('conversation', fn ($c) => $c->visibleTo($user)));
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(WaConversation::class, 'conversation_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function offer(): BelongsTo
    {
        return $this->belongsTo(Offer::class);
    }

    public function quoter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'quoted_by');
    }

    public function pax(): int
    {
        return $this->pax_adults + $this->pax_children + $this->pax_infants;
    }

    public static function nextNumber(): string
    {
        $today = now()->format('Ymd');
        $last = static::where('request_number', 'like', "QR-{$today}-%")
            ->orderByDesc('request_number')
            ->value('request_number');
        $seq = $last ? ((int) substr($last, -4)) + 1 : 1;

        return "QR-{$today}-" . str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
    }
}
