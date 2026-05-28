<?php

namespace App\Http\Controllers;

use App\Models\Role;
use App\Models\Permission;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Illuminate\Support\Facades\DB;

class RoleController extends Controller
{
    public function index()
    {
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
        });

        return back()->with('success', 'تم إنشاء الصلاحية بنجاح');
    }

    public function edit(Role $role)
    {
        return response()->json([
            'role' => $role,
            'permission_ids' => $role->permissions()->pluck('permissions.id'),
        ]);
    }

    public function update(Request $request, Role $role)
    {
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
        });

        return back()->with('success', 'تم تحديث الصلاحية بنجاح');
    }

    public function destroy(Role $role)
    {
        if ($role->slug === 'admin') {
            return back()->with('error', 'لا يمكن حذف دور المدير العام');
        }

        if ($role->users()->count() > 0) {
            return back()->with('error', 'لا يمكن حذف هذا الدور لوجود مستخدمين مرتبطين به');
        }

        $role->delete();
        return back()->with('success', 'تم حذف الصلاحية');
    }
}
