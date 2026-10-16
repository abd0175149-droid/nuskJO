<?php

namespace App\Models;

use App\Models\Concerns\HasApiTokens;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laragear\WebAuthn\WebAuthnAuthentication;
use Laragear\WebAuthn\Contracts\WebAuthnAuthenticatable;

class User extends Authenticatable implements WebAuthnAuthenticatable
{
    /** @use HasFactory<UserFactory> */
    // HasApiTokens (E40): رموز خادم MCP للقراءة، بلا مساس بـ can() وGate::before
    use HasApiTokens, HasFactory, Notifiable, WebAuthnAuthentication;

    // role_id و is_active ليسا هنا عمداً: يُضبطان بـ forceFill بعد فحص الصلاحية
    // (RoleAssignmentGuard و AdminInvariant)، فلا يغيّرهما أي ملء جماعي من طلب.
    protected $fillable = [
        'name', 'email', 'password', 'phone',
        'avatar', 'locale', 'theme', 'last_login_at',
    ];

    // أعمدة العامل الثاني لا تغادر الخادم أبداً (UserController::index يمرر النماذج إلى Inertia)، وآخر عنوان دخول
    // بيانات شخصية للموظف لا يراها إلا من يملك users.update (حقل محسوب في القائمة)
    protected $hidden = [
        'password', 'remember_token',
        'two_factor_secret', 'two_factor_recovery_codes', 'two_factor_last_step', 'two_factor_confirmed_at', 'last_login_ip',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'last_login_at' => 'datetime',
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
            'two_factor_confirmed_at' => 'datetime',
            'two_factor_last_step' => 'integer',
            'last_seen_at' => 'datetime',
        ];
    }

    /** سجّل تطبيق مصادقة وأكّده (E27) */
    public function hasTwoFactor(): bool
    {
        return $this->two_factor_confirmed_at !== null;
    }

    /** عامل ثانٍ صالح لتحدّي الدخول والتحقق المعزّز. مفاتيح المرور مؤجلة (قرار المالك)، فهو TOTP وحده اليوم */
    public function hasSecondFactor(): bool
    {
        return $this->hasTwoFactor();
    }

    /** المدير، أو من يملك أيّ صلاحية حساسة (config/security.php)، يُطلب منه العامل الثاني حين يُفرض */
    public function requiresMfa(): bool
    {
        if ($this->isAdmin()) {
            return true;
        }

        return (bool) array_intersect($this->permissionSlugs(), (array) config('security.mfa.sensitive_permissions', []));
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function fcmTokens()
    {
        return $this->hasMany(FcmToken::class);
    }

    /** صلاحيات الدور بمعرّفاتها النصية، تُحمَّل مرة لكل دور في الطلب (Gate::before يقرؤها لكل فحص) */
    private ?array $permissionSlugsCache = null;

    public function permissionSlugs(): array
    {
        if (($this->permissionSlugsCache['role_id'] ?? false) !== $this->role_id) {
            $this->permissionSlugsCache = [
                'role_id' => $this->role_id,
                'slugs' => $this->role?->permissions->pluck('slug')->all() ?? [],
            ];
        }

        return $this->permissionSlugsCache['slugs'];
    }

    public function isAdmin(): bool
    {
        return $this->role?->slug === 'admin';
    }

    public function isSales(): bool
    {
        return $this->role?->slug === 'sales';
    }

    public function isAccountant(): bool
    {
        return $this->role?->slug === 'accountant';
    }

    public function isHR(): bool
    {
        return $this->role?->slug === 'hr_manager';
    }

    public function employee()
    {
        return $this->hasOne(Employee::class);
    }

    public function isEmployee(): bool
    {
        return $this->employee !== null;
    }
}
