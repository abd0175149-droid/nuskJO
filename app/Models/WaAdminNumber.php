<?php

namespace App\Models;

use App\Services\WhatsApp\AdminTools;
use App\Services\WhatsApp\PhoneNormalizer;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * رقم واتساب لموظف إدارة.
 * المحادثة من هذا الرقم تُخدم بالمساعد الإداري بدل بوت العملاء،
 * ولا يرى المساعد إلا الأدوات المسموحة في permissions.
 *
 * الهويّة من رقم المرسِل في ويبهوك ميتا الموقّع — لا يستطيع أحد انتحالها من الدردشة.
 */
class WaAdminNumber extends Model
{
    use HasFactory;

    protected $table = 'wa_admin_numbers';

    protected $fillable = [
        'phone', 'name', 'user_id', 'permissions', 'is_active', 'notes', 'last_used_at', 'created_by', 'require_mfa',
        // E23: يستقبل «الإرسال التجريبي» (قرار المالك أي الأرقام)
        'test_recipient',
    ];

    protected $casts = [
        'permissions' => 'array',
        'is_active' => 'boolean',
        'last_used_at' => 'datetime',
        'require_mfa' => 'boolean',
        'test_recipient' => 'boolean',
    ];

    /**
     * علَم لاصق: محادثة رقم الإدارة (والرقم السابق إن تغيّر) تبقى محجوبة عمن لا يملك whatsapp.admin_chats،
     * حتى بعد تعطيل الرقم أو حذفه، فسجل التقارير والإيرادات لا ينكشف. لا يوجد مسار يزيل العلَم.
     */
    protected static function booted(): void
    {
        $mark = function (array $phones): void {
            $phones = array_values(array_filter(array_unique($phones)));
            if ($phones && \Illuminate\Support\Facades\Schema::hasColumn('wa_conversations', 'is_admin_chat')) {
                WaConversation::whereIn('phone', $phones)->update(['is_admin_chat' => true]);
            }
        };

        static::saved(fn (self $n) => $mark([$n->phone, $n->getOriginal('phone')]));
        static::deleting(fn (self $n) => $mark([$n->phone]));
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** الرقم الإداري الفعّال لرقم محادثة، إن وُجد */
    public static function forPhone(?string $phone): ?self
    {
        $canonical = PhoneNormalizer::canonical($phone);
        if ($canonical === '') {
            return null;
        }

        try {
            // رقم موظف عُطّل حسابه يُعامَل كعميل: لا أدوات إدارية ولا أرقام إيرادات
            return self::where('phone', $canonical)->where('is_active', true)
                ->where(fn ($q) => $q->whereNull('user_id')->orWhereHas('user', fn ($u) => $u->where('is_active', true)))
                ->first();
        } catch (\Throwable $e) {
            return null;   // قبل تشغيل الهجرة — لا أرقام إدارة بعد
        }
    }

    /**
     * رقم الإدارة لرقم محادثة مع سبب حجبه (E27): blocked='mfa' إن كان الرقم يشترط العامل الثاني ومستخدمه المرتبط
     * لم يفعّله، والعلم admin_number_mfa مفعّل. forPhone باقية كما هي (الرقم الذي عُطّل مستخدمه يُعامَل كعميل).
     *
     * @return array{number: ?self, blocked: ?string}
     */
    public static function resolveForPhone(?string $phone): array
    {
        $number = self::forPhone($phone);
        if ($number && $number->mfaBlocked()) {
            return ['number' => $number, 'blocked' => 'mfa'];
        }

        return ['number' => $number, 'blocked' => null];
    }

    /** يشترط العامل الثاني ومستخدمه بلا عامل (أو بلا مستخدم)؟ لا حجب والعلم مطفأ */
    public function mfaBlocked(): bool
    {
        if (!$this->require_mfa || !rescue(fn () => \App\Support\FeatureFlags::on('admin_number_mfa'), false, false)) {
            return false;
        }
        $user = $this->user_id ? $this->user : null;

        return !$user || !$user->hasSecondFactor();
    }

    /** فيه صلاحية تعديل (AdminTools::CATALOG بقيمة write)؟ */
    public function hasWritePermissions(): bool
    {
        return (bool) array_filter($this->permissions ?? [], fn ($p) => AdminTools::CATALOG[$p]['write'] ?? false);
    }

    public function allows(string $permission): bool
    {
        return in_array($permission, $this->permissions ?? [], true);
    }

    /** هل يملك أيّاً من هذه الصلاحيات؟ */
    public function allowsAny(array $permissions): bool
    {
        return (bool) array_intersect($permissions, $this->permissions ?? []);
    }

    /**
     * صلاحيات الرقم التي يدعمها صاحبه (E15.5): صلاحيات الرقم ∩ ما يملكه المستخدم المرتبط بدوره (AdminTools::RBAC_REQUIRES).
     * بلا مستخدم مرتبط أو بمستخدم معطّل: القراءة وحدها. الفرض خلف العلم admin_rbac_enforce (AdminTools::gate).
     *
     * @return list<string>
     */
    public function effectivePermissions(): array
    {
        $perms = $this->permissions ?? [];
        $user = $this->user_id ? $this->user : null;
        if (!$user || !$user->is_active) {
            return array_values(array_filter($perms, fn ($p) => !(AdminTools::CATALOG[$p]['write'] ?? true)));
        }

        return array_values(array_filter($perms, fn ($p) => AdminTools::rbacAllows($user, $p)));
    }
}
