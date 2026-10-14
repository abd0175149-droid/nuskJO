<?php

namespace App\Support;

use App\Models\WaBotSetting;
use Illuminate\Support\Facades\Log;

/**
 * أعلام الميزات المركزية (E10): كل ميزة لاحقة تُشحن هنا مطفأة، وتُفعَّل بلا نشر.
 * الأولوية: البيئة (config/features.php) ثم المحفوظ (wa_bot_settings.feature_flags، يبدّله المدير بسبب ويُدقَّق)
 * ثم الافتراض (false دائماً). علم available=false لم يُشحن كوده بعد: لا يعمل ولا يُبدَّل حتى تشحنه ملحمته.
 */
final class FeatureFlags
{
    public const DEFINITIONS = [
        'llm_circuit_breaker' => ['label' => 'قاطع دائرة مزوّد الذكاء', 'capability' => 'C256', 'available' => false],
        'inbound_event_log' => ['label' => 'سجل أحداث الوارد', 'capability' => 'C261', 'available' => false],
        'retention_purge' => ['label' => 'حذف دوري بحسب مدة الاحتفاظ', 'capability' => 'C287', 'available' => true],
        'media_archive' => ['label' => 'أرشفة مرفقات العملاء (صور ووثائق)', 'capability' => 'C283', 'available' => true],
        'media_consent_prompt' => ['label' => 'إشعار العميل بحفظ ملفاته مع زر «احذف ملفاتي»', 'capability' => 'C283', 'available' => true],
    ];

    /** @var array<string, array>|null بديل DEFINITIONS في الاختبارات وحدها (fake) */
    private static ?array $fake = null;

    /** للاختبارات: علم متاح لا يوجد بعد في الكود. null يعيد التعريفات الحقيقية */
    public static function fake(?array $definitions): void
    {
        self::$fake = $definitions;
    }

    /** @return array<string, array{label: string, capability: string, available: bool}> */
    public static function definitions(): array
    {
        return self::$fake ?? self::DEFINITIONS;
    }

    public static function on(string $key): bool
    {
        if (!isset(self::definitions()[$key])) {
            if (app()->runningUnitTests()) {
                throw new \InvalidArgumentException("علم غير معرّف: {$key}");
            }
            Log::error('feature_flag.unknown', ['flag' => $key]);

            return false;
        }

        return self::available($key) && self::state($key)['on'];
    }

    public static function available(string $key): bool
    {
        return (bool) (self::definitions()[$key]['available'] ?? false);
    }

    /** @return array{on: bool, source: string} source: env | db | default */
    public static function state(string $key): array
    {
        $env = config("features.env.{$key}");
        if ($env !== null && $env !== '') {
            return ['on' => (bool) filter_var($env, FILTER_VALIDATE_BOOL), 'source' => 'env'];
        }
        $db = (WaBotSetting::current()->feature_flags ?? [])[$key] ?? null;
        if ($db !== null) {
            return ['on' => (bool) $db, 'source' => 'db'];
        }

        return ['on' => false, 'source' => 'default'];
    }

    /** للوحة الأعلام: بلا قيم سرية */
    public static function panel(): array
    {
        $out = [];
        foreach (self::definitions() as $key => $d) {
            $state = self::state($key);
            $out[] = ['key' => $key, 'label' => $d['label'], 'available' => self::available($key)] + $state;
        }

        return $out;
    }
}
