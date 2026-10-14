<?php

namespace App\Services\Security;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * من يحق له إسناد أي دور، ومن يحق له إدارة أي مستخدم.
 *
 * - المدير يسند أي دور ويدير أي مستخدم.
 * - غيره لا يسند دور المدير، ولا دوراً فيه صلاحية حساسة، ولا دوراً أوسع من صلاحياته هو.
 * - وغيره لا يدير مديراً ولا مستخدماً أقوى منه.
 */
class RoleAssignmentGuard
{
    public static function canAssign(User $actor, Role $role): bool
    {
        if ($actor->isAdmin()) {
            return true;
        }
        if ($role->slug === 'admin') {
            return false;
        }

        $rolePerms = self::slugs($role);

        if ($rolePerms->intersect(Permission::SENSITIVE)->isNotEmpty()) {
            return false;
        }

        return $rolePerms->diff(self::slugs($actor->role))->isEmpty();
    }

    public static function canManageUser(User $actor, User $target): bool
    {
        if ($actor->isAdmin()) {
            return true;
        }
        if ($target->isAdmin()) {
            return false;
        }

        return self::slugs($target->role)->diff(self::slugs($actor->role))->isEmpty();
    }

    /** الأدوار التي يحق للمستخدم إسنادها، بالحقلين id و name. */
    public static function assignableRoles(User $actor): Collection
    {
        return Role::with('permissions:id,slug')->orderBy('id')->get()
            ->filter(fn (Role $role) => self::canAssign($actor, $role))
            ->map(fn (Role $role) => ['id' => $role->id, 'name' => $role->name])
            ->values();
    }

    private static function slugs(?Role $role): Collection
    {
        if (!$role) {
            return collect();
        }

        return $role->relationLoaded('permissions')
            ? $role->permissions->pluck('slug')
            : $role->permissions()->pluck('slug');
    }
}
