<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use App\Services\Security\AdminInvariant;
use App\Services\Security\RoleAssignmentGuard;
use App\Services\Security\SessionRevoker;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class UserController extends Controller
{
    public function index(Request $request)
    {
        abort_unless(auth()->user()->can('users.view'), 403);

        $query = User::with('role')->latest();

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%")
                  ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        if ($request->filled('role_id')) {
            $query->where('role_id', $request->role_id);
        }

        $users = $query->paginate(15)->withQueryString();
        $roles = Role::select('id', 'name')->get();

        return Inertia::render('Users/Index', [
            'users' => $users,
            'roles' => $roles,
            'assignableRoles' => RoleAssignmentGuard::assignableRoles(auth()->user()),
            'filters' => $request->only(['search', 'role_id']),
        ]);
    }

    public function store(Request $request)
    {
        $actor = auth()->user();
        abort_unless($actor->can('users.create'), 403);

        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:8',
            'role_id' => 'required|exists:roles,id',
            'phone' => 'nullable|string|max:20',
            'is_active' => 'boolean',
        ]);

        $role = Role::findOrFail($request->role_id);
        abort_unless(RoleAssignmentGuard::canAssign($actor, $role), 403, 'لا تملك صلاحية إسناد هذا الدور');

        $user = new User([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'phone' => $request->phone,
        ]);
        $user->forceFill([
            'role_id' => $role->id,
            'is_active' => $request->boolean('is_active', true),
        ])->save();

        AuditLog::log('user.create', 'User', $user->id, $user->name, ['role' => $role->slug]);

        return back()->with('success', 'تم إضافة الموظف بنجاح');
    }

    public function update(Request $request, User $user)
    {
        $actor = auth()->user();
        abort_unless($actor->can('users.update'), 403);
        abort_unless(RoleAssignmentGuard::canManageUser($actor, $user), 403, 'لا تملك صلاحية تعديل هذا المستخدم');

        $rules = [
            'name' => 'required|string|max:255',
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users')->ignore($user->id)],
            'role_id' => 'required|exists:roles,id',
            'phone' => 'nullable|string|max:20',
            'is_active' => 'boolean',
        ];

        if ($request->filled('password')) {
            abort_unless($actor->isAdmin(), 403, 'تغيير كلمة المرور للمدير فقط');
            $rules['password'] = 'string|min:8';
        }

        $request->validate($rules);

        $newRoleId = (int) $request->role_id;
        $newActive = $request->has('is_active') ? $request->boolean('is_active') : (bool) $user->is_active;
        $roleChanged = $newRoleId !== (int) $user->role_id;
        $activeChanged = $newActive !== (bool) $user->is_active;

        if ($user->id === $actor->id && ($roleChanged || $activeChanged)) {
            throw ValidationException::withMessages([
                'role_id' => 'لا يمكنك تغيير دورك أو تعطيل حسابك بنفسك',
            ]);
        }

        if ($roleChanged) {
            $role = Role::findOrFail($newRoleId);
            abort_unless(RoleAssignmentGuard::canAssign($actor, $role), 403, 'لا تملك صلاحية إسناد هذا الدور');
        }

        $before = ['role_id' => $user->role_id, 'is_active' => (bool) $user->is_active];

        $applied = DB::transaction(function () use ($user, $request, $newRoleId, $newActive, $roleChanged, $activeChanged) {
            if ($roleChanged || $activeChanged) {
                $columns = ['role_id' => $newRoleId, 'is_active' => $newActive];

                if (AdminInvariant::dropsAdmin($user, $newRoleId, $newActive)) {
                    if (! AdminInvariant::guardedUpdate($user, $columns)) {
                        return false;
                    }
                } else {
                    $user->forceFill($columns)->save();
                }
            }

            $data = $request->only(['name', 'email', 'phone']);
            if ($request->filled('password')) {
                $data['password'] = Hash::make($request->password);
            }
            $user->update($data);

            return true;
        });

        if (! $applied) {
            return $this->invariantBlocked($request);
        }

        if ($roleChanged) {
            AuditLog::log('user.role_change', 'User', $user->id, $user->name, ['from' => $before['role_id'], 'to' => $newRoleId]);
        }
        if ($activeChanged) {
            AuditLog::log($newActive ? 'user.activate' : 'user.deactivate', 'User', $user->id, $user->name, ['from' => $before['is_active'], 'to' => $newActive]);
        }
        if ($request->filled('password')) {
            AuditLog::log('user.password_reset', 'User', $user->id, $user->name);
        }
        if (($activeChanged && ! $newActive) || $request->filled('password')) {
            SessionRevoker::revoke($user);
        }

        return back()->with('success', 'تم تحديث بيانات الموظف بنجاح');
    }

    /**
     * إعادة تعيين كلمة مرور مستخدم من قبل المدير (دون معرفة القديمة)
     */
    public function resetPassword(Request $request, User $user)
    {
        abort_unless(auth()->user()->isAdmin(), 403);

        $request->validate([
            'password' => 'required|string|min:8|confirmed',
        ]);

        $user->update(['password' => Hash::make($request->password)]);
        SessionRevoker::revoke($user);
        AuditLog::log('user.password_reset', 'User', $user->id, $user->name);

        return back()->with('success', "تمت إعادة تعيين كلمة مرور {$user->name} بنجاح");
    }

    public function destroy(Request $request, User $user)
    {
        $actor = auth()->user();
        abort_unless($actor->can('users.delete'), 403);

        if ($user->id === $actor->id) {
            return back()->with('error', 'لا يمكنك حذف حسابك الخاص');
        }

        abort_unless(RoleAssignmentGuard::canManageUser($actor, $user), 403, 'لا تملك صلاحية حذف هذا المستخدم');

        $label = $user->name;

        if (AdminInvariant::dropsAdmin($user, null, false)) {
            if (! AdminInvariant::guardedDelete($user)) {
                return $this->invariantBlocked($request);
            }
        } else {
            $user->delete();
        }

        SessionRevoker::revokeDeleted($user->id);

        AuditLog::log('user.delete', 'User', $user->id, $label);

        return back()->with('success', 'تم حذف حساب الموظف');
    }

    private function invariantBlocked(Request $request)
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => AdminInvariant::BLOCKED_MESSAGE], 409);
        }

        return back()->with('error', AdminInvariant::BLOCKED_MESSAGE);
    }
}
