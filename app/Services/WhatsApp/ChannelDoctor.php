<?php

namespace App\Services\WhatsApp;

use App\Models\User;
use App\Models\WaBotSetting;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;

/**
 * فحص صحة القناة والإعداد (whatsapp:doctor وشريط صفحة الإعدادات). قراءة فقط، ولا تُعاد أي قيمة سرية:
 * كل بند مستوى ورمز ورسالة عربية فيها النصيحة.
 */
final class ChannelDoctor
{
    /** @return list<array{level: string, code: string, message: string}> */
    public static function checks(): array
    {
        $out = [];
        $add = function (string $level, string $code, string $message) use (&$out) {
            $out[] = compact('level', 'code', 'message');
        };

        $settings = rescue(fn () => WaBotSetting::current(), null, false);
        if ($settings) {
            // سرّ محفوظ بمفتاح آخر (APP_KEY تغيّر): يبدو فارغاً في الواجهة ويتعطل الإرسال
            foreach (['wa_token' => 'رمز ميتا', 'wa_app_secret' => 'سر التطبيق', 'api_key' => 'مفتاح النموذج'] as $field => $label) {
                $raw = $settings->getRawOriginal($field);
                if ($raw === null || $raw === '') {
                    continue;
                }
                try {
                    Crypt::decryptString($raw);
                } catch (DecryptException) {
                    $add('error', 'SECRET_UNREADABLE', "{$label} محفوظ بمفتاح تطبيق آخر — أعد إدخاله من الإعدادات، أو أضف المفتاح القديم إلى APP_PREVIOUS_KEYS.");
                }
            }

            if (rescue(fn () => $settings->botReady(), false, false) && !rescue(fn () => $settings->appSecret(), null, false)) {
                $add('warn', 'NO_APP_SECRET', 'البوت مفعّل بلا سر التطبيق — لا يُتحقق من توقيع الويبهوك فتُرفض كل الرسائل الواردة.');
            }

            $envToken = (string) config('services.whatsapp.verify_token');
            $dbToken = (string) $settings->wa_verify_token;
            if (($envToken !== '' && strlen($envToken) < 16) || ($envToken === '' && $dbToken !== '' && strlen($dbToken) < 16)) {
                $add('warn', 'SHORT_VERIFY_TOKEN', 'رمز التحقق من الويبهوك أقصر من 16 حرفاً — ولّد رمزاً جديداً من الإعدادات ثم حدّثه في لوحة ميتا.');
            }
        }

        $admin = rescue(fn () => User::where('email', 'admin@nusuk-jo.com')->first(), null, false);
        if ($admin && Hash::check('password', (string) $admin->password)) {
            $add('error', 'DEFAULT_ADMIN_PASSWORD', 'حساب المدير الافتراضي بكلمة المرور الافتراضية — غيّرها فوراً.');
        }

        $levels = ['debug' => 0, 'info' => 1, 'notice' => 2, 'warning' => 3, 'error' => 4, 'critical' => 5, 'alert' => 6, 'emergency' => 7];
        $level = strtolower((string) config('logging.channels.daily.level', 'debug'));
        if (($levels[$level] ?? 0) > $levels['warning']) {
            $add('warn', 'LOG_LEVEL_TOO_STRICT', "مستوى السجل {$level} يُسقط تحذيرات الواتساب — اجعله LOG_LEVEL=warning.");
        }

        foreach (['app.url' => 'APP_URL', 'flights.travelfusion.endpoint' => 'TF_ENDPOINT'] as $key => $name) {
            $url = (string) config($key);
            if ($url !== '' && !str_starts_with($url, 'https://') && app()->isProduction()) {
                $add('warn', 'NOT_HTTPS', "{$name} لا يبدأ بـ https://.");
            }
        }

        // E27: كوكي الجلسة بلا Secure يُرسل على http أيضاً (أول طلب قبل التحويل إلى https)
        if (app()->isProduction() && config('session.secure') !== true) {
            $add('warn', 'SESSION_COOKIE_NOT_SECURE', 'كوكي الجلسة غير مقيّد بـ HTTPS — اجعل APP_URL يبدأ بـ https:// أو اضبط SESSION_SECURE_COOKIE=true.');
        }

        if (in_array(trim((string) config('trustedproxy.proxies', '*')), ['*', '**'], true)) {
            $add('warn', 'TRUST_ALL_PROXIES', 'TRUSTED_PROXIES=* يثق بأي مصدر لعنوان الزائر — اضبطه على 127.0.0.1 وبوابة شبكة الحاوية بعد ربط المنفذ على loopback.');
        }

        $authError = Cache::get('wa:last_auth_error_at');
        if ($authError && rescue(fn () => now()->diffInHours($authError, true) < 24, false, false)) {
            $add('error', 'META_TOKEN_REJECTED', 'رفضت ميتا رمز الوصول (190) خلال آخر 24 ساعة — حدّث الرمز من الإعدادات.');
        }

        // E18: رفض ميتا لإثبات سر التطبيق يوقف كل النداءات، والرمز المجهول المتكرر يستحق مراجعة الجدول
        $today = Graph\GraphClient::counters(1)[0];
        if ($today['proof_err'] > 0) {
            $add('error', 'APPSECRET_PROOF_REJECTED', "رفضت ميتا appsecret_proof اليوم ({$today['proof_err']} مرة) — صحّح سر التطبيق أو أعد WA_APPSECRET_PROOF=false.");
        }
        foreach (rescue(fn () => MetaErrorReactor::unknownCodes(), [], false) as $code => $count) {
            if ($count >= 3) {
                $add('warn', 'META_UNKNOWN_CODE', "رمز ميتا {$code} لا يعرفه جدول الرموز وتكرر {$count} مرات اليوم — راجع MetaErrorCatalog.");
            }
        }

        if (config('app.debug') && app()->isProduction()) {
            $add('error', 'APP_DEBUG', 'APP_DEBUG مفعّل في الإنتاج — صفحات الخطأ تكشف البيئة.');
        }

        return $out;
    }

