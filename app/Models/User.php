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

    protected $fillable = [
        'name', 'email', 'password', 'role_id', 'phone',
        'avatar', 'locale', 'theme', 'is_active', 'last_login_at',
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

    public function can($ability, $arguments = []): bool
    {
        if ($this->role && $this->role->slug === 'admin') {
            return true;
        }
        if ($this->role && $this->role->hasPermission($ability)) {
            return true;
        }
        return parent::can($ability, $arguments);
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
