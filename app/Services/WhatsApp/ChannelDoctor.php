<?php

namespace App\Services\WhatsApp;

use App\Models\User;
use App\Models\WaBotSetting;
use App\Services\Ops\OpsAlerts;
use App\Services\Ops\OpsHealth;
use App\Services\Rendering\HeadlessChromium;
use App\Support\FeatureFlags;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * فحص صحة القناة والإعداد (whatsapp:doctor وشريط صفحة الإعدادات). قراءة فقط، ولا تُعاد أي قيمة سرية:
 * كل بند مستوى ورمز ورسالة عربية فيها النصيحة.
 *
 * E23: قسمان في run() — local (تعارضات البيئة: الطابور وretry_after والقفل والكاش وSQLite وكروميوم
 * وstorage:link والقرص والناقل وReverb) وremote (بوابة OpenWA ثم كل رقم فعّال: جلسته وجاهزيته وwebhook عليه،
 * بميزانية زمنية كلية). فحوص ميتا (debug_token وعقدة الرقم والاشتراكات) أُزيلت مع ميتا (2026-11-06).
 * كل بند {section, level: ok|warn|error|skip, code, message, advice}. الحصيلة في 'wa:doctor:last' يومين (store).
 */
final class ChannelDoctor
{
    /** دوال JSON التي تعتمدها E21 */
    public const SQLITE_MIN = '3.31.0';

