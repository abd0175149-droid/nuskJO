<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Role;
use App\Models\Permission;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Illuminate\Support\Facades\DB;

/**
 * إدارة الأدوار لمن يملك roles.manage. غير المدير لا يضيف ولا يسحب صلاحية حساسة (Permission::SENSITIVE)،
 * ولا يعدّل دور المدير ولا دوره هو، فلا يرفع صلاحياته عبر الأدوار.
 */
class RoleController extends Controller
{
    private function authorizeAdmin(): void
    {
        abort_unless(auth()->user()?->can('roles.manage'), 403);
    }

    /** غير المدير: لا دور المدير ولا دوره هو */
    private function authorizeRoleTarget(Role $role): void
    {
        $actor = auth()->user();
        if ($actor->isAdmin()) {
            return;
        }

        abort_if($role->slug === 'admin' || $role->id === $actor->role_id, 403, 'لا يمكنك تعديل هذا الدور');
    }

    /** غير المدير لا يغيّر عضوية الصلاحيات الحساسة في الدور (إضافة أو سحباً) */
    private function authorizePermissionSet(array $requested, array $current = []): void
    {
        if (auth()->user()->isAdmin()) {
            return;
        }

        $sensitive = Permission::whereIn('slug', Permission::SENSITIVE)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $before = array_values(array_intersect($sensitive, array_map('intval', $current)));
        $after = array_values(array_intersect($sensitive, array_map('intval', $requested)));
        sort($before);
        sort($after);

        abort_unless($before === $after, 403, 'الصلاحيات الحساسة يمنحها المدير وحده');
    }

    public function index()
    {
        $this->authorizeAdmin();

        $roles = Role::withCount('users')->get();
        // Group permissions by module
        $permissions = Permission::all()->groupBy('module');

        return Inertia::render('Roles/Index', [
            'roles' => $roles,
            'permissions' => $permissions,
            'sensitive' => Permission::SENSITIVE,
        ]);
    }

    public function store(Request $request)
    {
        $this->authorizeAdmin();

        $request->validate([
            'name' => 'required|string|max:255',
            'permissions' => 'array',
            'permissions.*' => 'exists:permissions,id',
        ]);
        $this->authorizePermissionSet((array) $request->input('permissions', []));

        DB::transaction(function () use ($request) {
            $role = Role::create([
                'name' => $request->name,
                'slug' => Str::slug($request->name) . '-' . time(),
            ]);

            if ($request->has('permissions')) {
                $role->permissions()->sync($request->permissions);
            }

            AuditLog::log(\App\Enums\AuditAction::RoleCreate, 'Role', $role->id, $role->name, ['permissions' => $request->permissions ?? []]);
        });

        return back()->with('success', 'تم إنشاء الصلاحية بنجاح');
    }

    public function edit(Role $role)
    {
        $this->authorizeAdmin();
        $this->authorizeRoleTarget($role);

        return response()->json([
            'role' => $role,
            'permission_ids' => $role->permissions()->pluck('permissions.id'),
        ]);
    }

    public function update(Request $request, Role $role)
    {
        $this->authorizeAdmin();

        if ($role->slug === 'admin') {
            return back()->with('error', 'لا يمكن تعديل صلاحيات المدير العام');
        }

        $this->authorizeRoleTarget($role);

        $request->validate([
            'name' => 'required|string|max:255',
            'permissions' => 'array',
            'permissions.*' => 'exists:permissions,id',
        ]);
        $this->authorizePermissionSet(
            (array) $request->input('permissions', []),
            $role->permissions()->pluck('permissions.id')->all()
        );

        DB::transaction(function () use ($request, $role) {
            $role->update([
                'name' => $request->name,
            ]);

            if ($request->has('permissions')) {
                $role->permissions()->sync($request->permissions);
            } else {
                $role->permissions()->detach();
            }

            AuditLog::log(\App\Enums\AuditAction::RoleUpdate, 'Role', $role->id, $role->name, ['permissions' => $request->permissions ?? []]);
        });

        return back()->with('success', 'تم تحديث الصلاحية بنجاح');
    }

    public function destroy(Role $role)
    {
        $this->authorizeAdmin();

        if ($role->slug === 'admin') {
            return back()->with('error', 'لا يمكن حذف دور المدير العام');
        }
        $this->authorizeRoleTarget($role);
        $this->authorizePermissionSet([], $role->permissions()->pluck('permissions.id')->all());

        if ($role->users()->count() > 0) {
            return back()->with('error', 'لا يمكن حذف هذا الدور لوجود مستخدمين مرتبطين به');
        }

        AuditLog::log(\App\Enums\AuditAction::RoleDelete, 'Role', $role->id, $role->name);
        $role->delete();
        return back()->with('success', 'تم حذف الصلاحية');
    }
}
