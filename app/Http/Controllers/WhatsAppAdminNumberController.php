<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\User;
use App\Models\WaAdminAction;
use App\Models\WaAdminNumber;
use App\Models\WaBotSetting;
use App\Services\WhatsApp\AdminTools;
use App\Services\WhatsApp\PhoneNormalizer;
use App\Support\FeatureFlags;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * أرقام الإدارة: من يُخدم بالمساعد الإداري على واتساب، وبأيّ أدوات.
 */
class WhatsAppAdminNumberController extends Controller
{
    public function index()
    {
        abort_unless(auth()->user()->can('whatsapp.settings'), 403);

        $numbers = WaAdminNumber::with('user:id,name,is_active,role_id,two_factor_confirmed_at')->orderBy('name')->get();

        return Inertia::render('WhatsApp/AdminNumbers', [
            'title' => 'أرقام الإدارة',
            'testSendEnabled' => FeatureFlags::on('wa_test_send'),
            'numbers' => $numbers->map(fn ($n) => [
                'id' => $n->id,
                'phone' => $n->phone,
                'name' => $n->name,
                'user_id' => $n->user_id,
                'user_name' => $n->user?->name,
                'user_inactive' => $n->user && !$n->user->is_active,
                // E27: يشترط العامل الثاني لمستخدمه، والمساعد موقوف إن لم يفعّله (والعلم admin_number_mfa مفعّل)
                'require_mfa' => (bool) $n->require_mfa,
                'user_has_mfa' => (bool) $n->user?->hasSecondFactor(),
                'mfa_blocked' => $n->mfaBlocked(),
                'permissions' => $n->permissions ?? [],
                // ما يدعمه دور المستخدم المرتبط من صلاحيات الرقم، وتحذيرات ما لا يدعمه (E15.5)
                'effective' => $n->effectivePermissions(),
                'warnings' => AdminTools::permissionWarnings($n),
                'is_active' => (bool) $n->is_active,
                // E23: يستقبل «الإرسال التجريبي» (العلم wa_test_send)
                'test_recipient' => (bool) $n->test_recipient,
                'notes' => $n->notes,
                'last_used' => $n->last_used_at?->diffForHumans(),
                // رقمٌ مسجّل لعميل أيضاً؟ تنبيه: صاحبه سيُخدم كإدارة لا كعميل
                'client_match' => PhoneNormalizer::findClient($n->phone)?->name,
            ])->values(),
            'catalog' => AdminTools::CATALOG,
            // تسميات أدوات المساعد وأفعالها من سجلها (E15)، لا قائمة مكتوبة في الصفحة
            'toolLabels' => AdminTools::labels(),
            'users' => User::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'actions' => WaAdminAction::with('adminNumber:id,name')->orderByDesc('id')->limit(40)->get()
                ->map(fn ($a) => [
                    'id' => $a->id,
                    'who' => $a->adminNumber?->name ?? '—',
                    'tool' => $a->tool,
                    'action' => $a->action,
                    'status' => $a->status,
                    'summary' => $a->summary,
                    'at' => $a->created_at?->format('Y-m-d H:i'),
                ]),
            'botPhone' => \App\Services\CompanyInfo::all()['phone'],
            'botReady' => WaBotSetting::current()->channelReady(),
            'modes' => [
                'readonly' => FeatureFlags::on('admin_readonly'),
                'rbacEnforced' => FeatureFlags::on('admin_rbac_enforce'),
                'mfaRequired' => FeatureFlags::on('admin_number_mfa'),
            ],
            'isAdmin' => auth()->user()->isAdmin(),
        ]);
    }

    public function store(Request $request)
    {
        abort_unless(auth()->user()->can('whatsapp.settings'), 403);

        $data = $this->validated($request);
        $data['created_by'] = auth()->id();
        $n = WaAdminNumber::create($data);

        AuditLog::log(\App\Enums\AuditAction::Create, 'wa_admin_number', $n->id, $n->name, ['permissions' => $n->permissions, 'require_mfa' => (bool) $n->require_mfa]);

        return back()->with('success', "أُضيف رقم الإدارة «{$n->name}»");
    }

