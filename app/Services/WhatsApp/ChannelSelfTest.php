<?php

namespace App\Services\WhatsApp;

use App\Models\WaBotSetting;
use App\Services\Ops\OpsHealth;
use App\Support\FeatureFlags;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * «اختبار الاتصال» (E23، العلم channel_selftest): قراءات ميتا الرسمية (الرقم، واشتراكات التطبيق برمز التطبيق، واشتراك
 * الحساب)، ثم ارتداد موقّع ذاتياً عبر رابط الويبهوك العام، ثم آخر ويبهوك حقيقي. لا يرسل شيئاً لأي عميل، ولا يعدّل
 * الويبهوك ولا override_callback_uri عند ميتا.
 *
 * الارتداد: الكائن nuskjo_selftest بلا entry ولا changes ولا الحقل messages أبداً، ويُعترض في المتحكم بعد التوقيع وقبل أي
 * عدّاد أو حفظ أو معالجة. وحدّه: يوقّع بالسر المخزّن نفسه فلا يثبت صحته؛ الذي يثبتها خطوة app_secret وآخر ويبهوك حقيقي.
 */
final class ChannelSelfTest
{
    public const OBJECT = 'nuskjo_selftest';

    private const NONCE_TTL = 120;

    public static function enabled(): bool
    {
        return FeatureFlags::on('channel_selftest');
    }

    /** @return array{at: string, ok: bool, steps: list<array{key: string, ok: ?bool, label: string, detail: string, advice: ?string}>} */
    public static function run(): array
    {
        $steps = [self::node(), self::appSecret(), self::waba(), self::loopback(), self::lastReal()];

        return [
            'at' => now()->toIso8601String(),
            'ok' => !in_array(false, array_column($steps, 'ok'), true),
            'steps' => $steps,
        ];
    }

    /**
     * جهة الاستقبال (WhatsAppWebhookController::receive، بعد التحقق من التوقيع): حمولة الارتداد تُعلَّم وتنتهي هنا.
     * nonce لم نصدره يُتجاهل بصمت (200 بلا أثر). يعيد true إن كانت الحمولة حمولة ارتداد.
     */
    public static function intercepts(string $raw): bool
    {
        if (!str_contains($raw, self::OBJECT) || !self::enabled()) {
            return false;
        }
        $payload = json_decode($raw, true);
        if (!is_array($payload) || ($payload['object'] ?? null) !== self::OBJECT) {
            return false;
        }
        $nonce = is_string($payload['nonce'] ?? null) ? $payload['nonce'] : '';
        if (preg_match('/^[A-Za-z0-9]{32}$/', $nonce) && OpsHealth::store()->get('wa:selftest:'.$nonce) === 'pending') {
            OpsHealth::store()->put('wa:selftest:'.$nonce, 'received', self::NONCE_TTL);
        }

        return true;
    }

    private static function node(): array
    {
        $s = WaBotSetting::current();
        if (!$s->channelReady()) {
            return self::step('node', false, 'الرمز ومعرّف الرقم', 'القناة غير مهيّأة.', 'أدخل Access Token وPhone Number ID في «ربط قناة ميتا».');
        }
        $r = MetaHealth::phoneNode(8);
        if (!$r['reached']) {
            return self::step('node', false, 'الرمز ومعرّف الرقم', 'تعذّر الوصول إلى ميتا (مهلة أو شبكة).', 'أعد المحاولة بعد قليل.');
        }
        if ($r['status'] !== 200) {
            $code = (int) data_get($r['json'], 'error.code');

            return self::step('node', false, 'الرمز ومعرّف الرقم', 'ميتا رفضت قراءة الرقم'.($code ? " ({$code})" : '').'.',
                $code === 190 ? 'الرمز منتهٍ أو مُلغى — ولّد رمزاً جديداً لمستخدم النظام.' : 'تحقق أن Phone Number ID من الحساب نفسه الذي يخصه الرمز.');
        }
        $status = (string) data_get($r['json'], 'status', '');
        $can = (string) data_get($r['json'], 'health_status.can_send_message', '');
        $ok = ($status === '' || $status === 'CONNECTED') && $can !== 'BLOCKED';

        return self::step('node', $ok, 'الرمز ومعرّف الرقم', 'متطابقان. حالة الرقم '.($status ?: '؟').'، والإرسال '.($can ?: '؟').'.',
            $ok ? null : 'التفاصيل في بطاقة «صحة القناة» وفي WhatsApp Manager.');
    }

