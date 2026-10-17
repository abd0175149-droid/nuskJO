<?php

namespace App\Services\WhatsApp;

use App\Models\WaBotSetting;
use App\Services\Ops\OpsAlerts;
use App\Services\Ops\OpsHealth;
use App\Support\FeatureFlags;
use Illuminate\Support\Facades\Cache;

/**
 * فحوص ميتا للقراءة (E08): الرمز يومياً (debug_token) وصحة الإرسال كل ساعة (health_status).
 * تنبّه المدير ولا تغيّر wa_suspended أبداً (الإيقاف الآلي لأحداث الحساب وحدها). لا تخزّن رمزاً ولا رداً خاماً
 * ولا معرّفات كيانات: الحالة والتواريخ والصلاحيات الناقصة وأخطاء الكيانات كما شرحتها ميتا.
 * انقطاع الاتصال لا ينبّه إلا بعد مرتين متتاليتين.
 * E22 (العلم channel_health): فحص القناة مرجع آلة الحالات (ChannelHealth::fromPoll)، والرمز الباطل انتقال TOKEN_INVALID،
 * وتنبيهات الحجب والقيد والرمز تصدر من الانتقال وحده.
 * E23 (العلم channel_monitor): عقدة الرقم بحقول أكثر (الحالة والاسم والإنتاجية وwebhook_configuration)، واشتراكات الويبهوك
 * كل ساعة (subscribed_apps و/{app}/subscriptions)، وتنبيه الانحراف مرة لكل تغيّر، واكتشاف WABA. كلها GET عبر الناقل
 * (WhatsAppTransport ثم GraphClient)، ولا رابط كامل يُحفظ: المضيف وهل هو رابطنا فقط.
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

    /** آخر فحص قناة وصل إلى ميتا (ردّت، ولو بخطأ) */
    private bool $reached = false;

    public function __construct(private OpsAlerts $alerts) {}

    /** ملخصات آخر فحوص ميتا وآخر خطأ اعتماد عند الإرسال لبطاقة الإعدادات (لا رمز ولا سر) */
    public static function summary(WaBotSetting $s): array
    {
        return [
            'enabled' => (bool) config('ops.meta_checks'),
            'ready' => $s->metaChecksReady(),
            'credentials' => $s->wa_credentials_status,
            'channel' => $s->wa_channel_health,
            'last_account_error' => Cache::get('wa:last_account_error'),
        ];
    }

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

        // E22: الرمز الباطل انتقال TOKEN_INVALID (ChannelHealth ينبّه مرة واحدة، فلا تنبيه token_invalid هنا)
        if (isset($issues['token_invalid']) && ChannelHealth::enabled()) {
            rescue(fn () => ChannelHealth::fromTokenCheck(), null, false);
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

    /**
     * @return array<string, mixed> الملخص المحفوظ في wa_channel_health. مع channel_health يمر على ChannelHealth::fromPoll
     *                              (مرجع الحالة)، وhumanInitiated للفحص الذي بدأه إنسان (حفظ رمز جديد، «افحص الآن»، الرفع اليدوي)
     */
    public function channel(bool $humanInitiated = false): array
    {
        $this->reached = false;
        $summary = $this->probeChannel();
        // فشل الاتصال يحفظ ملخص آخر فحص وصل (last_known)، فلا يُقرأ بعده كأنه نتيجة جديدة
        if (!$this->reached) {
            return $summary;
        }
        try {
            ChannelHealth::recordPoll($summary);
            ChannelHealth::fromPoll($summary, $humanInitiated);
        } catch (\Throwable $e) {
            WaLog::error('health.poll_apply_failed', ['type' => class_basename($e)]);
        }

        return $summary;
    }

    /** @return array<string, mixed> */
    private function probeChannel(): array
    {
        $s = WaBotSetting::current();
        if (!$s->channelReady()) {
            return $this->save($s, 'wa_channel_health', ['state' => 'not_configured', 'reasons' => []]);
        }

        $monitor = self::monitorOn();
        $r = $monitor ? self::phoneNode(15) : WhatsAppChannel::healthStatus();
        if (!$r['reached']) {
            return $this->unreachable($s, 'wa_channel_health', 'channel', self::CHANNEL_ALERTS);
        }
        $this->reached = true;
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

        $details = [
            'can_send_message' => $overall,
            'quality_rating' => $quality,
            'verified_name' => data_get($r['json'], 'verified_name'),
            'entities' => $entities,
        ];
        if ($monitor) {
            // E23: اكتشاف WABA من الكيانات قبل المقارنة، وحالة الرقم، وملخص الويبهوك (العقدة + آخر قراءة للاشتراكات)
            $s = $this->discoverWaba($s, (array) ($health['entities'] ?? []));
            $details['number'] = self::numberSummary($r['json']);
            $details['webhook_node'] = $r['optional_dropped'] ? null : self::nodeWebhook($r['json']);
            $details['webhook'] = self::evaluateWebhookConfig($details['webhook_node'], OpsHealth::store()->get('wa:probe:subs'));
        }
        // E22: مقارنة حساب الرقم عند ميتا بمعرّفنا المضبوط أو المتعلَّم، بلا حفظ المعرّف نفسه
        $details['waba_match'] = $this->wabaMatch($s, (array) ($health['entities'] ?? []));
        // E23: بلا آلة الحالات (channel_health مطفأ) ينبّه المراقب نفسه عند الاختلاف، ولا يُكتب شيء
        if ($monitor && $details['waba_match'] === false && !ChannelHealth::enabled()) {
            $this->alerts->notifyAdmins('health:waba_mismatch', '⚠️ معرّف حساب الأعمال لا يطابق فحص ميتا',
                'معرّف WABA المضبوط أو المتعلَّم يختلف عن حساب الرقم في health_status. راجع WA_WABA_ID في الإعدادات.', ChannelHealth::URL);
        }

        return $this->conclude($s, 'wa_channel_health', 'channel', $this->worst($issues), array_keys($issues), $details, self::CHANNEL_ALERTS);
    }

    /** null: لا معرّف عندنا أو لا كيان WABA في الرد */
    private function wabaMatch(WaBotSetting $s, array $entities): ?bool
    {
        $ours = WebhookFence::account($s)['id'] ?? ((string) $s->wa_waba_id !== '' ? (string) $s->wa_waba_id : null);
        foreach ($entities as $e) {
            $id = data_get($e, 'id');
            if (strtoupper((string) data_get($e, 'entity_type')) === 'WABA' && is_scalar($id) && (string) $id !== '' && $ours !== null) {
                return (string) $id === $ours;
            }
        }

        return null;
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
        // E22: الحجب والقيد الجزئي والرمز الباطل وعودة القناة ينبّه بها انتقال ChannelHealth مرة واحدة
        $owned = ChannelHealth::enabled() ? ['blocked', 'limited', 'token_invalid'] : [];

        if (in_array($state, ['warn', 'critical'], true)) {
            foreach ($reasons as $reason) {
                if (isset($messages[$reason]) && !in_array($reason, $owned, true)) {
                    $this->alerts->notifyAdmins("meta:{$kind}:{$state}:{$reason}", ...$messages[$reason]);
                }
            }
        }
        if ($state === 'ok' && $previous === 'critical' && !($kind === 'channel' && $owned !== [])) {
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
            // نتيجة فحص لا تعديل مدير: بلا رفع النسخة وبلا تدقيق
            Settings\SettingsWriter::apply([$column => $summary], null, null, bumpVersion: false, audit: 'none');
        } catch (\Throwable $e) {
            // قبل الهجرة 2026_10_14_000008 لا عمود: الفحص لا يكسر المجدول
            WaLog::error('meta.check.save_failed', ['type' => $e::class]);
        }

        return $summary;
    }

    // ==================== E23: المراقب الدوري والقراءات الرسمية (GET فقط) ====================

    /** حقول عقدة الرقم الثابتة */
    public const NODE_FIELDS = 'health_status,quality_rating,status,name_status,throughput,verified_name,display_phone_number';

    /** حقول قد لا يعرفها إصدار Graph: تُطلب، وعند الخطأ 100 يُعاد الطلب مرة واحدة دونها */
    private const NODE_OPTIONAL = ['webhook_configuration'];

    /** حد رسائل الأعمال: مثاله في الوثيقة على v25.0، فلا يُطلب قبله */
    private const LIMIT_FIELD = 'whatsapp_business_manager_messaging_limit';

    private const LIMIT_SINCE = 25;

    /** عناوين تنبيه الانحراف (مرة لكل تغيّر فعلي في الانحراف، لا في كل دورة) */
    private const DRIFT_TITLES = [
        'callback' => '⚠️ ويبهوك ميتا لا يشير إلى نُسك',
        'fields' => '⚠️ حقول غير مشتركة في ويبهوك ميتا',
        'app' => '⚠️ تطبيقنا غير مشترك في حساب واتساب للأعمال',
    ];

    /** العلم channel_monitor: القراءات الإضافية كل 30 دقيقة والاشتراكات كل ساعة وتنبيه الانحراف */
    public static function monitorOn(): bool
    {
        return FeatureFlags::on('channel_monitor');
    }

    /**
     * دورة whatsapp:health (العلم channel_monitor): الاشتراكات إن مضت ساعة على آخر قراءة (أو --force)، ثم عقدة الرقم
     * وآلة الحالات (channel)، ثم تنبيه الانحراف. الرمز من فحص E08 اليومي (لا debug_token هنا)، والإصدار من GraphVersions.
     *
     * @return array{channel: array, subscriptions_checked: bool, webhook: ?array, credentials: ?array, graph: array}
     */
    public function monitor(bool $force = false): array
    {
        // العقدة أولاً: منها يُكتشف WABA الذي تحتاجه قراءة subscribed_apps
        $channel = $this->channel();
        $checked = false;
        if (WaBotSetting::current()->channelReady() && ($force || OpsHealth::store()->get('wa:probe:subs') === null)
            && ($subs = self::refreshSubscriptions(15)) !== null) {
            $checked = true;
            $node = array_key_exists('webhook_node', $channel) ? $channel['webhook_node'] : null;
            $channel = $this->saveWebhook(self::evaluateWebhookConfig($node, $subs));
        }
        $webhook = is_array($channel['webhook'] ?? null) ? $channel['webhook'] : null;
        if ($webhook !== null && ($channel['state'] ?? null) !== 'unreachable') {
            $this->alertDrift($webhook);
        }

        return [
            'channel' => $channel,
            'subscriptions_checked' => $checked,
            'webhook' => $webhook,
            'credentials' => WaBotSetting::current()->wa_credentials_status,
            'graph' => GraphVersions::status(config('services.whatsapp.graph_version')),
        ];
    }

    /**
     * عقدة الرقم: الصحة والجودة والحالة والاسم والإنتاجية وwebhook_configuration (وحد الرسائل من v25.0). لا يحفظ شيئاً.
     *
     * @return array{reached: bool, status: ?int, json: array<string, mixed>, optional_dropped: bool}
     */
    public static function phoneNode(int $timeout = 15): array
    {
        $s = WaBotSetting::current();
        $optional = self::NODE_OPTIONAL;
        if (self::versionAtLeast(self::LIMIT_SINCE)) {
            $optional[] = self::LIMIT_FIELD;
        }
        $segments = [(string) $s->phoneNumberId()];
        $r = self::get($segments, ['fields' => self::NODE_FIELDS.','.implode(',', $optional)], (string) $s->token(), $timeout, 'phone_node');
        // حقل لا يعرفه الإصدار (100): مرة واحدة بلا الاختيارية. رفض appsecret_proof (100 أيضاً) ليس منها
        if ($r['reached'] && $r['status'] !== 200 && (int) data_get($r['json'], 'error.code') === 100 && !$r['proof_rejected']) {
            return self::get($segments, ['fields' => self::NODE_FIELDS], (string) $s->token(), $timeout, 'phone_node') + ['optional_dropped' => true];
        }

        return $r + ['optional_dropped' => false];
    }

    /**
     * GET /{WABA_ID}/subscribed_apps: هل تطبيقنا مشترك في الحساب، وهل على الحساب override_callback_uri (المضيف وحده).
     *
     * @return array{state: string, our_app_subscribed?: ?bool, apps?: int, override?: ?array, error_code?: ?int}
     */
    public static function subscribedApps(int $timeout = 15): array
    {
        $s = WaBotSetting::current();
        if (!$s->channelReady()) {
            return ['state' => 'not_configured'];
        }
        $waba = self::wabaId($s);
        if ($waba === null) {
            return ['state' => 'no_waba'];
        }
        $r = self::get([$waba, 'subscribed_apps'], [], (string) $s->token(), $timeout, 'subscribed_apps');
        if (!$r['reached']) {
            return ['state' => 'unreachable'];
        }
        if ($r['status'] !== 200) {
            return ['state' => 'error', 'error_code' => (int) data_get($r['json'], 'error.code') ?: null];
        }

        $appId = (string) $s->appId();
        $ours = $appId === '' ? null : false;
        $override = null;
        $rows = (array) data_get($r['json'], 'data', []);
        foreach ($rows as $row) {
            if ($appId !== '' && (string) data_get($row, 'whatsapp_business_api_data.id') === $appId) {
                $ours = true;
                $override = self::urlSummary(data_get($row, 'override_callback_uri'));
            }
        }

        return ['state' => 'ok', 'our_app_subscribed' => $ours, 'apps' => count($rows), 'override' => $override];
    }

    /**
     * GET /{APP_ID}/subscriptions برمز التطبيق (APP_ID|APP_SECRET في ترويسة Authorization كما في debug_token). نجاحه يثبت
     * صحة App Secret المخزّن. يعيد لكائن whatsapp_business_account: الرابط (المضيف وهل هو رابطنا) والتفعيل والحقول بإصداراتها.
     *
     * @return array{state: string, found?: bool, callback?: ?array, active?: bool, fields?: array<string, ?string>, error_code?: ?int}
     */
    public static function appSubscriptions(int $timeout = 15): array
    {
        $s = WaBotSetting::current();
        $appId = (string) $s->appId();
        $secret = (string) $s->appSecret();
        if ($appId === '' || $secret === '') {
            return ['state' => 'not_possible'];
        }
        $r = self::get([$appId, 'subscriptions'], [], $appId.'|'.$secret, $timeout, 'app_subscriptions');
        if (!$r['reached']) {
            return ['state' => 'unreachable'];
        }
        if ($r['status'] !== 200) {
            $code = (int) data_get($r['json'], 'error.code') ?: null;

            return ['state' => in_array($code, [190, 102, 101], true) ? 'app_credentials_invalid' : 'error', 'error_code' => $code];
        }

        foreach ((array) data_get($r['json'], 'data', []) as $row) {
            if (data_get($row, 'object') !== 'whatsapp_business_account') {
                continue;
            }
            $fields = [];
            foreach ((array) data_get($row, 'fields', []) as $f) {
                $name = WebhookFields::name(data_get($f, 'name'));
                if ($name !== '') {
                    $version = data_get($f, 'version');
                    $fields[$name] = is_string($version) && GraphVersions::isValid($version) ? $version : null;
                }
            }

            return ['state' => 'ok', 'found' => true, 'callback' => self::urlSummary(data_get($row, 'callback_url')),
                'active' => (bool) data_get($row, 'active', false), 'fields' => $fields];
        }

        return ['state' => 'ok', 'found' => false, 'callback' => null, 'active' => false, 'fields' => []];
    }

    /**
     * أرقام الحساب وحالتها (GET /{WABA_ID}/phone_numbers)، مخزّنة ساعة. بلا معرّفات: رقمنا يُعلَّم بـ ours.
     * لا إنشاء ولا حذف ولا register من نظامنا: إضافة رقم أو حذفه من WhatsApp Manager.
     *
     * @return array{state: string, at?: string, numbers?: list<array<string, mixed>>, error_code?: ?int}
     */
    public static function phoneNumbers(int $timeout = 15, bool $fresh = false): array
    {
        $store = OpsHealth::store();
        if (!$fresh && is_array($cached = $store->get('wa:probe:numbers'))) {
            return $cached;
        }
        $s = WaBotSetting::current();
        if (!$s->channelReady()) {
            return ['state' => 'not_configured'];
        }
        $waba = self::wabaId($s);
        if ($waba === null) {
            return ['state' => 'no_waba'];
        }
        $r = self::get([$waba, 'phone_numbers'], [
            'fields' => 'id,display_phone_number,verified_name,quality_rating,code_verification_status,status,name_status,throughput',
        ], (string) $s->token(), $timeout, 'phone_numbers');
        if (!$r['reached']) {
            return ['state' => 'unreachable'];
        }
        if ($r['status'] !== 200) {
            return ['state' => 'error', 'error_code' => (int) data_get($r['json'], 'error.code') ?: null];
        }

        $ours = (string) $s->phoneNumberId();
        $numbers = array_map(fn ($n) => [
            'display' => self::scalar(data_get($n, 'display_phone_number'), 32),
            'verified_name' => self::scalar(data_get($n, 'verified_name'), 120),
            'quality_rating' => self::scalar(data_get($n, 'quality_rating'), 20),
            'status' => self::scalar(data_get($n, 'status'), 30),
            'name_status' => self::scalar(data_get($n, 'name_status'), 30),
            'code_verification_status' => self::scalar(data_get($n, 'code_verification_status'), 30),
            'throughput' => self::scalar(data_get($n, 'throughput.level'), 30),
            'ours' => $ours !== '' && (string) data_get($n, 'id') === $ours,
        ], array_values((array) data_get($r['json'], 'data', [])));
        $result = ['state' => 'ok', 'at' => now()->toIso8601String(), 'numbers' => array_slice($numbers, 0, 25)];
        $store->put('wa:probe:numbers', $result, (int) config('whatsapp.monitor.probe_ttl.numbers', 3600));

        return $result;
    }

    /**
     * قراءة الاشتراكين وتخزينهما ساعة ('wa:probe:subs')، أو null إن لم تصل ميتا في أيٍّ منهما (فلا يُخزَّن فشل).
     *
     * @return array{waba: array, app: array, at: string}|null
     */
    public static function refreshSubscriptions(int $timeout = 15): ?array
    {
        $subs = ['waba' => self::subscribedApps($timeout), 'app' => self::appSubscriptions($timeout), 'at' => now()->toIso8601String()];
        if ($subs['waba']['state'] === 'unreachable' && in_array($subs['app']['state'], ['unreachable', 'not_possible'], true)) {
            return null;
        }
        OpsHealth::store()->put('wa:probe:subs', $subs, (int) config('whatsapp.monitor.probe_ttl.subs', 3600));

        return $subs;
    }

    /**
     * تقييم الويبهوك من عقدة الرقم (webhook_configuration) وآخر قراءة للاشتراكات. الانحرافات في issues:
     * callback_mismatch (رابط التطبيق غير رابطنا)، override:phone_number أو override:waba (يوجّه لغيرنا)،
     * missing:{field} (حقل مطلوب غير مشترك)، inactive وnot_subscribed (لا اشتراك للحساب في التطبيق)، app_not_subscribed.
     * ok=null حين لا نعرف شيئاً بعد.
     *
     * @param  array|null  $node  من nodeWebhook()، أو null إن لم يتح الحقل على هذا الإصدار
     * @param  array|null  $subs  {waba, app, at} من refreshSubscriptions
     */
    public static function evaluateWebhookConfig(?array $node, ?array $subs): array
    {
        $app = is_array($subs['app'] ?? null) && ($subs['app']['state'] ?? null) === 'ok' ? $subs['app'] : null;
        $waba = is_array($subs['waba'] ?? null) && ($subs['waba']['state'] ?? null) === 'ok' ? $subs['waba'] : null;
        $issues = [];

        // رابط التطبيق: من /subscriptions (المرجع) وإلا من application في عقدة الرقم
        $callback = ($app['found'] ?? false) ? ($app['callback'] ?? null) : ($node['application'] ?? null);
        if ($callback !== null && !$callback['ours']) {
            $issues[] = 'callback_mismatch';
        }

        // الأولوية عند ميتا: الرقم ثم الحساب ثم التطبيق. override لرابطنا لا يُعدّ انحرافاً
        $overrides = [];
        foreach (['phone_number' => $node['phone_number'] ?? null, 'waba' => $node['waba'] ?? ($waba['override'] ?? null)] as $level => $u) {
            if ($u !== null) {
                $overrides[] = ['level' => $level, 'host' => $u['host'], 'ours' => $u['ours']];
                if (!$u['ours']) {
                    $issues[] = 'override:'.$level;
                }
            }
        }

        $missing = null;
        if ($app !== null) {
            if (!$app['found']) {
                $issues[] = 'not_subscribed';
            } else {
                $missing = array_values(array_diff(self::requiredFields(), array_keys($app['fields'])));
                foreach ($missing as $field) {
                    $issues[] = 'missing:'.$field;
                }
                if (!$app['active']) {
                    $issues[] = 'inactive';
                }
            }
        }
        if (($waba['our_app_subscribed'] ?? null) === false) {
            $issues[] = 'app_not_subscribed';
        }

        $known = $node !== null || $app !== null || $waba !== null;

        return [
            'ok' => $known ? $issues === [] : null,
            'issues' => $issues,
            'callback' => $callback,
            'overrides' => $overrides,
            'missing_fields' => $missing,
            'field_versions' => $app['fields'] ?? [],
            'webhook_version' => config('services.whatsapp.webhook_version') ?: null,
            'our_app_subscribed' => $waba['our_app_subscribed'] ?? null,
            'configuration_available' => $node !== null,
            'subscriptions' => [
                'at' => $subs['at'] ?? null,
                'app' => $subs['app']['state'] ?? null,
                'waba' => $subs['waba']['state'] ?? null,
            ],
        ];
    }

    /** @return list<string> */
    public static function requiredFields(): array
    {
        return array_values(array_filter((array) config('whatsapp.monitor.required_webhook_fields', []), 'is_string'));
    }

    /** رابط ويبهوكنا كما يجب أن يكون عند ميتا */
    public static function ourWebhookUrl(): string
    {
        return rtrim((string) config('app.url'), '/').'/api/whatsapp/webhook';
    }

    /**
     * رابط من ميتا لا يُحفظ كاملاً: المضيف وحده، وهل هو رابطنا (المضيف والمسار، بلا الاستعلام).
     *
     * @return array{host: string, ours: bool}|null
     */
    public static function urlSummary(mixed $url): ?array
    {
        if (!is_string($url) || trim($url) === '') {
            return null;
        }
        $parts = parse_url(trim($url));
        $host = strtolower((string) ($parts['host'] ?? ''));
        $ours = parse_url(self::ourWebhookUrl());
        $same = $host !== '' && $host === strtolower((string) ($ours['host'] ?? ''))
            && strtolower((string) ($parts['scheme'] ?? '')) === strtolower((string) ($ours['scheme'] ?? ''))
            && rtrim((string) ($parts['path'] ?? ''), '/') === rtrim((string) ($ours['path'] ?? ''), '/');

        return ['host' => mb_substr($host !== '' ? $host : '?', 0, 120), 'ours' => $same];
    }

    /** webhook_configuration من عقدة الرقم: المضيف وحده لكل مستوى @return array{phone_number: ?array, waba: ?array, application: ?array} */
    public static function nodeWebhook(array $json): array
    {
        $conf = (array) data_get($json, 'webhook_configuration', []);

        return [
            'phone_number' => self::urlSummary($conf['phone_number'] ?? null),
            'waba' => self::urlSummary($conf['whatsapp_business_account'] ?? null),
            'application' => self::urlSummary($conf['application'] ?? null),
        ];
    }

    /** تقييم الويبهوك بعد قراءة الاشتراكات: يُدمج في ملخص wa_channel_health المحفوظ (بلا رفع النسخة) */
    private function saveWebhook(array $webhook): array
    {
        $s = WaBotSetting::current();
        $summary = array_merge((array) ($s->wa_channel_health ?? []), ['webhook' => $webhook]);
        try {
            Settings\SettingsWriter::apply(['wa_channel_health' => $summary], null, null, bumpVersion: false, audit: 'none');
        } catch (\Throwable $e) {
            WaLog::error('meta.check.save_failed', ['type' => $e::class]);
        }

        return $summary;
    }

    /** حالة الرقم عند ميتا للعرض: CONNECTED شرط للإرسال والاستقبال */
    private static function numberSummary(array $json): array
    {
        $limit = data_get($json, self::LIMIT_FIELD);

        return [
            'status' => self::scalar(data_get($json, 'status'), 30),
            'name_status' => self::scalar(data_get($json, 'name_status'), 30),
            'throughput' => self::scalar(data_get($json, 'throughput.level'), 30),
            'messaging_limit' => is_scalar($limit) ? self::scalar($limit, 40) : self::scalar(data_get($limit, 'current_limit') ?? data_get($limit, 'tier'), 40),
        ];
    }

    /** الانحراف: تنبيه واحد لكل بصمة جديدة، وتنبيه عودة واحد حين يزول */
    private function alertDrift(array $webhook): void
    {
        if (($webhook['ok'] ?? null) === null) {
            return;
        }
        $store = OpsHealth::store();
        $issues = (array) ($webhook['issues'] ?? []);
        $previous = $store->get('wa:webhook:drift');
        if ($issues === []) {
            if ($previous !== null) {
                $store->forget('wa:webhook:drift');
                $this->alerts->notifyAdmins('wa:webhook-drift:ok', '✅ ويبهوك ميتا يشير إلى نُسك', 'زال الانحراف في إعداد الويبهوك عند ميتا.', ChannelHealth::URL);
            }

            return;
        }

        $hosts = array_map(fn ($o) => $o['level'].'@'.$o['host'], (array) ($webhook['overrides'] ?? []));
        sort($issues);
        $hash = substr(sha1(json_encode([$issues, $webhook['callback']['host'] ?? null, $hosts])), 0, 16);
        if ($hash === $previous) {
            return;
        }
        $store->forever('wa:webhook:drift', $hash);
        WaLog::warning('webhook.drift', ['reason' => mb_substr(implode(',', $issues), 0, 120)]);
        [$title, $body] = self::driftMessage($webhook);
        $this->alerts->notifyAdmins('wa:webhook-drift:'.$hash, $title, $body, ChannelHealth::URL);
    }

    /** @return array{0: string, 1: string} العنوان بحسب أخطر انحراف، والنص يجمعها كلها */
    public static function driftMessage(array $webhook): array
    {
        $lines = [];
        $title = null;
        $callback = $webhook['callback'] ?? null;
        if (in_array('callback_mismatch', $webhook['issues'], true)) {
            $title = self::DRIFT_TITLES['callback'];
            $lines[] = 'رابط الويبهوك عند ميتا على '.($callback['host'] ?? '?').' لا على نُسك — صحّحه من App Dashboard › WhatsApp › Configuration.';
        }
        foreach ((array) ($webhook['overrides'] ?? []) as $o) {
            if (!$o['ours']) {
                $title ??= self::DRIFT_TITLES['callback'];
                $lines[] = 'override على '.($o['level'] === 'phone_number' ? 'الرقم' : 'الحساب').' يوجّه الأحداث إلى '.$o['host'].' — أزله إن لم يكن مقصوداً.';
            }
        }
        $missing = (array) ($webhook['missing_fields'] ?? []);
        if ($missing !== []) {
            $title ??= count($missing) === 1 ? '⚠️ حقل '.$missing[0].' غير مشترك في ويبهوك ميتا' : self::DRIFT_TITLES['fields'];
            $lines[] = 'حقول غير مشتركة: '.implode('، ', $missing).' — اشترك فيها من App Dashboard › Webhook fields.';
        }
        if (array_intersect(['app_not_subscribed', 'not_subscribed', 'inactive'], $webhook['issues'])) {
            $title ??= self::DRIFT_TITLES['app'];
            $lines[] = 'تطبيقنا غير مشترك في أحداث حساب واتساب للأعمال (أو الاشتراك موقوف) — لا تصل الرسائل.';
        }

        return [$title ?? self::DRIFT_TITLES['callback'], mb_substr(implode(' ', $lines), 0, 400)];
    }

    /**
     * اكتشاف WABA من كيانات health_status: إن لم يكن معرّف في البيئة ولا في الإعدادات يُحفظ بالمصدر discovered
     * (يُعرض ولا يفرض السياج حتى يؤكده المالك يدوياً، E20). المضبوط لا يُكتب فوقه.
     */
    private function discoverWaba(WaBotSetting $s, array $entities): WaBotSetting
    {
        if (WebhookFence::account($s)['id'] !== null || (string) $s->wa_waba_id !== '') {
            return $s;
        }
        foreach ($entities as $e) {
            $id = data_get($e, 'id');
            if (strtoupper((string) data_get($e, 'entity_type')) === 'WABA' && is_scalar($id) && preg_match('/^\d{5,25}$/', (string) $id)) {
                try {
                    Settings\SettingsWriter::apply(['wa_waba_id' => (string) $id, 'wa_waba_id_source' => 'discovered'], null, null, bumpVersion: false, audit: 'none');
                    OpsHealth::store()->forever('wa:waba:detected', (string) $id);
                    WaLog::info('waba.discovered', ['source' => 'health_status']);
                } catch (\Throwable $ex) {
                    WaLog::error('waba.discover_failed', ['type' => class_basename($ex)]);
                }

                return WaBotSetting::current();
            }
        }

        return $s;
    }

    /** المعرّف المعروف: البيئة، ثم المحفوظ (يدوي أو متعلَّم أو مكتشف) */
    private static function wabaId(WaBotSetting $s): ?string
    {
        $id = WebhookFence::account($s)['id'] ?? (string) $s->wa_waba_id;

        return $id !== '' && preg_match('/^\d{1,30}$/', $id) ? $id : null;
    }

    private static function versionAtLeast(int $major): bool
    {
        return preg_match('/^v(\d+)\.\d+$/', (string) config('services.whatsapp.graph_version'), $m) === 1 && (int) $m[1] >= $major;
    }

    private static function scalar(mixed $value, int $max): ?string
    {
        return is_scalar($value) && (string) $value !== '' ? mb_substr((string) $value, 0, $max) : null;
    }

    /**
     * نداء GET واحد عبر الناقل. الاستثناء يُسجَّل باسم صنفه وحده (ConnectionException يضم الرابط)، ولا رمز في الرابط أبداً.
     *
     * @param  list<string>  $segments
     * @return array{reached: bool, status: ?int, json: array<string, mixed>, proof_rejected: bool}
     */
    private static function get(array $segments, array $query, #[\SensitiveParameter] string $token, int $timeout, string $name): array
    {
        try {
            $res = app(Transport\WhatsAppTransport::class)->request('GET', $segments, $query, $token, max(1, $timeout), max(1, min(5, $timeout)));
        } catch (\Throwable $e) {
            WaLog::error('meta.check.unreachable', ['type' => class_basename($e), 'field' => $name]);

            return ['reached' => false, 'status' => null, 'json' => [], 'proof_rejected' => false];
        }
        if (!$res->successful()) {
            WaLog::warning('meta.check.failed', ['field' => $name, 'http' => $res->status(), 'meta_code' => (int) data_get($res->json(), 'error.code') ?: null]);
        }

        return ['reached' => true, 'status' => $res->status(), 'json' => (array) $res->json(), 'proof_rejected' => Graph\GraphClient::isProofRejection($res)];
    }
}
