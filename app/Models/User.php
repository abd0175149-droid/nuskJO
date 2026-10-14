<?php

namespace App\Models;

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
    use HasFactory, Notifiable, WebAuthnAuthentication;

    // role_id و is_active ليسا هنا عمداً: يُضبطان بـ forceFill بعد فحص الصلاحية
    // (RoleAssignmentGuard و AdminInvariant)، فلا يغيّرهما أي ملء جماعي من طلب.
    protected $fillable = [
        'name', 'email', 'password', 'phone',
        'avatar', 'locale', 'theme', 'last_login_at',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'last_login_at' => 'datetime',
        ];
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
