<?php

namespace App\Services\WhatsApp;

use App\Models\User;
use App\Models\WaBotSetting;
use App\Services\Ops\OpsAlerts;
use App\Services\Ops\OpsHealth;
use App\Services\Rendering\HeadlessChromium;
use App\Support\FeatureFlags;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * فحص صحة القناة والإعداد (whatsapp:doctor وشريط صفحة الإعدادات). قراءة فقط، ولا تُعاد أي قيمة سرية:
 * كل بند مستوى ورمز ورسالة عربية فيها النصيحة.
 *
 * E23: قسمان جديدان في run() — local (تعارضات البيئة: الطابور وretry_after والقفل والكاش وSQLite وكروميوم
 * وstorage:link والقرص والناقل وReverb) وremote (debug_token وعقدة الرقم والاشتراكان، بميزانية زمنية كلية).
 * كل بند {section, level: ok|warn|error|skip, code, message, advice}. الحصيلة في 'wa:doctor:last' يومين (store).
 */
final class ChannelDoctor
{
    /** دوال JSON التي تعتمدها E21 */
    public const SQLITE_MIN = '3.31.0';

    /** وظائف واتساب: مهلة كل منها يجب أن تقل عن retry_after (تُقرأ بالانعكاس لا بأرقام مكتوبة) */
    private const WA_JOBS = [
        \App\Jobs\ProcessWhatsAppReply::class, \App\Jobs\RefreshChannelHealth::class, \App\Jobs\SendPushNotification::class,
        \App\Jobs\SendOutageNotice::class, \App\Jobs\SendWhatsAppMediaJob::class, \App\Jobs\ArchiveWhatsAppMedia::class,
        \App\Jobs\SendWhatsAppReadReceipt::class,
    ];

    /** المهلة القصوى لكل نداء بعيد (ثوانٍ) */
    private const REMOTE_CALL_MAX = 5;

    /** للاختبارات: إصدار SQLite المحاكى بدل الحقيقي */
    public static ?\Closure $sqliteVersion = null;

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