    public function update(Request $request, WaAdminNumber $adminNumber)
    {
        abort_unless(auth()->user()->can('whatsapp.settings'), 403);

        $before = $adminNumber->only(['phone', 'name', 'permissions', 'is_active', 'require_mfa', 'test_recipient']);
        $adminNumber->update($this->validated($request, $adminNumber));

        AuditLog::log(\App\Enums\AuditAction::Update, 'wa_admin_number', $adminNumber->id, $adminNumber->name, [
            'before' => $before, 'after' => $adminNumber->only(['phone', 'name', 'permissions', 'is_active', 'require_mfa', 'test_recipient']),
        ]);

        return back()->with('success', "حُفظ رقم الإدارة «{$adminNumber->name}»");
    }

    public function destroy(WaAdminNumber $adminNumber)
    {
        abort_unless(auth()->user()->can('whatsapp.settings'), 403);

        AuditLog::log(\App\Enums\AuditAction::Delete, 'wa_admin_number', $adminNumber->id, $adminNumber->name);
        $adminNumber->delete();

        return back()->with('success', 'حُذف رقم الإدارة — صار صاحبه يُخدم كأي عميل');
    }

    private function validated(Request $request, ?WaAdminNumber $existing = null): array
    {
        $ignoreId = $existing?->id;
        $data = $request->validate([
            'phone' => 'required|string|max:32',
            'name' => 'required|string|max:100',
            'user_id' => 'nullable|exists:users,id',
            'permissions' => 'present|array',
            'permissions.*' => ['string', Rule::in(array_keys(AdminTools::CATALOG))],
            'is_active' => 'boolean',
            'notes' => 'nullable|string|max:1000',
            'require_mfa' => 'sometimes|boolean',
            'test_recipient' => 'sometimes|boolean',
        ], [
            'phone.required' => 'رقم الهاتف مطلوب.',
            'name.required' => 'اسم صاحب الرقم مطلوب.',
        ]);

        // نفس صيغة رقم المحادثة، وإلا لا يتعرّف عليه البوت
        $canonical = PhoneNormalizer::canonical($data['phone']);
        if (strlen($canonical) < 11) {
            back()->withErrors(['phone' => 'الرقم غير صالح — اكتبه كاملاً، مثل 0791234567 أو ‎+962791234567.'])->throwResponse();
        }
        $taken = WaAdminNumber::where('phone', $canonical)->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))->exists();
        if ($taken) {
            back()->withErrors(['phone' => 'هذا الرقم مسجّل كرقم إدارة بالفعل.'])->throwResponse();
        }

        $data['phone'] = $canonical;
        $data['permissions'] = array_values(array_unique($data['permissions']));
        $data['is_active'] = $request->boolean('is_active', true);

        // E27 (العلم admin_number_mfa): الرقم الذي يعدّل يحتاج حساباً مرتبطاً، ويشترط عامله الثاني تلقائياً.
        // لا يُطفأ الشرط لرقم يعدّل إلا المدير (ويُدقَّق في صف الحفظ). والعلم مطفأ: الخانة تُحفظ كما هي ولا تُفرض
        $writes = (bool) array_filter($data['permissions'], fn ($p) => AdminTools::CATALOG[$p]['write'] ?? false);
        $requested = $request->has('require_mfa') ? $request->boolean('require_mfa') : (bool) ($existing?->require_mfa ?? false);
        if (FeatureFlags::on('admin_number_mfa') && $writes) {
            if (empty($data['user_id'])) {
                back()->withErrors(['user_id' => 'رقم فيه صلاحيات تعديل يحتاج حساب صاحبه في النظام (لشرط التحقق بخطوتين وصلاحيات دوره).'])->throwResponse();
            }
            $requested = $requested || !auth()->user()->isAdmin();
            if (!$request->has('require_mfa') && !$existing) {
                $requested = true;
            }
        }
        $data['require_mfa'] = $requested;

        return $data;
    }
}