    private static function appSecret(): array
    {
        $r = MetaHealth::appSubscriptions(8);
        $label = 'App Secret ورابط الويبهوك';

        return match ($r['state']) {
            'not_possible' => self::step('app_secret', null, $label, 'غير ممكن — أدخل App ID وApp Secret.', 'App ID من App settings › Basic.'),
            'unreachable' => self::step('app_secret', false, $label, 'تعذّر الوصول إلى ميتا.', 'أعد المحاولة بعد قليل.'),
            'app_credentials_invalid' => self::step('app_secret', false, $label, 'ميتا رفضت رمز التطبيق: App Secret أو App ID غير صحيح.', 'انسخهما من App settings › Basic.'),
            'error' => self::step('app_secret', false, $label, 'ردّت ميتا بخطأ'.(($r['error_code'] ?? null) ? " ({$r['error_code']})" : '').'.'),
            default => self::subscriptionStep($r, $label),
        };
    }

    private static function subscriptionStep(array $r, string $label): array
    {
        if (!$r['found']) {
            return self::step('app_secret', false, $label, 'App Secret صحيح، لكن التطبيق غير مشترك في أحداث whatsapp_business_account.', 'أضف الاشتراك من App Dashboard › WhatsApp › Configuration.');
        }
        if ($r['callback'] === null || !$r['callback']['ours']) {
            return self::step('app_secret', false, $label, 'App Secret صحيح، لكن الويبهوك يشير إلى '.($r['callback']['host'] ?? '?').' وليس إلى نُسك.',
                'صحّحه من App Dashboard › WhatsApp › Configuration إلى '.MetaHealth::ourWebhookUrl());
        }
        $missing = array_values(array_diff(MetaHealth::requiredFields(), array_keys($r['fields'])));
        if ($missing !== [] || !$r['active']) {
            return self::step('app_secret', false, $label, 'App Secret صحيح والرابط رابطنا، لكن '.($r['active'] ? 'حقولاً غير مشتركة: '.implode('، ', $missing) : 'الاشتراك موقوف').'.',
                'من App Dashboard › Webhook fields.');
        }

        return self::step('app_secret', true, $label, 'App Secret صحيح، والويبهوك رابط نُسك، والحقول المطلوبة مشتركة.');
    }

    private static function waba(): array
    {
        $r = MetaHealth::subscribedApps(8);
        $label = 'اشتراك حساب الأعمال';

        return match ($r['state']) {
            'no_waba' => self::step('waba', null, $label, 'معرّف حساب الأعمال غير معروف.', 'أدخل WA_WABA_ID أو انتظر اكتشافه من الفحص الدوري.'),
            'not_configured' => self::step('waba', null, $label, 'القناة غير مهيّأة.'),
            'unreachable' => self::step('waba', false, $label, 'تعذّر الوصول إلى ميتا.'),
            'error' => self::step('waba', false, $label, 'ردّت ميتا بخطأ'.(($r['error_code'] ?? null) ? " ({$r['error_code']})" : '').'.', 'الرمز يحتاج whatsapp_business_management.'),
            default => match (true) {
                ($r['override'] ?? null) !== null && !$r['override']['ours'] => self::step('waba', false, $label, "override على الحساب يوجّه الأحداث إلى {$r['override']['host']}.", 'أزله من لوحة ميتا إن لم يكن مقصوداً.'),
                $r['our_app_subscribed'] === false => self::step('waba', false, $label, 'تطبيقنا غير مشترك في حساب الأعمال: لا تصل الرسائل.', 'اشترك من WhatsApp Manager.'),
                $r['our_app_subscribed'] === null => self::step('waba', null, $label, 'لا App ID للمقارنة.'),
                default => self::step('waba', true, $label, 'تطبيقنا مشترك في حساب الأعمال بلا override.'),
            },
        };
    }

