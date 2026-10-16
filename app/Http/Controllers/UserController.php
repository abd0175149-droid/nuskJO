<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use App\Services\NotificationService;
use App\Services\Security\AdminInvariant;
use App\Services\Security\Mfa;
use App\Services\Security\RoleAssignmentGuard;
use App\Services\Security\SessionRevoker;
use App\Services\Security\TwoFactor;
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

        // صلاحيات الدور محمّلة مرة: requires_mfa يقرؤها لكل صف
        $query = User::with('role.permissions:id,slug')->latest();

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

        // E27: الخاملون (آخر نشاط قبل 60 يوماً، أو لا نشاط منذ الإنشاء)، ومن يتطلب التحقق بخطوتين ولم يفعّله
        $filter = $request->input('filter');
        $idleBefore = now()->subDays((int) config('security.idle.user_days', 60));
        if ($filter === 'idle') {
            $query->where('is_active', true)->where(fn ($q) => $q->where('last_seen_at', '<', $idleBefore)
                ->orWhere(fn ($w) => $w->whereNull('last_seen_at')->where('created_at', '<', $idleBefore)));
        }
        if ($filter === 'mfa_missing') {
            $ids = (clone $query)->whereNull('two_factor_confirmed_at')->where('is_active', true)->get()
                ->filter(fn (User $u) => $u->requiresMfa())->pluck('id');
            $query->whereIn('id', $ids);
        }

        // حقول محسوبة فقط (لا أعمدة العامل الثاني)، وآخر عنوان دخول لمن يملك تعديل المستخدمين وحده
        $canSeeIp = auth()->user()->can('users.update');
        $users = $query->paginate(15)->withQueryString()->through(fn (User $u) => [
            'id' => $u->id,
            'name' => $u->name,
            'email' => $u->email,
            'phone' => $u->phone,
            'role_id' => $u->role_id,
            'role' => $u->role ? ['id' => $u->role->id, 'name' => $u->role->name] : null,
            'is_active' => (bool) $u->is_active,
            'has_mfa' => $u->hasTwoFactor(),
            'requires_mfa' => $u->requiresMfa(),
            'last_login' => $u->last_login_at?->diffForHumans(),
            'last_login_at' => $u->last_login_at?->timezone('Asia/Amman')->format('Y-m-d H:i'),
            'last_seen' => $u->last_seen_at?->diffForHumans(),
            'idle' => (bool) $u->is_active && ($u->last_seen_at ?? $u->created_at)?->lt($idleBefore),
            'last_login_ip' => $canSeeIp ? $u->last_login_ip : null,
        ]);
        $roles = Role::select('id', 'name')->get();

        return Inertia::render('Users/Index', [
            'users' => $users,
            'roles' => $roles,
            'assignableRoles' => RoleAssignmentGuard::assignableRoles(auth()->user()),
            'filters' => $request->only(['search', 'role_id', 'filter']),
            'mfa' => ['enforcement' => strtolower((string) config('security.mfa.enforce', 'off')), 'enrollment_open' => Mfa::enrollmentOpen()],
        ]);
    }

    /**
     * إعادة تعيين التحقق بخطوتين لموظف فقد هاتفه (E27): المدير وحده، بسبب، ويُخرَج الموظف من كل أجهزته.
     * لا لحساب المدير نفسه (من صفحة الأمان)، ولا لآخر مدير نشط بعامل ثانٍ (أمر الطوارئ security:mfa-reset وحده).
     */
    public function resetTwoFactor(Request $request, User $user)
    {
        abort_unless(auth()->user()->isAdmin(), 403);
        $data = $request->validate(['reason' => 'required|string|min:10|max:200'], [
            'reason.required' => 'اكتب سبب إعادة التعيين.', 'reason.min' => 'اكتب السبب (10 أحرف على الأقل).',
        ]);

        if ($user->id === auth()->id()) {
            abort(403, 'عطّل تحققك بنفسك من صفحة «الأمان»');
        }
        if (!$user->hasTwoFactor()) {
            return back()->with('error', "لا تحقق بخطوتين مفعّل لحساب {$user->name}");
        }
        if (TwoFactor::isLastAdminWithFactor($user)) {
            return back()->with('error', 'هذا آخر مدير نشط بتحقق بخطوتين — إعادة تعيينه بأمر الطوارئ من الخادم وحده');
        }

        DB::transaction(function () use ($user, $data) {
            TwoFactor::clear($user);
            AuditLog::log(\App\Enums\AuditAction::AuthMfaReset, 'User', $user->id, $user->name, ['reason' => $data['reason'], 'by' => 'admin']);
        });
        SessionRevoker::revoke($user);

        rescue(function () use ($user) {
            NotificationService::send($user->id, '🔐 أُعيد تعيين التحقق بخطوتين', 'أعاد المدير تعيين التحقق بخطوتين لحسابك — سجّله من جديد من صفحة «الأمان» بعد الدخول.', [
                'type' => 'security', 'icon' => '🔐', 'action_url' => '/profile/security',
            ]);
            NotificationService::notifyAdmins('🔐 إعادة تعيين تحقق موظف', auth()->user()->name." أعاد تعيين التحقق بخطوتين لحساب {$user->name}.", [
                'type' => 'security', 'icon' => '🔐', 'action_url' => '/users',
            ]);
        }, null, false);

        return back()->with('success', "أُعيد تعيين التحقق بخطوتين لحساب {$user->name}، وخرج من كل أجهزته");
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
            'password' => ['required', 'string', \App\Support\PasswordPolicy::rule(), 'confirmed'],
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
