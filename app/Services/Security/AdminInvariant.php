<?php

namespace App\Services\Security;

use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * لا يبقى النظام بلا مدير نشط.
 *
 * الشرط يُفحص داخل عبارة التحديث أو الحذف نفسها، فلا يستطيع طلبان متزامنان
 * أن يُسقطا آخر مديرَين معاً. القيمة false تعني أن التغيير رُفض.
 */
class AdminInvariant
{
    public const BLOCKED_MESSAGE = 'لا يمكن ترك النظام بلا مدير نشط';

    public static function adminRoleId(): ?int
    {
        return Role::where('slug', 'admin')->value('id');
    }

    /** هل يُسقط هذا التغيير صفة «مدير نشط» عن المستخدم؟ */
    public static function dropsAdmin(User $target, ?int $newRoleId, ?bool $newActive): bool
    {
        $adminId = self::adminRoleId();

        if (! $adminId || (int) $target->role_id !== $adminId || ! $target->is_active) {
            return false;
        }

        return ($newRoleId !== null && $newRoleId !== $adminId) || $newActive === false;
    }

    /** تحديث أعمدة المستخدم بشرط بقاء مدير نشط آخر إن كان هو مديراً نشطاً. */
    public static function guardedUpdate(User $target, array $columns): bool
    {
        $affected = self::guardedQuery($target)->update($columns + ['updated_at' => now()]);

        if ($affected > 0) {
            $target->refresh();
        }

        return $affected > 0;
    }

    public static function guardedDelete(User $target): bool
    {
        return self::guardedQuery($target)->delete() > 0;
    }

    private static function guardedQuery(User $target)
    {
        $adminId = self::adminRoleId();
        $query = DB::table('users')->where('id', $target->id);

        if (! $adminId) {
            return $query;
        }

        // الأقواس الخارجية إلزامية حتى لا يُدمج الشرط مع where('id') بـ OR.
        return $query->whereRaw(
            '(NOT (role_id = ? AND is_active = 1) OR EXISTS (SELECT 1 FROM users o WHERE o.id <> ? AND o.role_id = ? AND o.is_active = 1))',
            [$adminId, $target->id, $adminId],
        );
    }
}