        // E26: CORS مغلق (config/cors.php)؛ التطبيق من أصل واحد، فمسار مفتوح أو أصل '*' يفتح ردوداً لمواقع أخرى
        if ((array) config('cors.paths', []) !== [] || in_array('*', (array) config('cors.allowed_origins', []), true)) {
            $add('warn', 'CORS_OPEN', 'CORS مفتوح في config/cors.php (paths أو أصل *) — التطبيق من أصل واحد، أعد paths إلى [] والأصول إلى [].');
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
     * إصدار كروميوم المسجّل عند بناء الصورة (E26.8): apk لا يثبّت إصداره، فيُكتب في /etc/chromium-version
     * ليظهر في سجل البناء وفي whatsapp:doctor. null خارج الحاوية أو في صورة أقدم.
     */
    public static function chromiumVersion(string $file = '/etc/chromium-version'): ?string
    {
        $line = is_readable($file) ? trim((string) strtok((string) file_get_contents($file), "\n")) : '';

        return $line !== '' ? mb_substr($line, 0, 120) : null;
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

    // ==================== E23: الفحص الكامل (local وremote) ====================

    /**
     * الفحص الكامل لـ whatsapp:doctor وزر «شغّل الفحص»: الإعداد (checks وsourceWarnings) والويبهوك (E20) والبيئة
     * والفحوص البعيدة بميزانية كلية بالثواني. لا يرمي، ولا يغيّر شيئاً عند ميتا ولا حالة القناة.
     *
     * @return array{at: string, budget: int, offline: bool, duration_ms: int, counts: array<string, int>, checks: list<array<string, ?string>>}
     */
    public static function run(int $budget = 8, bool $offline = false): array
    {
        $started = self::clock();
        $settings = rescue(fn () => WaBotSetting::current(), null, false);
        $checks = [];
        foreach (rescue(fn () => self::checks(), [], false) as $c) {
            $checks[] = self::item('config', $c['level'], $c['code'], $c['message']);
        }
        if ($settings) {
            foreach (rescue(fn () => self::sourceWarnings($settings), [], false) as $c) {
                $checks[] = self::item('config', $c['level'], $c['code'], $c['message']);
            }
        }
        foreach (rescue(fn () => WebhookStats::checks(WebhookStats::summary(true)), [], false) as $c) {
            $checks[] = self::item('webhook', $c['level'] === 'warning' ? 'warn' : $c['level'], $c['code'], $c['message']);
        }
        $checks = [...$checks, ...self::local()];
        $checks = [...$checks, ...($offline ? [self::item('remote', 'skip', 'REMOTE_OFFLINE', 'الفحوص البعيدة متخطاة (--offline).')] : self::remote($budget))];

        $counts = ['ok' => 0, 'warn' => 0, 'error' => 0, 'skip' => 0];
        foreach ($checks as $c) {
            $counts[$c['level']]++;
        }

        return [
            'at' => now()->toIso8601String(),
            'budget' => $budget,
            'offline' => $offline,
            'duration_ms' => (int) round((self::clock() - $started) * 1000),
            'counts' => $counts,
            'checks' => $checks,
        ];
    }

    /**
     * فحوص البيئة (C223). ما يتعلق بالحاوية (كروميوم وstorage:link والقرص وReverb) تحذير في الإنتاج وحده، وخارجه «تخطٍّ».
     *
     * @return list<array<string, ?string>>
     */
    public static function local(): array
    {
        $out = [];
        $prod = app()->isProduction();
        $env = fn (bool $bad) => $bad ? ($prod ? 'warn' : 'skip') : 'ok';

        $queue = (string) config('queue.default');
        $out[] = $prod && $queue === 'sync'
            ? self::item('local', 'error', 'QUEUE_SYNC', 'QUEUE_CONNECTION=sync في الإنتاج: الردود والإشعارات تعمل داخل طلب الويب.', 'اجعله QUEUE_CONNECTION=database ثم docker compose up -d.')
            : self::item('local', 'ok', 'QUEUE_SYNC', "الطابور: {$queue}.");

        $retry = config("queue.connections.{$queue}.retry_after");
        if ($queue === 'sync' || $retry === null) {
            $out[] = self::item('local', 'skip', 'RETRY_AFTER', 'retry_after لا يخص هذا الطابور.');
        } else {
            [$timeout, $job] = self::longestJobTimeout();
            $out[] = (int) $retry <= $timeout
                ? self::item('local', 'error', 'RETRY_AFTER', "retry_after ({$retry} ث) لا يزيد على مهلة {$job} ({$timeout} ث): الوظيفة تُعاد وهي تعمل فيتكرر الرد.", 'ارفع DB_QUEUE_RETRY_AFTER فوق أطول مهلة بهامش (240 افتراضياً).')
                : self::item('local', 'ok', 'RETRY_AFTER', "retry_after {$retry} ث > أطول مهلة وظيفة واتساب ({$timeout} ث).");
        }

        $lock = \App\Jobs\ProcessWhatsAppReply::LOCK_SECONDS;
        $jobTimeout = \App\Jobs\ProcessWhatsAppReply::TIMEOUT;
        $out[] = $lock < $jobTimeout
            ? self::item('local', 'error', 'LOCK_TTL', "قفل المحادثة ({$lock} ث) أقصر من مهلة وظيفة الرد ({$jobTimeout} ث).", 'القفل يجب أن يغطي المهلة كاملة (E07).')
            : self::item('local', 'ok', 'LOCK_TTL', "قفل المحادثة {$lock} ث يغطي مهلة الرد {$jobTimeout} ث.");

        $cache = (string) config('cache.default');
        $out[] = $prod && in_array($cache, ['array', 'null'], true)
            ? self::item('local', 'error', 'CACHE_STORE', "مخزن الكاش {$cache} لا يُشارك بين العمليات: العدّادات والأقفال وإيصالات الحالة تضيع.", 'اجعله CACHE_STORE=database أو file.')
            : self::item('local', 'ok', 'CACHE_STORE', "مخزن الكاش: {$cache}.");

        $settings = rescue(fn () => WaBotSetting::current(), null, false);
        if ($settings && rescue(fn () => $settings->botReady(), false, false) && !rescue(fn () => $settings->verifyTokenHash(), null, false)) {
            $out[] = self::item('local', 'error', 'VERIFY_TOKEN_MISSING', 'البوت مفعّل بلا رمز تحقق للويبهوك: لا تكتمل مصافحة ميتا.', 'ولّد رمزاً من الإعدادات ثم انسخه إلى لوحة ميتا.');
        }

        foreach (['graph_version' => ['GRAPH_VERSION', 'إصدار Graph للطلبات'], 'webhook_version' => ['WEBHOOK_VERSION', 'إصدار الويبهوك']] as $key => [$code, $label]) {
            $version = config("services.whatsapp.{$key}") ?: null;
            if ($version === null) {
                $out[] = self::item('local', $prod ? 'warn' : 'skip', $code, "{$label} غير مضبوط.", 'اضبط WA_WEBHOOK_VERSION على إصدار الويبهوك في لوحة ميتا.');
                continue;
            }
            $st = GraphVersions::status($version);
            $out[] = match ($st['level']) {
                'expired' => self::item('local', 'error', $code, "{$label} {$version} انتهى دعمه في {$st['sunset']}: ميتا تحوّل الطلب بصمت إلى أقدم إصدار متاح.", 'رقّ الإصدار بعد مراجعة MetaErrorCatalog.'),
                'unknown' => self::item('local', 'error', $code, "{$label} {$version} غير موجود في جدول الإصدارات.", 'صحّح القيمة أو أضف الإصدار إلى GraphVersions.'),
                'warn' => self::item('local', 'warn', $code, "{$label} {$version} ينتهي في {$st['sunset']} (بعد {$st['days_left']} يوماً).", 'خطط للترقية قبل الموعد.'),
                default => self::item('local', 'ok', $code, "{$label} {$version}".($st['sunset'] ? " (حتى {$st['sunset']})" : '').'.'),
            };
        }

        if (config('database.default') === 'sqlite' || self::$sqliteVersion !== null) {
            $version = (string) (self::$sqliteVersion ? (self::$sqliteVersion)() : rescue(fn () => DB::selectOne('select sqlite_version() as v')->v, '', false));
            $out[] = $version === ''
                ? self::item('local', 'warn', 'SQLITE_VERSION', 'تعذّرت قراءة إصدار SQLite.')
                : (version_compare($version, self::SQLITE_MIN, '<')
                    ? self::item('local', 'error', 'SQLITE_VERSION', "SQLite {$version} أقدم من ".self::SQLITE_MIN.': دوال JSON في حالات التسليم لا تعمل.', 'حدّث صورة PHP الأساسية.')
                    : self::item('local', 'ok', 'SQLITE_VERSION', "SQLite {$version}."));
        }

        try {
            $binary = HeadlessChromium::binary();
            $runs = is_executable($binary);
            $out[] = self::item('local', $env(!$runs), 'CHROMIUM', $runs ? 'كروميوم موجود: '.(self::chromiumVersion() ?? basename($binary)).'.' : 'ملف كروميوم غير قابل للتنفيذ لهذا المستخدم.', $runs ? null : 'راجع أذونات الحزمة chromium في الصورة.');
        } catch (\Throwable) {
            $out[] = self::item('local', $env(true), 'CHROMIUM', 'لم يُعثر على كروميوم: بطاقات العروض وفاتورة PDF لا تُولَّد.', 'ثبّت chromium في الصورة أو اضبط CHROMIUM_PATH.');
        }

        $link = public_path('storage');
        $linked = is_link($link) && realpath($link) === realpath(storage_path('app/public'));
        $out[] = self::item('local', $env(!$linked), 'STORAGE_LINK', $linked ? 'public/storage يشير إلى storage/app/public.' : 'public/storage ليس رابطاً إلى storage/app/public: صور البطاقات لا تصل ميتا.', $linked ? null : 'شغّل php artisan storage:link (الإقلاع يفعلها).');

        $free = @disk_free_space(storage_path());
        $total = @disk_total_space(storage_path());
        if ($free !== false && $total) {
            $pct = (int) floor($free * 100 / $total);
            $out[] = $pct < 10
                ? self::item('local', $prod ? ($pct < 5 ? 'error' : 'warn') : 'skip', 'DISK_LOW', "المساحة الحرة {$pct}% فقط على قرص التخزين.", 'نظّف النسخ القديمة والسجلات قبل أن تتوقف الكتابة.')
                : self::item('local', 'ok', 'DISK_LOW', "المساحة الحرة {$pct}%.");
        }

        $configured = (string) config('whatsapp.transport', 'cloud');
        $bound = rescue(fn () => app(Transport\WhatsAppTransport::class)->name(), 'cloud', false);
        $out[] = match (true) {
            $configured === 'log' && $prod => self::item('local', 'error', 'TRANSPORT', 'WA_TRANSPORT=log مرفوض في الإنتاج: رُبط Cloud API بدلاً منه.', 'احذف WA_TRANSPORT أو اجعله cloud.'),
            $bound === 'log' => self::item('local', 'warn', 'TRANSPORT', 'الناقل log: لا شيء يصل ميتا (اختبار وتطوير محلي فقط).'),
            !in_array($configured, ['cloud', 'log'], true) => self::item('local', 'warn', 'TRANSPORT', "WA_TRANSPORT={$configured} غير معروف: رُبط Cloud API.", 'اجعله cloud.'),
            default => self::item('local', 'ok', 'TRANSPORT', 'الناقل: Cloud API الرسمية.'),
        };

        if (config('broadcasting.default') === 'reverb') {
            $port = (int) config('reverb.servers.reverb.port', 8080);
            $sock = @fsockopen('127.0.0.1', $port, $errno, $errstr, 1.0);
            if (is_resource($sock)) {
                fclose($sock);
            }
            $out[] = self::item('local', $env(!$sock), 'REVERB', $sock ? "Reverb يستمع على {$port}." : "Reverb لا يستمع على 127.0.0.1:{$port}: الصندوق اللحظي يعود للاستطلاع.", $sock ? null : 'راجع برنامج reverb في supervisord.');
        }

        return $out;
    }

    /**
     * الفحوص البعيدة بالترتيب (C204): debug_token، فعقدة الرقم، فاشتراكات التطبيق، فاشتراك الحساب. لكل نداء مهلة
     * min(5، المتبقي)، وما بعد نفاد الميزانية «لم يُفحص — الوقت». قراءة فقط، ولا تنبيه ولا تغيير لحالة القناة.
     *
     * @return list<array<string, ?string>>
     */
    public static function remote(int $budget = 5): array
    {
        $s = rescue(fn () => WaBotSetting::current(), null, false);
        if (!$s || !$s->channelReady()) {
            return [self::item('remote', 'skip', 'REMOTE_NOT_CONFIGURED', 'القناة غير مهيّأة: لا فحص بعيد.')];
        }

        $steps = [
            'META_TOKEN' => fn (int $t) => self::remoteToken($s, $t),
            'META_NUMBER' => fn (int $t) => self::remoteNode($t),
            'WEBHOOK_APP' => fn (int $t) => self::remoteAppSubscriptions($t),
            'WEBHOOK_WABA' => fn (int $t) => self::remoteWabaSubscriptions($t),
        ];
        $start = self::clock();
        $out = [];
        foreach ($steps as $code => $step) {
            $left = $budget - (self::clock() - $start);
            if ($left < 1) {
                $out[] = self::item('remote', 'skip', $code, 'لم يُفحص — الوقت.', 'شغّل الفحص بميزانية أكبر: whatsapp:doctor --budget=20');
                continue;
            }
            try {
                array_push($out, ...$step((int) max(1, min(self::REMOTE_CALL_MAX, floor($left)))));
            } catch (\Throwable $e) {
                WaLog::error('doctor.remote_failed', ['type' => class_basename($e), 'field' => $code]);
                $out[] = self::item('remote', 'warn', $code, 'تعذّر الفحص ('.class_basename($e).').');
            }
        }

        return $out;
    }

    /** حفظ الحصيلة يومين، وتنبيه واحد (بالعلم channel_monitor) حين يصير فحص ما خطأً لم يكن في الحصيلة السابقة */
    public static function store(array $result, bool $alert = true): void
    {
        $store = OpsHealth::store();
        $store->put('wa:doctor:last', $result, now()->addDays(2));
        $errors = array_values(array_unique(array_column(array_filter($result['checks'], fn ($c) => $c['level'] === 'error'), 'code')));
        sort($errors);
        $previous = (array) $store->get('wa:doctor:errors', []);
        $store->forever('wa:doctor:errors', $errors);
        $new = array_values(array_diff($errors, $previous));
        if ($alert && $new !== [] && FeatureFlags::on('channel_monitor')) {
            app(OpsAlerts::class)->notifyAdmins('wa:doctor:'.substr(sha1(implode(',', $errors)), 0, 16), '⛔ فحص الإعدادات وجد خطأً جديداً',
                mb_substr(count($errors).' خطأ: '.implode('، ', $errors).' — التفاصيل في إعدادات الواتساب › فحص الإعدادات.', 0, 400), '/whatsapp/settings#doctor');
        }
    }

    /** آخر حصيلة محفوظة (null قبل أول فحص كامل) */
    public static function last(): ?array
    {
        $last = rescue(fn () => OpsHealth::store()->get('wa:doctor:last'), null, false);

        return is_array($last) ? $last : null;
    }

    /** @return list<array<string, ?string>> */
    private static function remoteToken(WaBotSetting $s, int $timeout): array
    {
        if (!$s->metaChecksReady()) {
            return [self::item('remote', 'skip', 'META_TOKEN', 'فحص الرمز غير ممكن: يلزم App ID وApp Secret.', 'أدخلهما في «ربط قناة ميتا».')];
        }
        $r = WhatsAppChannel::debugToken($timeout);
        if (!$r['reached']) {
            return [self::item('remote', 'warn', 'META_TOKEN', 'تعذّر الوصول إلى ميتا (debug_token).', 'راجع الشبكة أو صفحة حالة ميتا.')];
        }
        if ($r['status'] !== 200 || !isset($r['json']['data'])) {
            $code = (int) data_get($r['json'], 'error.code');

            return [in_array($code, [190, 102], true)
                ? self::item('remote', 'error', 'APP_SECRET_INVALID', 'ميتا رفضت رمز التطبيق: App ID أو App Secret غير صحيح.', 'راجعهما من App settings › Basic.')
                : self::item('remote', 'warn', 'META_TOKEN', 'ردّت ميتا بخطأ على فحص الرمز'.($code ? " ({$code})" : '').'.')];
        }
        $data = (array) $r['json']['data'];
        if (!($data['is_valid'] ?? false)) {
            return [self::item('remote', 'error', 'META_TOKEN', 'رمز ميتا غير صالح.', 'ولّد رمزاً جديداً لمستخدم النظام وحدّثه في الإعدادات.')];
        }
        $out = [];
        $missing = array_values(array_diff(MetaHealth::SCOPES, (array) ($data['scopes'] ?? [])));
        if ($missing !== []) {
            $out[] = self::item('remote', 'error', 'META_TOKEN_SCOPES', 'الرمز بلا صلاحيات: '.implode('، ', $missing).'.', 'أعد توليد الرمز بالصلاحيتين.');
        }
        $expiresAt = (int) ($data['expires_at'] ?? 0);
        $days = $expiresAt > 0 ? (int) floor(($expiresAt - now()->getTimestamp()) / 86400) : null;
        $out[] = $days !== null && $days < (int) config('ops.token_warn_days', 14)
            ? self::item('remote', $days < (int) config('ops.token_critical_days', 3) ? 'error' : 'warn', 'META_TOKEN', "رمز ميتا صالح وينتهي بعد {$days} يوماً.", 'جدّده أو ولّد رمزاً لا ينتهي.')
            : self::item('remote', 'ok', 'META_TOKEN', 'رمز ميتا صالح'.($days === null ? ' ولا ينتهي.' : " (ينتهي بعد {$days} يوماً)."));

        return $out;
    }

    /** @return list<array<string, ?string>> */
    private static function remoteNode(int $timeout): array
    {
        $r = MetaHealth::phoneNode($timeout);
        if (!$r['reached']) {
            return [self::item('remote', 'warn', 'META_NUMBER', 'تعذّر الوصول إلى ميتا (عقدة الرقم).')];
        }
        if ($r['status'] !== 200) {
            $code = (int) data_get($r['json'], 'error.code');

            return [self::item('remote', 'error', 'META_NUMBER', 'ميتا رفضت قراءة الرقم'.($code ? " ({$code})" : '').': الرمز ومعرّف الرقم غير متطابقين أو بلا صلاحية.', 'تحقق من Phone Number ID والرمز.')];
        }
        $out = [];
        $can = (string) data_get($r['json'], 'health_status.can_send_message', '');
        $status = (string) data_get($r['json'], 'status', '');
        $out[] = match ($can) {
            'BLOCKED' => self::item('remote', 'error', 'META_NUMBER', 'ميتا تحجب الإرسال من رقمنا (BLOCKED).', 'التفاصيل في بطاقة «صحة القناة».'),
            'LIMITED' => self::item('remote', 'warn', 'META_NUMBER', 'ميتا تقيّد الإرسال من رقمنا (LIMITED).', 'التفاصيل في بطاقة «صحة القناة».'),
            'AVAILABLE' => self::item('remote', 'ok', 'META_NUMBER', 'الرمز ومعرّف الرقم متطابقان، والإرسال متاح (AVAILABLE).'),
            default => self::item('remote', 'warn', 'META_NUMBER', 'الرمز ومعرّف الرقم متطابقان، لكن ميتا لم تُعد حالة الإرسال.'),
        };
        if ($status !== '' && $status !== 'CONNECTED') {
            $out[] = self::item('remote', 'error', 'META_NUMBER_STATUS', "حالة الرقم عند ميتا {$status} لا CONNECTED: لا إرسال ولا استقبال.", 'راجع الرقم في WhatsApp Manager.');
        }
        if (data_get($r['json'], 'quality_rating') === 'RED') {
            $out[] = self::item('remote', 'warn', 'META_QUALITY', 'جودة الرقم حمراء.', 'راجع الرسائل الآلية والحظر.');
        }
        if (!$r['optional_dropped']) {
            $webhook = MetaHealth::nodeWebhook($r['json']);
            foreach (['phone_number' => 'الرقم', 'waba' => 'الحساب'] as $level => $label) {
                $u = $webhook[$level];
                if ($u !== null && !$u['ours']) {
                    $out[] = self::item('remote', 'error', 'WEBHOOK_OVERRIDE', "override على {$label} يوجّه الأحداث إلى {$u['host']} لا إلى نُسك.", 'أزله من لوحة ميتا إن لم يكن مقصوداً.');
                }
            }
        }

        return $out;
    }

    /** @return list<array<string, ?string>> */
    private static function remoteAppSubscriptions(int $timeout): array
    {
        $r = MetaHealth::appSubscriptions($timeout);

        return match ($r['state']) {
            'not_possible' => [self::item('remote', 'skip', 'WEBHOOK_APP', 'غير ممكن — أدخل App ID وApp Secret.')],
            'unreachable' => [self::item('remote', 'warn', 'WEBHOOK_APP', 'تعذّر الوصول إلى ميتا (اشتراكات التطبيق).')],
            'app_credentials_invalid' => [self::item('remote', 'error', 'APP_SECRET_INVALID', 'ميتا رفضت رمز التطبيق: App Secret أو App ID غير صحيح.', 'راجعهما من App settings › Basic.')],
            'error' => [self::item('remote', 'warn', 'WEBHOOK_APP', 'ردّت ميتا بخطأ على اشتراكات التطبيق'.(($r['error_code'] ?? null) ? " ({$r['error_code']})" : '').'.')],
            default => self::appSubscriptionItems($r),
        };
    }

    /** @return list<array<string, ?string>> */
    private static function appSubscriptionItems(array $r): array
    {
        if (!$r['found']) {
            return [self::item('remote', 'error', 'WEBHOOK_APP', 'التطبيق غير مشترك في أحداث whatsapp_business_account.', 'أضف الاشتراك من App Dashboard › WhatsApp › Configuration.')];
        }
        $out = [];
        $callback = $r['callback'];
        $out[] = $callback !== null && $callback['ours']
            ? self::item('remote', 'ok', 'WEBHOOK_APP', 'App Secret صحيح، ورابط الويبهوك عند ميتا هو رابط نُسك.')
            : self::item('remote', 'error', 'WEBHOOK_CALLBACK', 'رابط الويبهوك عند ميتا على '.($callback['host'] ?? '?').' لا على نُسك.', 'صحّحه من App Dashboard › WhatsApp › Configuration إلى '.MetaHealth::ourWebhookUrl());
        if (!$r['active']) {
            $out[] = self::item('remote', 'error', 'WEBHOOK_INACTIVE', 'اشتراك الويبهوك موقوف عند ميتا.', 'فعّله من App Dashboard.');
        }
        $missing = array_values(array_diff(MetaHealth::requiredFields(), array_keys($r['fields'])));
        if ($missing !== []) {
            $out[] = self::item('remote', in_array('messages', $missing, true) ? 'error' : 'warn', 'WEBHOOK_FIELDS', 'حقول غير مشتركة: '.implode('، ', $missing).'.', 'اشترك فيها من App Dashboard › Webhook fields.');
        }

        return $out;
    }

    /** @return list<array<string, ?string>> */
    private static function remoteWabaSubscriptions(int $timeout): array
    {
        $r = MetaHealth::subscribedApps($timeout);

        return match ($r['state']) {
            'no_waba' => [self::item('remote', 'skip', 'WEBHOOK_WABA', 'معرّف حساب الأعمال غير معروف.', 'أدخل WA_WABA_ID أو انتظر اكتشافه من الفحص الدوري.')],
            'unreachable' => [self::item('remote', 'warn', 'WEBHOOK_WABA', 'تعذّر الوصول إلى ميتا (اشتراك الحساب).')],
            'error', 'not_configured' => [self::item('remote', 'warn', 'WEBHOOK_WABA', 'ردّت ميتا بخطأ على اشتراك الحساب'.(($r['error_code'] ?? null) ? " ({$r['error_code']})" : '').'.')],
            default => array_values(array_filter([
                match ($r['our_app_subscribed']) {
                    false => self::item('remote', 'error', 'WEBHOOK_WABA', 'تطبيقنا غير مشترك في حساب واتساب للأعمال: لا تصل الرسائل.', 'اشترك من WhatsApp Manager أو بـ POST subscribed_apps يدوياً.'),
                    null => self::item('remote', 'skip', 'WEBHOOK_WABA', 'لا App ID للمقارنة مع التطبيقات المشتركة.'),
                    default => self::item('remote', 'ok', 'WEBHOOK_WABA', 'تطبيقنا مشترك في حساب واتساب للأعمال.'),
                },
                ($r['override'] ?? null) !== null && !$r['override']['ours']
                    ? self::item('remote', 'error', 'WEBHOOK_OVERRIDE', "override على الحساب يوجّه الأحداث إلى {$r['override']['host']} لا إلى نُسك.", 'أزله إن لم يكن مقصوداً.')
                    : null,
            ])),
        };
    }

    /** @return array{0: int, 1: string} أطول مهلة بين وظائف واتساب واسم صنفها */
    private static function longestJobTimeout(): array
    {
        $max = 0;
        $name = '—';
        foreach (self::WA_JOBS as $class) {
            $timeout = (int) ((new \ReflectionClass($class))->getDefaultProperties()['timeout'] ?? 0);
            if ($timeout > $max) {
                [$max, $name] = [$timeout, class_basename($class)];
            }
        }

        return [$max, $name];
    }

    /** @return array{section: string, level: string, code: string, message: string, advice: ?string} */
    private static function item(string $section, string $level, string $code, string $message, ?string $advice = null): array
    {
        return ['section' => $section, 'level' => in_array($level, ['ok', 'warn', 'error', 'skip'], true) ? $level : 'warn',
            'code' => $code, 'message' => $message, 'advice' => $advice];
    }

    /** ثوانٍ بكسور، من ساعة Carbon (تتقدّم في الاختبارات بـ travel) */
    private static function clock(): float
    {
        return now()->getPreciseTimestamp(3) / 1000;
    }
}
