<?php

namespace App\Services\WhatsApp;

use App\Models\WaBotSetting;
use App\Services\Ops\OpsAlerts;
use Illuminate\Support\Facades\Cache;

/**
 * فحوص ميتا للقراءة (E08): الرمز يومياً (debug_token) وصحة الإرسال كل ساعة (health_status).
 * تنبّه المدير ولا تغيّر wa_suspended أبداً (الإيقاف الآلي لأحداث الحساب وحدها). لا تخزّن رمزاً ولا رداً خاماً
 * ولا معرّفات كيانات: الحالة والتواريخ والصلاحيات الناقصة وأخطاء الكيانات كما شرحتها ميتا.
 * انقطاع الاتصال لا ينبّه إلا بعد مرتين متتاليتين.
 */
final class MetaHealth
{
    public const SCOPES = ['whatsapp_business_messaging', 'whatsapp_business_management'];

    /** الحالات بالترتيب من الأسوأ */
    private const SEVERITY = ['critical' => 3, 'warn' => 2, 'unreachable' => 1, 'ok' => 0, 'not_configured' => 0];

    private const CREDENTIAL_ALERTS = [
        'token_invalid' => ['⛔ رمز ميتا غير صالح — الإرسال متوقف', 'ولّد رمزاً جديداً لمستخدم النظام في Business Settings وحدّثه في إعدادات الواتساب.'],
        'app_credentials_invalid' => ['⛔ فحص رمز ميتا لا يعمل', 'معرّف التطبيق أو سرّه غير صحيح. راجع App ID وApp Secret في إعدادات الواتساب.'],
        'missing_scopes' => ['⛔ رمز ميتا بلا صلاحيات لازمة', 'الصلاحيات الناقصة في بطاقة صحة القناة. أعد توليد الرمز بالصلاحيتين.'],
        'app_mismatch' => ['⚠️ رمز ميتا من تطبيق آخر', 'الرمز لم يُولَّد من التطبيق المضبوط معرّفه في الإعدادات.'],
        'expiring' => ['⚠️ رمز ميتا يقترب من الانتهاء', 'جدّد الرمز قبل انتهائه، أو ولّد رمزاً لا ينتهي (Never expires).'],
        'unreachable' => ['⚠️ تعذّر فحص رمز ميتا', 'فشل الاتصال بميتا مرتين متتاليتين. راجع الشبكة أو صفحة حالة ميتا.'],
    ];

    private const CHANNEL_ALERTS = [
        'blocked' => ['⛔ ميتا أوقفت الإرسال من رقمنا', 'السبب والحل المقترح من ميتا في بطاقة صحة القناة.'],
        'limited' => ['⚠️ ميتا قيّدت الإرسال من رقمنا', 'التفاصيل في بطاقة صحة القناة.'],
        'quality_red' => ['⚠️ جودة رقم الواتساب حمراء', 'العملاء يحظرون الرقم أو يبلّغون عنه. راجع الرسائل الآلية.'],
        'unreachable' => ['⚠️ تعذّر فحص صحة القناة', 'فشل الاتصال بميتا مرتين متتاليتين. راجع الشبكة أو صفحة حالة ميتا.'],
    ];

    public function __construct(private OpsAlerts $alerts) {}

    /** @return array<string, mixed> الملخص المحفوظ في wa_credentials_status */
    public function credentials(): array
    {
        $s = WaBotSetting::current();
        if (!$s->metaChecksReady()) {
            return $this->save($s, 'wa_credentials_status', ['state' => 'not_configured', 'reasons' => []]);
        }

        $r = WhatsAppChannel::debugToken();
        if (!$r['reached']) {
            return $this->unreachable($s, 'wa_credentials_status', 'credentials', self::CREDENTIAL_ALERTS);
        }
        Cache::forget('meta:unreachable:credentials');

        // خطأ على رمز التطبيق نفسه (190 أو 102): المعرّف أو السر خطأ
        if ($r['status'] !== 200 || !isset($r['json']['data'])) {
            $code = (int) data_get($r['json'], 'error.code');
            $state = in_array($code, [190, 102], true) ? 'critical' : 'unreachable';

            return $this->conclude($s, 'wa_credentials_status', 'credentials', $state, $state === 'critical' ? ['app_credentials_invalid'] : [], ['error_code' => $code ?: null], self::CREDENTIAL_ALERTS);
        }

        $data = (array) $r['json']['data'];
        $expiresAt = (int) ($data['expires_at'] ?? 0);
        $missing = array_values(array_diff(self::SCOPES, (array) ($data['scopes'] ?? [])));
        $appMatches = (string) ($data['app_id'] ?? '') === (string) $s->appId();
        $daysLeft = $expiresAt > 0 ? (int) floor(($expiresAt - now()->getTimestamp()) / 86400) : null;

        $issues = [];
        if (!($data['is_valid'] ?? false)) {
            $issues['token_invalid'] = 'critical';
        }
        if ($missing !== []) {
            $issues['missing_scopes'] = 'critical';
        }
        if ($daysLeft !== null && $daysLeft < (int) config('ops.token_warn_days')) {
            $issues['expiring'] = $daysLeft < (int) config('ops.token_critical_days') ? 'critical' : 'warn';
        }
        if (!$appMatches) {
            $issues['app_mismatch'] = 'warn';
        }

        return $this->conclude($s, 'wa_credentials_status', 'credentials', $this->worst($issues), array_keys($issues), [
            'is_valid' => (bool) ($data['is_valid'] ?? false),
            'expires_at' => $expiresAt > 0 ? date(DATE_ATOM, $expiresAt) : null,
            'never_expires' => $expiresAt === 0,
            'days_left' => $daysLeft,
            'missing_scopes' => $missing,
            'app_matches' => $appMatches,
        ], self::CREDENTIAL_ALERTS);
    }

