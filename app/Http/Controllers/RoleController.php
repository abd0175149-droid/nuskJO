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
 * إدارة الأدوار للمدير فقط حتى تُنشأ الصلاحية roles.manage (E03 T4).
 */
class RoleController extends Controller
{
    private function authorizeAdmin(): void
    {
        abort_unless(auth()->user()?->isAdmin(), 403);
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

        DB::transaction(function () use ($request) {
            $role = Role::create([
                'name' => $request->name,
                'slug' => Str::slug($request->name) . '-' . time(),
            ]);

            if ($request->has('permissions')) {
                $role->permissions()->sync($request->permissions);
            }

            AuditLog::log('role.create', 'Role', $role->id, $role->name, ['permissions' => $request->permissions ?? []]);
        });

        return back()->with('success', 'تم إنشاء الصلاحية بنجاح');
    }

    public function edit(Role $role)
    {
        $this->authorizeAdmin();

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

        $request->validate([
            'name' => 'required|string|max:255',
            'permissions' => 'array',
            'permissions.*' => 'exists:permissions,id',
        ]);

        DB::transaction(function () use ($request, $role) {
            $role->update([
                'name' => $request->name,
            ]);

            if ($request->has('permissions')) {
                $role->permissions()->sync($request->permissions);
            } else {
                $role->permissions()->detach();
            }

            AuditLog::log('role.update', 'Role', $role->id, $role->name, ['permissions' => $request->permissions ?? []]);
        });

        return back()->with('success', 'تم تحديث الصلاحية بنجاح');
    }

    public function destroy(Role $role)
    {
        $this->authorizeAdmin();

        if ($role->slug === 'admin') {
            return back()->with('error', 'لا يمكن حذف دور المدير العام');
        }

        if ($role->users()->count() > 0) {
            return back()->with('error', 'لا يمكن حذف هذا الدور لوجود مستخدمين مرتبطين به');
        }

        AuditLog::log('role.delete', 'Role', $role->id, $role->name);
        $role->delete();
        return back()->with('success', 'تم حذف الصلاحية');
    }
}
