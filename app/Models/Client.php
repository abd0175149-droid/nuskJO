<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Client extends Model
{
    use HasFactory, SoftDeletes;
    protected $fillable = [
        'name', 'code', 'phone', 'email', 'address',
        'city', 'country', 'currency', 'balance_jod', 'credit_limit_jod',
        'notes', 'is_active', 'created_by', 'agent_id', 'employee_id',
        'nationality', 'id_number', 'contact_person', 'account_id',
    ];

    protected $casts = [
        'balance_jod' => 'decimal:3',
        'credit_limit_jod' => 'decimal:3',
        'is_active' => 'boolean',
    ];

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(Receipt::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }



    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function ledgerEntries()
    {
        return LedgerEntry::where('entity_type', 'client')
            ->where('entity_id', $this->id);
    }

    /** رقم أردني بصيغة واحدة للمطابقة: +962 79… و00962… و079… كلها 079… */
    public static function normalizePhone(?string $phone): string
    {
        $d = preg_replace('/\D+/', '', (string) $phone);
        if (str_starts_with($d, '00')) {
            $d = substr($d, 2);
        }
        if (str_starts_with($d, '962')) {
            $d = '0' . substr($d, 3);
        }
        if ($d !== '' && strlen($d) === 9 && $d[0] === '7') {
            $d = '0' . $d;
        }

        return $d;
    }

    /** العميل بهذا الرقم، أياً كانت صيغة حفظه */
    public static function findByPhone(?string $phone): ?self
    {
        $n = self::normalizePhone($phone);
        if (strlen($n) < 9) {
            return null;
        }

        return self::where('phone', 'like', '%' . substr($n, -9))->get()
            ->first(fn ($c) => self::normalizePhone($c->phone) === $n);
    }

    /** رمز العميل التالي CLT-### — كما في شاشة العملاء */
    public static function nextCode(): string
    {
        $codes = self::withTrashed()->where('code', 'like', 'CLT-%')->pluck('code')->map(fn ($c) => (int) substr($c, 4));
        $next = $codes->max() ? $codes->max() + 1 : 1;

        return 'CLT-' . str_pad($next, 3, '0', STR_PAD_LEFT);
    }
}