    /** وظائف واتساب: مهلة كل منها يجب أن تقل عن retry_after (تُقرأ بالانعكاس لا بأرقام مكتوبة) */
    private const WA_JOBS = [
        \App\Jobs\ProcessWhatsAppReply::class, \App\Jobs\SendPushNotification::class,
        \App\Jobs\SendOutageNotice::class, \App\Jobs\SendWhatsAppMediaJob::class, \App\Jobs\ArchiveWhatsAppMedia::class,
        \App\Jobs\SendWhatsAppReadReceipt::class, \App\Jobs\DispatchOutboxItem::class, \App\Jobs\BackfillChannel::class,
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
            // سرّ محفوظ بمفتاح آخر (APP_KEY تغيّر): يبدو فارغاً في الواجهة ويتعطل البوت
            foreach (['api_key' => 'مفتاح النموذج', 'fallback_api_key' => 'مفتاح النموذج الاحتياطي'] as $field => $label) {
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
        }

        // مفتاح رقم محفوظ بمفتاح تطبيق آخر: لا إرسال ولا قراءة منه حتى يُصلَح من صفحة الأرقام
        foreach (rescue(fn () => \App\Models\WaChannel::query()->active()->get(['id', 'name', 'api_key']), collect(), false) as $channel) {
            $raw = $channel->getRawOriginal('api_key');
            if ($raw === null || $raw === '') {
                continue;
            }
            try {
                Crypt::decryptString($raw);
            } catch (DecryptException) {
                $add('error', 'CHANNEL_KEY_UNREADABLE', "مفتاح الرقم «{$channel->name}» محفوظ بمفتاح تطبيق آخر — «إصلاح» من صفحة الأرقام يصدر مفتاحاً جديداً.");
            }
        }

        if (app()->isProduction() && (string) config('whatsapp.openwa.admin_key') === '') {
            $add('error', 'OPENWA_ADMIN_KEY_MISSING', 'OPENWA_ADMIN_KEY غير مضبوط: لا إنشاء أرقام ولا webhooks على البوابة.');
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
     * وسر يُفك بمفتاح سابق فقط (تدوير APP_KEY لم يكتمل).
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
        foreach (['api_key', 'fallback_api_key'] as $field) {
            $raw = $settings->getRawOriginal($field);
            if (Settings\AppKeys::decryptsWithCurrent($raw) === false && $settings->decryptOk($field) === true) {
                $out[] = ['level' => 'warn', 'code' => 'SECRET_ON_PREVIOUS_KEY', 'message' => "{$field} يُفك بمفتاح سابق فقط — شغّل whatsapp:reencrypt-secrets قبل حذف APP_PREVIOUS_KEYS."];
            }
        }

        return $out;
    }

    // ==================== E23: الفحص الكامل (local وremote) ====================

    /**
     * الفحص الكامل لـ whatsapp:doctor وزر «شغّل الفحص»: الإعداد (checks وsourceWarnings) والويبهوك (E20) والبيئة
     * والفحوص البعيدة بميزانية كلية بالثواني. لا يرمي، ولا يغيّر شيئاً على البوابة ولا حالة الأرقام.
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
        foreach (rescue(fn () => WebhookStats::checks(WebhookStats::summary()), [], false) as $c) {
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
        $out[] = self::item('local', $env(!$linked), 'STORAGE_LINK', $linked ? 'public/storage يشير إلى storage/app/public.' : 'public/storage ليس رابطاً إلى storage/app/public: صور البطاقات والحالة لا تُعرض.', $linked ? null : 'شغّل php artisan storage:link (الإقلاع يفعلها).');

        $free = @disk_free_space(storage_path());
        $total = @disk_total_space(storage_path());
        if ($free !== false && $total) {
            $pct = (int) floor($free * 100 / $total);
            $out[] = $pct < 10
                ? self::item('local', $prod ? ($pct < 5 ? 'error' : 'warn') : 'skip', 'DISK_LOW', "المساحة الحرة {$pct}% فقط على قرص التخزين.", 'نظّف النسخ القديمة والسجلات قبل أن تتوقف الكتابة.')
                : self::item('local', 'ok', 'DISK_LOW', "المساحة الحرة {$pct}%.");
        }

        $configured = (string) config('whatsapp.transport', 'openwa');
        $bound = rescue(fn () => app(Transport\ChannelTransport::class)->name(), 'openwa', false);
        $out[] = match (true) {
            $configured === 'log' && $prod => self::item('local', 'error', 'TRANSPORT', 'WA_TRANSPORT=log مرفوض في الإنتاج: رُبطت بوابة OpenWA بدلاً منه.', 'احذف WA_TRANSPORT أو اجعله openwa.'),
            $bound === 'log' => self::item('local', 'warn', 'TRANSPORT', 'الناقل log: لا شيء يصل واتساب (اختبار وتطوير محلي فقط).'),
            !in_array($configured, ['openwa', 'log'], true) => self::item('local', 'warn', 'TRANSPORT', "WA_TRANSPORT={$configured} غير معروف: رُبطت بوابة OpenWA.", 'اجعله openwa.'),
            default => self::item('local', 'ok', 'TRANSPORT', 'الناقل: بوابة OpenWA.'),
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
     * الفحوص البعيدة بالترتيب: البوابة (GET /health)، ثم لكل رقم فعّال جلسته وجاهزيته وwebhook عليه يطابق عنوانه
     * وأحداثه. لكل نداء مهلة البوابة (config)، وما بعد نفاد الميزانية «لم يُفحص — الوقت». قراءة فقط، بالمفتاح الرئيس.
     *
     * @return list<array<string, ?string>>
     */
    public static function remote(int $budget = 5): array
    {
        $admin = OpenWa\OpenWaClient::admin();
        if (!$admin->configured()) {
            return [self::item('remote', app()->isProduction() ? 'error' : 'skip', 'GATEWAY', 'البوابة غير مضبوطة (OPENWA_URL وOPENWA_ADMIN_KEY): لا فحص بعيد.')];
        }
        $start = self::clock();
        $health = $admin->request('GET', '/health');
        if (!$health->ok) {
            return [self::item('remote', 'error', 'GATEWAY', 'بوابة OpenWA لا تستجيب ('.($health->error?->code ?? 'HTTP '.$health->status()).'): لا يصل ولا يُرسل شيء.',
                'راجع حاوية nuskjo-openwa: docker compose ps ثم docker compose logs nuskjo-openwa.')];
        }
        $version = (string) ($health->json('version') ?? $health->json('info.version') ?? '');
        $out = [self::item('remote', 'ok', 'GATEWAY', 'بوابة OpenWA تستجيب'.($version !== '' ? " (الإصدار {$version})" : '').'.')];

        $channels = \App\Models\WaChannel::query()->active()->orderBy('sort')->orderBy('id')->get();
        if ($channels->isEmpty()) {
            $out[] = self::item('remote', app()->isProduction() ? 'warn' : 'skip', 'CHANNELS', 'لا رقم واتساب فعّالاً.', 'أضف رقماً من صفحة «الأرقام» واربطه.');

            return $out;
        }
        foreach ($channels as $channel) {
            if ($budget - (self::clock() - $start) < 1) {
                $out[] = self::item('remote', 'skip', 'CHANNEL', "«{$channel->name}»: لم يُفحص — الوقت.", 'شغّل الفحص بميزانية أكبر: whatsapp:doctor --budget=20');

                continue;
            }
            try {
                array_push($out, ...self::remoteChannel($admin, $channel));
            } catch (\Throwable $e) {
                WaLog::error('doctor.remote_failed', ['type' => class_basename($e), 'channel_id' => $channel->id]);
                $out[] = self::item('remote', 'warn', 'CHANNEL', "«{$channel->name}»: تعذّر الفحص (".class_basename($e).').');
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

    /** @return list<array<string, ?string>> رقم واحد: الجلسة والجاهزية وwebhook البوابة إليه */
    private static function remoteChannel(OpenWa\OpenWaClient $admin, \App\Models\WaChannel $channel): array
    {
        $name = "«{$channel->name}»";
        $sid = (string) $channel->openwa_session_id;
        if ($sid === '') {
            return [self::item('remote', 'error', 'CHANNEL_SESSION', "{$name} بلا جلسة على البوابة.", '«إصلاح» من صفحة الأرقام ينشئها.')];
        }
        $out = [];
        $out[] = match (true) {
            $channel->isPaused() => self::item('remote', 'warn', 'CHANNEL_READY', "{$name} موقوف مؤقتاً".($channel->pause_reason ? " ({$channel->pause_reason})" : '').': الصادر منه ينتظر في الطابور.'),
            $channel->isReady() => self::item('remote', 'ok', 'CHANNEL_READY', "{$name} متصل وآخر فحص حقيقي له نجح."),
            $channel->isLinked() => self::item('remote', 'warn', 'CHANNEL_READY', "{$name} مربوط لكن آخر فحص حقيقي له لم ينجح مؤخراً.", 'whatsapp:channel-watch يعيد تشغيله بعد إخفاقات متتالية؛ تابع صفحة الأرقام.'),
            default => self::item('remote', 'error', 'CHANNEL_READY', "{$name} غير متصل (الحالة {$channel->status}): لا يصل منه ولا إليه شيء.", 'أعد ربطه برمز QR من صفحة الأرقام.'),
        };

        $res = $admin->request('GET', '/sessions/'.rawurlencode($sid).'/webhooks');
        if (!$res->ok) {
            $out[] = self::item('remote', 'warn', 'CHANNEL_WEBHOOK', "{$name}: تعذّرت قراءة webhooks الجلسة (".($res->error?->code ?? 'HTTP '.$res->status()).').');

            return $out;
        }
        $hooks = (array) ($res->json('data') ?? $res->json('webhooks') ?? $res->json() ?? []);
        $ours = collect(array_is_list($hooks) ? $hooks : [])->first(fn ($h) => is_array($h) && ($h['url'] ?? null) === $channel->webhookUrl());
        if ($ours === null) {
            $out[] = self::item('remote', 'error', 'CHANNEL_WEBHOOK', "{$name}: لا webhook على البوابة إلى نُسك: لا تصل الرسائل الواردة ولا حالات التسليم.", '«إصلاح» من صفحة الأرقام يعيد تسجيله.');

            return $out;
        }
        $missing = array_values(array_diff((array) config('whatsapp.openwa.events', []), (array) ($ours['events'] ?? [])));
        $out[] = match (true) {
            ($ours['active'] ?? $ours['enabled'] ?? true) === false => self::item('remote', 'error', 'CHANNEL_WEBHOOK', "{$name}: webhook البوابة موقوف.", '«إصلاح» من صفحة الأرقام يعيد تسجيله.'),
            in_array('*', (array) ($ours['events'] ?? []), true) || $missing === [] => self::item('remote', 'ok', 'CHANNEL_WEBHOOK', "{$name}: webhook البوابة يصل نُسك بكل الأحداث."),
            default => self::item('remote', in_array('message.received', $missing, true) ? 'error' : 'warn', 'CHANNEL_WEBHOOK', "{$name}: أحداث غير مسجلة: ".implode('، ', $missing).'.', '«إصلاح» من صفحة الأرقام يعيد تسجيله بالأحداث كلها.'),
        };

        return $out;
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
