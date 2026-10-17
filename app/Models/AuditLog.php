<?php

namespace App\Models;

use App\Enums\AuditAction;
use App\Enums\AuditSeverity;
use App\Support\AuditActor;
use App\Support\AuditRedactor;
use App\Support\AuditSampler;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;

/**
 * سجل التدقيق المركزي: من فعل ماذا، ومتى، ومن أين. للإلحاق فقط (والقاعدة ترفض UPDATE بمُشغّل).
 *
 * ثلاثة أنماط كتابة:
 *  log     صارم يرمي: للمال والصلاحيات، داخل المعاملة نفسها (لا اعتماد بلا صف تدقيق).
 *  safe    بأفضل جهد: لا يُفشل العملية ولا الويبهوك. وما ليس alwaysOn خلف audit.extended.
 *  sampled بالعيّنات: للضجيج (دخول فاشل، توقيع مرفوض، 403).
 */
class AuditLog extends Model
{
    use MassPrunable;

    public $timestamps = false;

    protected $fillable = [
        'user_id', 'user_name', 'action', 'entity_type', 'entity_id', 'entity_label', 'changes', 'ip_address',
        'request_id', 'created_at', 'severity', 'actor_type', 'actor_ref', 'method', 'path', 'user_agent',
    ];

    protected $casts = [
        'changes' => 'array',
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('audit_logs is append-only'));
        static::deleting(fn () => throw new \LogicException('audit_logs is append-only'));
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /** null لنص لا يعرفه التعداد (صفوف قديمة أو مستقبلية) */
    public function actionEnum(): ?AuditAction
    {
        return AuditAction::tryFrom((string) $this->action);
    }

    /** التقليم اليومي: أحداث الضجيج وحدها بعد مدة الاحتفاظ */
    public function prunable(): Builder
    {
        return static::query()
            ->whereIn('action', AuditAction::noiseValues())
            ->where('created_at', '<', now()->subDays((int) config('audit.noise_retention_days', 90)));
    }

    public static function log(AuditAction $action, string $entityType, ?int $entityId = null, ?string $label = null, ?array $changes = null, ?AuditSeverity $severity = null): self
    {
        return static::create(self::row($action, $entityType, $entityId, $label, $changes, $severity));
    }

    public static function safe(AuditAction $action, string $entityType, ?int $entityId = null, ?string $label = null, ?array $changes = null, ?AuditSeverity $severity = null): ?self
    {
        if (!self::enabledFor($action)) {
            return null;
        }
        try {
            return static::log($action, $entityType, $entityId, $label, $changes, $severity);
        } catch (\Throwable $e) {
            Log::error('audit.write_failed', ['action' => $action->value, 'class' => $e::class]);

            return null;
        }
    }

    /** $key يحدد ما يُعد تكراراً (مثل البريد والعنوان)؛ لا يُخزَّن */
    public static function sampled(AuditAction $action, string $key, string $entityType, ?int $entityId = null, ?string $label = null, ?array $changes = null, ?AuditSeverity $severity = null): ?self
    {
        if (!self::enabledFor($action)) {
            return null;
        }
        try {
            $sample = AuditSampler::allow($action->value, $key);
            if (!$sample['allowed']) {
                return null;
            }
            if ($sample['suppressed'] > 0) {
                $changes = ($changes ?? []) + ['suppressed_count' => $sample['suppressed']];
            }

            return static::log($action, $entityType, $entityId, $label, $changes, $severity);
        } catch (\Throwable $e) {
            Log::error('audit.write_failed', ['action' => $action->value, 'class' => $e::class]);

            return null;
        }
    }

    private static function enabledFor(AuditAction $action): bool
    {
        return $action->alwaysOn() || (bool) config('audit.extended');
    }

    private static function row(AuditAction $action, string $entityType, ?int $entityId, ?string $label, ?array $changes, ?AuditSeverity $severity): array
    {
        $actor = AuditActor::current();
        $userId = $actor['user_id'] ?? auth()->id();
        $user = $userId === null ? null : (auth()->id() === $userId ? auth()->user() : User::find($userId));
        // حقول الطلب لطلب ويب فقط: الطرفية والطابور تعطي 127.0.0.1 مضللاً
        $request = request();
        $http = $request->route() !== null;

        return [
            'user_id' => $userId,
            'user_name' => $user?->name,
            'action' => $action->value,
            'severity' => ($severity ?? $action->severity())->value,
            'actor_type' => $actor['type'],
            'actor_ref' => $actor['ref'] !== null ? mb_substr((string) $actor['ref'], 0, 64) : null,
            'entity_type' => mb_substr($entityType, 0, 50),
            'entity_id' => $entityId,
            'entity_label' => $label !== null ? mb_substr($label, 0, 100) : null,
            'changes' => AuditRedactor::clean($changes),
            'ip_address' => $http ? $request->ip() : null,
            'method' => $http ? $request->method() : null,
            'path' => $http ? mb_substr($request->path(), 0, 191) : null,
            'user_agent' => $http ? mb_strcut((string) $request->userAgent(), 0, 255) : null,
            'request_id' => Context::get('request_id'),
            'created_at' => now(),
        ];
    }
}
