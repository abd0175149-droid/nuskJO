<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Role extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'slug', 'description'];

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'role_permissions');
    }

    public function hasPermission(string $slug): bool
    {
        // العلاقة المحمّلة أصلاً (HandleInertiaRequests وGate::before) تغني عن استعلام لكل فحص
        return $this->relationLoaded('permissions')
            ? $this->permissions->contains('slug', $slug)
            : $this->permissions()->where('slug', $slug)->exists();
    }
}
