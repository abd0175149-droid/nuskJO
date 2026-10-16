<?php

namespace App\Services\WhatsApp;

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\WaAdminNumber;
use App\Models\WaConversation;
use App\Models\WaOptout;
use App\Support\AuditActor;
use App\Support\FeatureFlags;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/**
 * ويبهوك user_preferences (E21، العلم user_preferences_optout): العميل أوقف رسائل التسويق من الشركة في تطبيق
 * واتساب نفسه (stop) أو أعادها (resume). يُسجَّل في wa_optouts بالمصدر meta_pref.
 *
 * - مصفّى برقمنا: metadata.phone_number_id لرقم آخر (ومنه حمولة زر Test في لوحة ميتا) يُتجاهل ويُعدّ فقط.
 * - الفئة marketing_messages وحدها، والقيمتان stop وresume وحدهما.
 * - resume تحذف الإيقاف الأقدم من وقتها أياً كان مصدره (نص أو زر أو ميتا)، فالإيقاف الأحدث لا يمحوه إعادة قديمة.
 * - لا رسالة للعميل (الإجراء اتخذه في تطبيقه)، ولا حدث في الناقل (الهوية رقم، والأحداث بمعرّفات فقط).
 * - التدقيق بالمعرّف والأعداد، بلا wa_id ولا رقم.
 *
 * wa_optouts لا يقرؤها اليوم إلا OptOutRule في السياقات المبادرة (ولا قوالب بقرار المالك). أي إرسال يبدؤه النظام
 * لاحقاً (سقف E24، أو قوالب بقرار مكتوب) يجب أن يقرأها قبل الإرسال.
 */
final class UserPreferencesHandler
{
    public const REASON_STOP = 'تفضيلات واتساب: إيقاف التسويق';

    private static ?bool $ready = null;

    /** العلم، وبعد الهجرة 2026_10_14_000021 (العمود source) وحدها */
    public static function enabled(): bool
    {
        if (!FeatureFlags::on('user_preferences_optout')) {
            return false;
        }

        return self::$ready ??= Schema::hasColumn('wa_optouts', 'source');
    }

    /** @return array{stop: int, resume: int, skipped: int} */
    public static function handle(array $value, string $ownId): array
    {
        $out = ['stop' => 0, 'resume' => 0, 'skipped' => 0];
        $to = (string) data_get($value, 'metadata.phone_number_id', '');
        if ($ownId !== '' && $to !== $ownId) {
            // رقم آخر على التطبيق نفسه، أو بلا رقم: لا يُعرف لمن، فلا يُكتب شيء. العدد وحده
            $day = 'wa:userpref:foreign:'.now()->format('Ymd');
            Cache::add($day, 0, now()->addDays(2));
            Cache::increment($day);
            WaLog::info('webhook.user_preferences.foreign', ['field' => 'user_preferences']);

            return $out;
        }

        $contacts = (array) data_get($value, 'contacts', []);
        foreach ((array) data_get($value, 'user_preferences', []) as $p) {
            $kind = self::apply((array) $p, $contacts);
            $out[$kind ?? 'skipped']++;
        }
        WaLog::info('webhook.user_preferences', ['count' => $out['stop'] + $out['resume'], 'deleted' => $out['resume']]);

        return $out;
    }

    /** @return 'stop'|'resume'|null */
    private static function apply(array $p, array $contacts): ?string
    {
        if (strtolower((string) data_get($p, 'category')) !== 'marketing_messages') {
            return null;
        }
        $value = strtolower((string) data_get($p, 'value'));
        if (!in_array($value, ['stop', 'resume'], true)) {
            return null;
        }
        $key = self::identity($p, $contacts);
        if ($key === null) {
            return null;
        }
        // رقم الإدارة لا يُسجَّل رافضاً للتسويق (كما في الإيقاف النصي)
        if (isset($key['phone']) && WaAdminNumber::forPhone($key['phone'])) {
            return null;
        }

        $ts = is_numeric(data_get($p, 'timestamp')) ? (int) data_get($p, 'timestamp') : 0;
        $at = $ts > 0 ? Carbon::createFromTimestamp($ts, config('app.timezone')) : now();
        if ($at->greaterThan(now())) {
            $at = now();
        }

        $row = WaOptout::where($key)->first();
        if ($value === 'stop') {
            // إيقاف أحدث مسجّل (نص أو زر بعد هذا الحدث) لا تكتب فوقه إعادة قديمة من ميتا
            if (!$row || !$row->created_at || $row->created_at->lessThanOrEqualTo($at)) {
                try {
                    $row = WaOptout::updateOrCreate($key, ['reason' => self::REASON_STOP, 'source' => 'meta_pref', 'created_at' => $at]);
                } catch (UniqueConstraintViolationException) {
                    $row = WaOptout::where($key)->first();   // نسختان من الحدث نفسه معاً
                }
            }
        } else {
            WaOptout::where($key)->where('created_at', '<=', $at)->delete();
        }

        AuditActor::as('meta', 'user_preferences', fn () => AuditLog::safe($value === 'stop' ? AuditAction::WaOptoutMeta : AuditAction::WaOptinMeta,
            'wa_optout', $row?->id, null, ['count' => 1, 'source' => 'meta_pref']));

        return $value;
    }

    /**
     * الرقم الكامل من wa_id، وإلا معرّف BSUID (user_id) يُحل بمحادثته: رقمها إن عُرف، وإلا المعرّف نفسه.
     *
     * @return array{phone: string}|array{wa_user_id: string}|null
     */
    private static function identity(array $p, array $contacts): ?array
    {
        $phone = ContactIdentity::phoneFrom(data_get($p, 'wa_id'));
        if ($phone !== null) {
            return ['phone' => $phone];
        }

        $userId = data_get($p, 'user_id') ?? data_get(ContactIdentity::contactFor($contacts, null, null), 'user_id');
        if (!is_string($userId) || !preg_match(InboundMessage::BSUID, $userId) || str_contains($userId, '.ENT.')) {
            return null;
        }
        $conv = WaConversation::where('wa_user_id', $userId)->where('status', '!=', 'merged')->first();

        return $conv && $conv->hasPhone() ? ['phone' => (string) $conv->phone] : ['wa_user_id' => $userId];
    }
}