    /**
     * تحذيرات مصادر الإعدادات (E10) لـ whatsapp:doctor وحده: بيئة تظلل قيمة محفوظة، وإيقاف من البيئة،
     * وسر يُفك بمفتاح سابق فقط (تدوير APP_KEY لم يكتمل)، ورمز تحقق مكتوب يدوياً لم يُولَّد.
     *
     * @return list<array{level: string, code: string, message: string}>
     */
    public static function sourceWarnings(WaBotSetting $settings): array
    {
        $out = [];
        $sources = $settings->sources();
        foreach ($sources as $key => $src) {
            if ($src['db_shadowed']) {
                $out[] = ['level' => 'warn', 'code' => 'ENV_SHADOWS_DB', 'message' => "{$src['env_var']} في البيئة يظلل قيمة {$key} المحفوظة — احذف أحدهما كي لا يختلط مصدرها."];
            }
        }
        if ($sources['wa_suspended']['source'] === 'env') {
            $out[] = ['level' => 'warn', 'code' => 'ENV_SUSPENDED', 'message' => 'WA_SUSPENDED مضبوط في البيئة: كل الإرسال موقوف ولا يُرفع من الإعدادات.'];
        }
        foreach (['wa_token', 'wa_app_secret', 'api_key'] as $field) {
            $raw = $settings->getRawOriginal($field);
            if (Settings\AppKeys::decryptsWithCurrent($raw) === false && $settings->decryptOk($field) === true) {
                $out[] = ['level' => 'warn', 'code' => 'SECRET_ON_PREVIOUS_KEY', 'message' => "{$field} يُفك بمفتاح سابق فقط — شغّل whatsapp:reencrypt-secrets قبل حذف APP_PREVIOUS_KEYS."];
            }
        }
        if ($sources['wa_verify_token']['source'] === 'db' && !empty($settings->wa_verify_token)) {
            $out[] = ['level' => 'warn', 'code' => 'VERIFY_TOKEN_PLAIN', 'message' => 'رمز التحقق مكتوب يدوياً ومحفوظ نصاً — ولّد رمزاً من الإعدادات، ثم whatsapp:settings-finalize يمسح النص.'];
        }

        return $out;
    }
}
