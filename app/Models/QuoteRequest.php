<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuoteRequest extends Model
{
    protected $fillable = [
        'request_number', 'conversation_id', 'client_id', 'offer_id', 'phone', 'customer_name',
        'type', 'route_from', 'route_to', 'depart_date', 'return_date',
        'pax_adults', 'pax_children', 'pax_infants', 'details', 'status',
        'quoted_price_jod', 'quoted_note', 'quoted_by', 'quoted_at', 'assigned_to',
    ];

    protected $casts = [
        'depart_date' => 'date',
        'return_date' => 'date',
        'quoted_at' => 'datetime',
        'quoted_price_jod' => 'decimal:3',
    ];

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
