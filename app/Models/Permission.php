<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Permission extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'slug', 'module', 'description'];

    /**
     * صلاحيات لا يمنحها إلا المدير، ولا تُسند عبر دور يملكه غير المدير.
     * بعضها (roles.manage و accounting.*) يُنشأ لاحقاً؛ وجوده هنا مسبقاً آمن.
     */
    public const SENSITIVE = [
        'users.create', 'users.update', 'users.delete', 'roles.manage',
        'settings.update', 'accounting.manage', 'accounting.close', 'whatsapp.settings',
    ];

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'role_permissions');
    }
}
