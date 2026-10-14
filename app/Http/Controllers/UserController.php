<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use App\Services\Security\AdminInvariant;
use App\Services\Security\RoleAssignmentGuard;
use App\Services\Security\SessionRevoker;
use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
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

    public function store(StoreUserRequest $request)
    {
        $data = $request->validated();
        $role = Role::findOrFail($data['role_id']);

        $user = new User([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'phone' => $data['phone'] ?? null,
        ]);
        DB::transaction(function () use ($user, $role, $request) {
            $user->forceFill([
                'role_id' => $role->id,
                'is_active' => $request->boolean('is_active', true),
            ])->save();
            AuditLog::log(\App\Enums\AuditAction::UserCreate, 'User', $user->id, $user->name, ['role' => $role->slug]);
        });

        return back()->with('success', 'تم إضافة الموظف بنجاح');
    }

    public function update(UpdateUserRequest $request, User $user)
    {
        $actor = auth()->user();
        $validated = $request->validated();

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

        $applied = DB::transaction(function () use ($user, $validated, $newRoleId, $newActive, $roleChanged, $activeChanged, $before) {
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

            $data = array_intersect_key($validated, array_flip(['name', 'email', 'phone']));
            if (!empty($validated['password'])) {
                $data['password'] = Hash::make($validated['password']);
            }
            $user->update($data);

            // صفوف التدقيق في المعاملة نفسها: لا تغيير صلاحيات بلا أثر. أسماء الحقول فقط، والهاتف بلا قيمة
            if ($roleChanged) {
                AuditLog::log(\App\Enums\AuditAction::UserRoleChange, 'User', $user->id, $user->name, [
                    'from' => $before['role_id'], 'to' => $newRoleId,
                    'from_role' => Role::find($before['role_id'])?->slug, 'to_role' => Role::find($newRoleId)?->slug,
                ]);
            }
            if ($activeChanged) {
                AuditLog::log($newActive ? \App\Enums\AuditAction::UserActivate : \App\Enums\AuditAction::UserDeactivate, 'User', $user->id, $user->name, ['from' => $before['is_active'], 'to' => $newActive]);
            }
            if (!empty($validated['password'])) {
                AuditLog::log(\App\Enums\AuditAction::UserPasswordReset, 'User', $user->id, $user->name);
            }
            $fields = array_values(array_intersect(array_keys($user->getChanges()), ['name', 'email', 'phone']));
            if ($fields) {
                AuditLog::log(\App\Enums\AuditAction::UserUpdate, 'User', $user->id, $user->name, ['fields' => $fields]);
            }

            return true;
        });

        if (! $applied) {
            return $this->invariantBlocked($request);
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
            'password' => ['required', 'string', \Illuminate\Validation\Rules\Password::min(8), 'confirmed'],
        ]);

        DB::transaction(function () use ($user, $request) {
            $user->update(['password' => Hash::make($request->password)]);
            AuditLog::log(\App\Enums\AuditAction::UserPasswordReset, 'User', $user->id, $user->name, ['target_is_admin' => $user->isAdmin()]);
        });
        SessionRevoker::revoke($user);

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

        $deleted = DB::transaction(function () use ($user, $label) {
            if (AdminInvariant::dropsAdmin($user, null, false)) {
                if (! AdminInvariant::guardedDelete($user)) {
                    return false;
                }
            } else {
                $user->delete();
            }
            AuditLog::log(\App\Enums\AuditAction::UserDelete, 'User', $user->id, $label);

            return true;
        });
        if (! $deleted) {
            return $this->invariantBlocked($request);
        }

        SessionRevoker::revokeDeleted($user->id);

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