    private static function loopback(): array
    {
        $label = 'ارتداد موقّع عبر الرابط العام';
        $secret = WaBotSetting::current()->appSecret();
        if (!$secret) {
            return self::step('loopback', false, $label, 'لا App Secret للتوقيع.', 'أدخله في «ربط قناة ميتا».');
        }
        $nonce = Str::random(32);
        $key = 'wa:selftest:'.$nonce;
        OpsHealth::store()->put($key, 'pending', self::NONCE_TTL);
        // لا entry ولا changes ولا field، والحقل messages ممنوع هنا منعاً تاماً
        $body = (string) json_encode(['object' => self::OBJECT, 'nonce' => $nonce, 'ts' => time()]);

        try {
            $res = Http::withHeaders(['X-Hub-Signature-256' => 'sha256='.hash_hmac('sha256', $body, $secret), 'Content-Type' => 'application/json'])
                ->timeout(5)->connectTimeout(3)->withoutRedirecting()
                ->withBody($body, 'application/json')
                ->post(MetaHealth::ourWebhookUrl());
        } catch (\Throwable $e) {
            WaLog::warning('selftest.loopback_failed', ['type' => class_basename($e)]);

            return self::step('loopback', false, $label, 'تعذّر الوصول إلى رابط الويبهوك العام ('.class_basename($e).').', 'راجع النفق وnginx وAPP_URL.');
        } finally {
            $state = OpsHealth::store()->get($key);
            OpsHealth::store()->forget($key);
        }
        if ($res->status() === 403) {
            return self::step('loopback', false, $label, 'الرابط رفض التوقيع (403).', 'السر المخزّن لا يطابق ما يتحقق به الخادم — راجع WA_APP_SECRET.');
        }
        if ($res->status() !== 200 || $state !== 'received') {
            return self::step('loopback', false, $label, "ردّ الرابط {$res->status()} ولم يصل الارتداد إلى المعالج.", 'راجع النفق وnginx واستثناء المسار من الجلسة.');
        }

        return self::step('loopback', true, $label, 'وصل الطلب الموقّع عبر الرابط العام إلى المعالج ورُدّ 200. يثبت النفق وnginx والمسار والتوقيع، لا صحة السر.');
    }

    private static function lastReal(): array
    {
        $label = 'آخر ويبهوك حقيقي من ميتا';
        $last = Cache::get('wa:sig:last_ok_at');
        $stats = WebhookStats::summary(true);
        $rejected = array_filter(array_map(fn ($c) => (int) ($c['rejected'] ?? 0), (array) ($stats['fence']['counts'] ?? [])));
        $bad = (int) ($stats['signatures']['day'] ?? 0);
        $detail = ($last ? 'آخر توقيع صحيح: '.Carbon::parse($last)->timezone('Asia/Amman')->format('Y-m-d H:i') : 'لم يصل ويبهوك موقّع بعد')
            .' · توقيعات مرفوضة (يومان): '.$bad
            .($rejected ? ' · رفض السياج: '.implode('، ', array_map(fn ($r, $n) => "{$r} {$n}", array_keys($rejected), $rejected)) : '');
        $ok = $last !== null && $bad === 0 ? true : ($last === null ? null : false);

        return self::step('last_real', $ok, $label, $detail, $bad > 0 ? 'توقيعات مرفوضة: قد يكون App Secret المخزّن غير صحيح.' : null);
    }

    private static function step(string $key, ?bool $ok, string $label, string $detail, ?string $advice = null): array
    {
        return compact('key', 'ok', 'label', 'detail', 'advice');
    }
}
