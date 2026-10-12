<?php

namespace App\Models;

use App\Services\WhatsApp\PhoneNormalizer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * رقم واتساب لموظف إدارة.
 * المحادثة من هذا الرقم تُخدم بالمساعد الإداري بدل بوت العملاء،
 * ولا يرى المساعد إلا الأدوات المسموحة في permissions.
 *
 * الهويّة من رقم المرسِل في ويبهوك ميتا الموقّع — لا يستطيع أحد انتحالها من الدردشة.
 */
class WaAdminNumber extends Model
{
    protected $table = 'wa_admin_numbers';

    protected $fillable = [
        'phone', 'name', 'user_id', 'permissions', 'is_active', 'notes', 'last_used_at', 'created_by',
    ];

    protected $casts = [
        'permissions' => 'array',
        'is_active' => 'boolean',
        'last_used_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** الرقم الإداري الفعّال لرقم محادثة، إن وُجد */
    public static function forPhone(?string $phone): ?self
    {
        $canonical = PhoneNormalizer::canonical($phone);
        if ($canonical === '') {
            return null;
        }

        try {
            return self::where('phone', $canonical)->where('is_active', true)->first();
        } catch (\Throwable $e) {
            return null;   // قبل تشغيل الهجرة — لا أرقام إدارة بعد
        }
    }

    public function allows(string $permission): bool
    {
        return in_array($permission, $this->permissions ?? [], true);
    }

    /** هل يملك أيّاً من هذه الصلاحيات؟ */
    public function allowsAny(array $permissions): bool
    {
        return (bool) array_intersect($permissions, $this->permissions ?? []);
    }
}