    /** @return array<string, mixed> الملخص المحفوظ في wa_channel_health */
    public function channel(): array
    {
        $s = WaBotSetting::current();
        if (!$s->channelReady()) {
            return $this->save($s, 'wa_channel_health', ['state' => 'not_configured', 'reasons' => []]);
        }

        $r = WhatsAppChannel::healthStatus();
        if (!$r['reached']) {
            return $this->unreachable($s, 'wa_channel_health', 'channel', self::CHANNEL_ALERTS);
        }
        Cache::forget('meta:unreachable:channel');
        if ($r['status'] !== 200) {
            return $this->conclude($s, 'wa_channel_health', 'channel', 'unreachable', [], ['error_code' => (int) data_get($r['json'], 'error.code') ?: null], self::CHANNEL_ALERTS);
        }

        $health = (array) data_get($r['json'], 'health_status', []);
        $overall = (string) ($health['can_send_message'] ?? 'UNKNOWN');
        $quality = data_get($r['json'], 'quality_rating');
        // بلا معرّفات الكيانات: النوع والحالة وما شرحته ميتا
        $entities = array_map(fn ($e) => [
            'entity_type' => (string) data_get($e, 'entity_type'),
            'can_send_message' => (string) data_get($e, 'can_send_message'),
            'errors' => array_map(fn ($err) => [
                'error_code' => data_get($err, 'error_code'),
                'error_description' => data_get($err, 'error_description'),
                'possible_solution' => data_get($err, 'possible_solution'),
            ], (array) data_get($e, 'errors', [])),
            'additional_info' => array_values((array) data_get($e, 'additional_info', [])),
        ], (array) ($health['entities'] ?? []));

        $issues = match ($overall) {
            'BLOCKED' => ['blocked' => 'critical'],
            'LIMITED' => ['limited' => 'warn'],
            default => [],
        };
        if ($quality === 'RED') {
            $issues['quality_red'] = 'warn';
        }

        return $this->conclude($s, 'wa_channel_health', 'channel', $this->worst($issues), array_keys($issues), [
            'can_send_message' => $overall,
            'quality_rating' => $quality,
            'verified_name' => data_get($r['json'], 'verified_name'),
            'entities' => $entities,
        ], self::CHANNEL_ALERTS);
    }

    /** @param array<string, string> $issues */
    private function worst(array $issues): string
    {
        $state = 'ok';
        foreach ($issues as $level) {
            if (self::SEVERITY[$level] > self::SEVERITY[$state]) {
                $state = $level;
            }
        }

        return $state;
    }

    private function unreachable(WaBotSetting $s, string $column, string $kind, array $messages): array
    {
        $key = "meta:unreachable:{$kind}";
        Cache::add($key, 0, now()->addDays(3));
        $failures = (int) Cache::increment($key);

        // تفاصيل آخر فحص وصل تبقى ظاهرة، ومعها حالته في last_known
        $previous = (array) ($s->{$column} ?? []);
        $summary = $this->save($s, $column, array_merge($previous, [
            'state' => 'unreachable', 'reasons' => [], 'last_known' => self::lastKnown($previous),
        ]));
        if ($failures >= 2) {
            $this->alerts->notifyAdmins("meta:{$kind}:unreachable", ...$messages['unreachable']);
        }

        return $summary;
    }

    /** يحفظ وينبّه: warn أو critical مرة في الساعة لكل سبب، وتعافٍ من critical مرة واحدة */
    private function conclude(WaBotSetting $s, string $column, string $kind, string $state, array $reasons, array $details, array $messages): array
    {
        $previous = self::lastKnown((array) ($s->{$column} ?? []));
        $summary = $this->save($s, $column, ['state' => $state, 'reasons' => $reasons] + $details);

        if (in_array($state, ['warn', 'critical'], true)) {
            foreach ($reasons as $reason) {
                if (isset($messages[$reason])) {
                    $this->alerts->notifyAdmins("meta:{$kind}:{$state}:{$reason}", ...$messages[$reason]);
                }
            }
        }
        if ($state === 'ok' && $previous === 'critical') {
            $this->alerts->notifyAdmins("meta:{$kind}:recovered", '✅ عادت قناة ميتا سليمة', $kind === 'credentials' ? 'فحص رمز ميتا سليم الآن.' : 'ميتا تسمح بالإرسال من رقمنا الآن.');
        }

        return $summary;
    }

    /** حالة آخر فحص وصل إلى ميتا (عبر فحوص لم تصل) */
    private static function lastKnown(array $summary): ?string
    {
        return ($summary['state'] ?? null) === 'unreachable' ? ($summary['last_known'] ?? null) : ($summary['state'] ?? null);
    }

    private function save(WaBotSetting $s, string $column, array $summary): array
    {
        $summary['checked_at'] = now()->toIso8601String();
        try {
            $s->forceFill([$column => $summary])->save();
        } catch (\Throwable $e) {
            // قبل الهجرة 2026_10_14_000008 لا عمود: الفحص لا يكسر المجدول
            WaLog::error('meta.check.save_failed', ['type' => $e::class]);
        }

        return $summary;
    }
}
