<?php

namespace App\Services\WhatsApp;

use App\Events\WhatsApp\WaMessageFailed;
use App\Events\WhatsApp\WaMessageSent;
use App\Models\WaBotSetting;
use App\Models\WaConversation;
use App\Models\WaMessage;
use App\Support\SecretRedactor;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;

/**
 * بوّابة الإرسال الوحيدة إلى ميتا — كل صادر (بوت/موظف/نظام) يمرّ من هنا.
 *
 * السائق الوحيد المسموح هو WhatsApp Cloud API الرسمية (Graph API). يُمنع إضافة
 * whatsapp-web.js أو Baileys أو أي عميل غير رسمي، لأن ذلك يخالف شروط واتساب ويعرّض الرقم الوحيد للحظر.
 *
 * مرفوض عمداً، ويعيد UNSUPPORTED لا نجاحاً وهمياً: القوالب (قرار المالك)، والمجموعات، والحالات (stories).
 *
 * الأقفال بالترتيب:
 *   0) الموظف يرى المحادثة (لرسائل الموظف وحدها)
 *   1) القناة مهيّأة (رمز + معرّف رقم)
 *   2) قفل الإرسال الطارئ (wa_suspended)، ثم حجب ميتا (wa_health=BLOCKED دائماً، وTOKEN_INVALID بعلم؛ E22)
 *   3) نافذة 24 ساعة من آخر رسالة واردة — لا استثناء لأحد
 *   3ب) المحظور رسمياً (E31): لا شيء يُرسل له
 *
 * نداءات إدارة جهات الاتصال (block_users) خارج dispatch، لأنها ليست رسائل، لكنها تحترم القناة والإيقاف الطارئ
 * ونافذة ميتا. وملف الأعمال (whatsapp_business_profile) قراءة بالقفل 1، وكتابة بالقفلين 1 و2. ولا كود هنا يغيّر
 * اسم العرض أو يعيد تسجيل الرقم أو يحجز اسم مستخدم: ذلك من WhatsApp Manager وحده (NoDisplayNameApiTest).
 * ورموز QR (message_qrdls، E38) خارج dispatch كذلك: العلم wa_qr_links والقفلان 1 و2، بلا نشر حالة آلياً أبداً.
 *
 * إشعار القراءة ومؤشر الكتابة (E35) ليسا رسالة: markRead خارج dispatch، بالقفلين 1 و2 وحدهما.
 *
 * كل نداء Graph عبر Graph\GraphClient (الإصدار من الإعداد، ومقاطع مرمَّزة، ولا تحويلات، وappsecret_proof خلف
 * WA_APPSECRET_PROOF)، وكل خطأ ميتا يصير MetaError من الجدول الوحيد MetaErrorCatalog (E18).
 *
 * المغلف الموحّد (E32): كل صادر يمر عبر send() ثم dispatch()، والدوال sendText وsendImage أغلفة رقيقة فوقه.
 * ممنوع نداء /messages خارج dispatch (NoDirectGraphMessagesTest)، والملف يُرفع بـ uploadMedia بعد preflight()
 * فلا يبقى ملف يتيم عند ميتا لمحادثة مغلقة أو موقوفة.
 */
class WhatsAppChannel
{
    public const ERR_NOT_CONFIGURED = 'CHANNEL_NOT_CONFIGURED';
    public const ERR_SUSPENDED = 'SENDING_SUSPENDED';
    public const ERR_WINDOW = 'WINDOW_EXPIRED';
    public const ERR_OUT_OF_SCOPE = 'OUT_OF_SCOPE';
    public const ERR_UNSUPPORTED = 'UNSUPPORTED';
    public const ERR_CONFIG = 'GRAPH_CONFIG_INVALID';
    public const ERR_TOKEN = 'TOKEN_INVALID';
    public const ERR_RATE = 'RATE_LIMITED';
    public const ERR_ACCOUNT = 'ACCOUNT_RESTRICTED';
    public const ERR_PROOF = 'APPSECRET_PROOF_INVALID';
    public const ERR_TOO_LARGE = 'TOO_LARGE';
    public const ERR_MEDIA_HOST = 'MEDIA_HOST_BLOCKED';
    public const ERR_MEDIA_INTEGRITY = 'MEDIA_INTEGRITY';
    public const ERR_NO_RECIPIENT = 'NO_RECIPIENT';
    public const ERR_BLOCKED = 'BLOCKED';
    // E22: ميتا تحجب الإرسال (wa_health=BLOCKED)، مستقل عن الإيقاف اليدوي SENDING_SUSPENDED
    public const ERR_CHANNEL_BLOCKED = 'CHANNEL_BLOCKED';

    /** نص الزر المعروض في الصندوق (الجسم المرسل للعميل غيره) */
    public const CONTACT_REQUEST_LABEL = '📱 طلب مشاركة رقم الهاتف';

    /** أنواع الصوت التي نفرّغها؛ غيرها لا يُنزَّل */
    private const AUDIO_MIME = ['audio/ogg', 'audio/opus', 'audio/mpeg', 'audio/mp4', 'audio/aac', 'audio/amr'];

    // ==================== أخطاء ميتا (E18) ====================

    /**
     * ما يُعرض ويُعاد لخطأ مصنَّف: الجديد مع meta_error_v2، وإلا رمز الجدول القديم ونصه (وضع الظل).
     *
     * @return array{0: string, 1: string} [الرمز المعاد، الرسالة المحفوظة والمعروضة]
     */
    private static function shown(MetaError $err): array
    {
        if (MetaErrorCatalog::v2()) {
            return [$err->code, $err->staffMessage];
        }
        if ($err->code === self::ERR_PROOF) {
            return [self::ERR_PROOF, '[100] إثبات سر التطبيق مرفوض — راجع WA_APPSECRET_PROOF وسر التطبيق.'];
        }

        return [MetaErrorCatalog::codeFor($err->metaCode),
            SecretRedactor::clean(MetaErrorCatalog::message($err->metaCode, $err->metaMessage ?? ('HTTP '.$err->httpStatus)))];
    }

    /** المسار القديم للتنبيه والإيقاف (وضع الظل). مع meta_error_v2 يتولاهما MetaErrorReactor */
    private static function legacyReact(MetaError $err, bool $accountOnly = false): void
    {
        if (MetaErrorCatalog::v2()) {
            return;
        }
        if ($err->code === self::ERR_PROOF) {
            self::alertOnce('proof', '⛔ ميتا رفضت appsecret_proof',
                'كل نداءات Graph تُرفض. عطّل WA_APPSECRET_PROOF أو صحّح سر التطبيق، ثم config:cache.', '/whatsapp/settings');

            return;
        }
        // تنزيل الوسائط: رمز مرفوض أو صلاحية ناقصة وحدهما (كما كان)
        if ($accountOnly && !(in_array($err->httpStatus, [401, 403], true) && MetaErrorCatalog::isAccountError($err->metaCode))) {
            return;
        }
        MetaErrorCatalog::react($err->metaCode);
    }

    /** إضافة لا استبدال: المستدعون يقرؤون ok وerror، والجديد يحمل الفئة والإجراء وقابلية الإعادة والنطاق */
    private static function failWith(MetaError $err, string $code, string $msg): array
    {
        $out = self::fail($code, $msg, $err->metaCode);
        if (MetaErrorCatalog::v2()) {
            $out += ['category' => $err->category, 'retryable' => $err->isRetryable(), 'action' => $err->action, 'scope' => $err->scope];
        }

        return $out;
    }

    /** تنبيه للمدير مرة في اليوم لكل نوع */
    private static function alertOnce(string $kind, string $title, string $body, string $url): void
    {
        if (Cache::add("wa:graph-alert:{$kind}:" . now()->toDateString(), 1, now()->addDay())) {
            WhatsAppInbox::notifyAdmins($title, $body, $url);
        }
    }

    // ==================== الوسائط الواردة ====================

    /**
     * تنزيل وسائط رسالة واردة (الصوت اليوم) على خطوتين: Graph يعيد رابطاً مؤقتاً وحجماً وبصمة، ثم التنزيل بالرمز نفسه.
     * الرابط https إلزامياً، ومضيفه من قائمة ميتا (WA_MEDIA_HOST_MODE: log يسجّل ويكمل، enforce يرفض قبل إرسال الرمز)،
     * والحجم مقيّد أثناء القراءة، والبصمة sha256 تُطابق بعدها.
     *
     * @return array{ok: bool, code: ?string, error: ?string, bytes?: string, mime?: string, host?: string, sha256_ok?: ?bool}
     */
    public static function fetchMedia(string $mediaId, string $kind = 'audio', int $maxBytes = 15_000_000, ?int $messageId = null): array
    {
        $s = WaBotSetting::current();
        $token = $s->token();
        if (!$token) {
            return self::mediaFail(self::ERR_NOT_CONFIGURED, 'قناة الواتساب غير مهيّأة.');
        }
        if ($kind !== 'audio') {
            return self::mediaFail(self::ERR_UNSUPPORTED, 'تنزيل هذا النوع من الوسائط غير مدعوم.');
        }

        // (أ) البيانات الوصفية (نداء Graph عبر GraphClient، بالإثبات إن فُعّل)
        try {
            $meta = Graph\GraphClient::request('GET', [$mediaId], array_filter(['phone_number_id' => $s->phoneNumberId()]), $token, 20);
        } catch (\InvalidArgumentException) {
            return self::mediaFail(self::ERR_CONFIG, 'معرّف الوسائط أو إصدار Graph غير صالح.');
        } catch (\Throwable $e) {
            WaLog::error('media.lookup.connection_failed', ['exception' => $e]);

            return self::mediaFail('HTTP_ERROR', 'تعذّر الاتصال بميتا (مهلة أو شبكة).');
        }
        $url = (string) $meta->json('url', '');
        if (!$meta->successful() || $url === '') {
            self::mediaLookupFailed($meta, $messageId);

            return self::mediaFail('META_ERROR', 'تعذّر جلب بيانات الوسائط من ميتا.');
        }

        // (ب) قبل التنزيل
        $mime = (string) ($meta->json('mime_type') ?: 'audio/ogg');
        if (!in_array(strtolower(trim(explode(';', $mime)[0])), self::AUDIO_MIME, true)) {
            return self::mediaFail(self::ERR_UNSUPPORTED, 'نوع الملف الصوتي غير مدعوم.');
        }
        if ((int) $meta->json('file_size', 0) > $maxBytes) {
            return self::mediaFail(self::ERR_TOO_LARGE, 'الملف أكبر من الحد المسموح.');
        }
        $enforce = config('services.whatsapp.media_host_mode') === 'enforce';
        $sha = strtolower((string) $meta->json('sha256', ''));
        if (!preg_match('/^[a-f0-9]{64}$/', $sha)) {
            WaLog::error('media.sha256.invalid');
            if ($enforce) {
                return self::mediaFail(self::ERR_MEDIA_INTEGRITY, 'بصمة الملف من ميتا غير صالحة.');
            }
            $sha = '';
        }

        // (ج) الرابط: https دائماً، ومضيف من القائمة
        $parts = parse_url($url) ?: [];
        $host = strtolower((string) ($parts['host'] ?? ''));
        if (($parts['scheme'] ?? '') !== 'https' || $host === '') {
            WaLog::error('media.url.not_https', ['host' => $host]);

            return self::mediaFail(self::ERR_MEDIA_HOST, 'رابط الوسائط ليس https.');
        }
        if (!self::isMetaMediaHost($host)) {
            WaLog::error('media.host.outside_allowlist', ['host' => $host]);
            if ($enforce) {
                return self::mediaFail(self::ERR_MEDIA_HOST, 'مضيف الوسائط خارج قائمة ميتا.');
            }
            self::alertOnce('media-host', '⚠️ مضيف وسائط غير متوقع', "رابط وسائط من ميتا على المضيف {$host} خارج القائمة. أضفه إلى WA_MEDIA_HOSTS إن كان لميتا قبل تفعيل الرفض.", '/whatsapp/settings');
        }

        // (د) التنزيل بقطع 64KB مع سقف أثناء القراءة
        $redirects = $enforce ? false : [
            'max' => 1, 'strict' => true, 'protocols' => ['https'],
            'on_redirect' => fn ($request, $response, $uri) => WaLog::error('media.download.redirected', ['host' => $uri->getHost()]),
        ];
        try {
            $file = Graph\GraphClient::media(40, $token)
                ->withOptions(['stream' => true, 'allow_redirects' => $redirects])
                ->get($url);
        } catch (\Throwable $e) {
            WaLog::error('media.download.connection_failed', ['exception' => $e]);

            return self::mediaFail('HTTP_ERROR', 'تعذّر تنزيل الوسائط (مهلة أو شبكة).');
        }
        if (!$file->successful()) {
            WaLog::error('media.download.failed', ['http' => $file->status(), 'host' => $host]);

            return self::mediaFail('META_ERROR', 'تعذّر تنزيل الوسائط.');
        }
        if ((int) $file->header('Content-Length') > $maxBytes) {
            return self::mediaFail(self::ERR_TOO_LARGE, 'الملف أكبر من الحد المسموح.');
        }
        $body = $file->toPsrResponse()->getBody();
        $bytes = '';
        while (!$body->eof()) {
            $bytes .= $body->read(65536);
            if (strlen($bytes) > $maxBytes) {
                $body->close();

                return self::mediaFail(self::ERR_TOO_LARGE, 'الملف أكبر من الحد المسموح.');
            }
        }

        // (هـ) البصمة
        $shaOk = $sha === '' ? null : hash_equals($sha, hash('sha256', $bytes));
        if ($shaOk === false) {
            WaLog::error('media.sha256.mismatch', ['host' => $host]);
            if ($enforce) {
                return self::mediaFail(self::ERR_MEDIA_INTEGRITY, 'الملف المنزَّل لا يطابق بصمة ميتا.');
            }
        }

        return ['ok' => true, 'code' => null, 'error' => null, 'bytes' => $bytes, 'mime' => $mime, 'host' => $host, 'sha256_ok' => $shaOk];
    }

    /**
     * بيانات وسيط وارد من ميتا بلا تنزيل (أرشيف E14): GET {v}/{media-id} عبر graph() بلا تحويلات ومهلة 20 ثانية.
     * الرابط مؤقت (5 دقائق) ويعود للمستدعي وحده: لا يُخزَّن ولا يُسجَّل.
     *
     * @return array{ok: bool, http: ?int, meta_code: ?int, url?: string, mime?: string, file_size?: int, sha256?: string}
     */
    public static function mediaMeta(string $mediaId): array
    {
        $s = WaBotSetting::current();
        if (!$s->token()) {
            return ['ok' => false, 'http' => null, 'meta_code' => null, 'code' => self::ERR_NOT_CONFIGURED];
        }
        try {
            $res = Graph\GraphClient::request('GET', [$mediaId], array_filter(['phone_number_id' => $s->phoneNumberId()]), (string) $s->token(), 20);
        } catch (\InvalidArgumentException) {
            return ['ok' => false, 'http' => null, 'meta_code' => null, 'code' => self::ERR_CONFIG];
        } catch (\Throwable $e) {
            WaLog::warning('media.lookup.connection_failed', ['type' => $e::class]);

            return ['ok' => false, 'http' => null, 'meta_code' => null, 'code' => 'HTTP_ERROR'];
        }
        $metaCode = is_numeric(data_get($res->json(), 'error.code')) ? (int) data_get($res->json(), 'error.code') : null;
        $url = (string) $res->json('url', '');
        if (!$res->successful() || $url === '') {
            self::mediaLookupFailed($res, null);

            return ['ok' => false, 'http' => $res->status(), 'meta_code' => $metaCode, 'code' => 'META_ERROR',
                'not_found' => $res->status() === 404 || $metaCode === 100 || str_contains(strtolower((string) data_get($res->json(), 'error.message', '')), 'not found')];
        }

        return ['ok' => true, 'http' => $res->status(), 'meta_code' => null, 'url' => $url,
            'mime' => (string) $res->json('mime_type', ''), 'file_size' => (int) $res->json('file_size', 0), 'sha256' => (string) $res->json('sha256', '')];
    }

    /** فشل الخطوة الأولى (Graph): تصنيف وسطر wa.media_failed بالمعرّفات، و190 ينبّه كما في الإرسال */
    private static function mediaLookupFailed(Response $res, ?int $messageId): MetaError
    {
        $err = MetaError::fromGraphResponse($res);
        WaLog::warning('wa.media_failed', ['message_id' => $messageId, 'meta_code' => $err->metaCode, 'error_code' => $err->code, 'category' => $err->category, 'http' => $res->status()]);
        self::legacyReact($err, accountOnly: true);
        MetaErrorReactor::react($err, ['message_id' => $messageId, 'source' => 'media']);

        return $err;
    }

    /** رابط تنزيل من ميتا: https ومضيف من WA_MEDIA_HOSTS. الأرشيف لا يرسل الرمز لغيره أياً كان media_host_mode */
    public static function isMetaMediaUrl(string $url): bool
    {
        $parts = parse_url($url) ?: [];
        $host = strtolower((string) ($parts['host'] ?? ''));

        return ($parts['scheme'] ?? '') === 'https' && $host !== '' && self::isMetaMediaHost($host);
    }

    /**
     * الغلاف القديم: null عند أي فشل.
     *
     * @return array{bytes: string, mime: string, host: string}|null
     */
    public static function downloadMedia(string $mediaId, int $maxBytes = 15_000_000): ?array
    {
        if ($mediaId === '') {
            return null;
        }
        $media = self::fetchMedia($mediaId, 'audio', $maxBytes);

        return $media['ok'] ? ['bytes' => $media['bytes'], 'mime' => $media['mime'], 'host' => $media['host']] : null;
    }

    // ==================== فحوص ميتا للقراءة (MetaHealth، E08) ====================

    /**
     * GET /debug_token لرمزنا، برمز التطبيق {app-id}|{app-secret} في ترويسة Authorization (يُبنى هنا ولا يُعاد ولا يُسجَّل).
     * الرابط يحمل الرمز المفحوص (input_token)، فالاستثناء لا يُسجَّل إلا باسم صنفه.
     *
     * @return array{reached: bool, status: ?int, json: array<string, mixed>}
     */
    public static function debugToken(): array
    {
        $s = WaBotSetting::current();
        try {
            // رمز التطبيق في Authorization، والإثبات (إن فُعّل) محسوب عليه هو
            $res = Graph\GraphClient::request('GET', ['debug_token'], ['input_token' => (string) $s->token()], $s->appId().'|'.$s->appSecret(), 15, 5);
        } catch (\Throwable $e) {
            WaLog::error('meta.check.unreachable', ['type' => $e::class, 'field' => 'debug_token']);

            return ['reached' => false, 'status' => null, 'json' => []];
        }

        return ['reached' => true, 'status' => $res->status(), 'json' => (array) $res->json()];
    }

    /**
     * صحة الإرسال من رقمنا: health_status (PHONE_NUMBER وWABA وBUSINESS وAPP) وquality_rating وverified_name.
     *
     * @return array{reached: bool, status: ?int, json: array<string, mixed>}
     */
    /**
     * حذف وسيط من خوادم ميتا (E13: حذف بيانات بطلب صاحبها): DELETE {v}/{media-id}، بلا تحويلات وبمهلة 10 ثوانٍ.
     * null: القناة غير مهيّأة. الفشل لا يوقف الحذف عندنا، ويُعدّ فقط.
     */
    public static function deleteMedia(string $mediaId): ?bool
    {
        $s = WaBotSetting::current();
        if (!$s->channelReady()) {
            return null;
        }
        try {
            $res = Graph\GraphClient::request('DELETE', [$mediaId], array_filter(['phone_number_id' => $s->phoneNumberId()]), (string) $s->token(), 10);
        } catch (\Throwable $e) {
            WaLog::warning('media.delete_failed', ['type' => $e::class]);

            return false;
        }
        if (!$res->successful()) {
            WaLog::warning('media.delete_failed', ['http' => $res->status()]);
        }

        return $res->successful();
    }

    public static function healthStatus(): array
    {
        $s = WaBotSetting::current();
        try {
            $res = Graph\GraphClient::request('GET', [(string) $s->phoneNumberId()], ['fields' => 'health_status,quality_rating,verified_name'], (string) $s->token(), 15);
        } catch (\Throwable $e) {
            WaLog::error('meta.check.unreachable', ['type' => $e::class, 'field' => 'health_status']);

            return ['reached' => false, 'status' => null, 'json' => []];
        }

        return ['reached' => true, 'status' => $res->status(), 'json' => (array) $res->json()];
    }

    /**
     * قبل حفظ Phone Number ID جديد (E10): هل تعرفه ميتا برمزنا؟ قراءة فقط.
     * false: ردّت ميتا بأن المعرّف غير صالح (400 برمز 100 أو 803)؛ true: موجود؛ null: لا نعرف (لا رمز، أو لا اتصال، أو خطأ آخر)،
     * فلا يُمنع الحفظ إلا برفض صريح من ميتا.
     */
    public static function probePhoneNumberId(string $phoneNumberId): ?bool
    {
        $s = WaBotSetting::current();
        if (empty($s->token())) {
            return null;
        }
        try {
            $res = Graph\GraphClient::request('GET', [$phoneNumberId], ['fields' => 'id'], (string) $s->token(), 15);
        } catch (\Throwable $e) {
            WaLog::error('meta.check.unreachable', ['type' => $e::class, 'field' => 'phone_number_id']);

            return null;
        }
        if ($res->successful()) {
            return true;
        }

        return $res->status() === 400 && in_array((int) data_get($res->json(), 'error.code'), [100, 803], true) ? false : null;
    }

    /**
     * فحص قرائي لرقمنا على إصدار Graph المضبوط (whatsapp:graph-smoke). لا يرسل أي رسالة.
     *
     * @return array{ok: bool, code: ?string, status: ?int, data?: array<string, mixed>}
     */
    public static function phoneNumberInfo(): array
    {
        $s = WaBotSetting::current();
        if (!$s->channelReady()) {
            return ['ok' => false, 'code' => self::ERR_NOT_CONFIGURED, 'status' => null];
        }
        try {
            $res = Graph\GraphClient::request('GET', [(string) $s->phoneNumberId()], ['fields' => 'id,display_phone_number,quality_rating,verified_name'], (string) $s->token(), 20);
        } catch (\InvalidArgumentException) {
            return ['ok' => false, 'code' => self::ERR_CONFIG, 'status' => null];
        } catch (\Throwable $e) {
            WaLog::error('graph.smoke.connection_failed', ['exception' => $e]);

            return ['ok' => false, 'code' => 'HTTP_ERROR', 'status' => null];
        }
        if (!$res->successful()) {
            $err = MetaError::fromGraphResponse($res);
            WaLog::warning('graph.smoke.failed', ['meta_code' => $err->metaCode, 'error_code' => $err->code, 'http' => $res->status(), 'fbtrace_id' => $err->fbtraceId]);
            self::legacyReact($err);
            MetaErrorReactor::react($err, ['source' => 'smoke']);

            return ['ok' => false, 'code' => self::shown($err)[0], 'status' => $res->status()];
        }

        $data = array_intersect_key((array) $res->json(), array_flip(['id', 'display_phone_number', 'quality_rating', 'verified_name']));

        return ['ok' => true, 'code' => null, 'status' => $res->status(), 'data' => $data];
    }

    private static function isMetaMediaHost(string $host): bool
    {
        foreach ((array) config('services.whatsapp.media_hosts', []) as $allowed) {
            $allowed = strtolower(ltrim(trim((string) $allowed), '.'));
            if ($allowed !== '' && ($host === $allowed || str_ends_with($host, '.' . $allowed))) {
                return true;
            }
        }

        return false;
    }

    private static function mediaFail(string $code, string $error): array
    {
        return ['ok' => false, 'code' => $code, 'error' => $error];
    }

    /** تحقّق توقيع ميتا على الجسم الخام — إلزامي في الإنتاج */
    public static function verifySignature(string $rawBody, ?string $header, ?string $appSecret): bool
    {
        if (empty($appSecret) || empty($header)) {
            return false;
        }
        $expected = 'sha256=' . hash_hmac('sha256', $rawBody, $appSecret);
        return hash_equals($expected, trim($header));
    }

    /**
     * إرسال رسالة نصية (غلاف رقيق فوق send()، E32).
     * $opts (E33): reply_to (WaMessage من المحادثة نفسها)، وpreview_url (طلب يقرره dispatch: للموظف وحده بالعلم والسياسة)،
     * وmeta (يُحفظ في payload._meta ولا يُرسل لميتا).
     * @return array{ok:bool, error:?string, code:?string, message_id:?string}
     */
    public static function sendText(WaConversation $conv, string $text, string $source = 'bot', ?int $staffId = null, string $context = 'reply', ?string $sendKey = null, array $opts = []): array
    {
        return self::send($conv, Outbound\OutboundMessage::text($text, (bool) ($opts['preview_url'] ?? false)), $source, $staffId,
            ['context' => $context, 'send_key' => $sendKey] + array_intersect_key($opts, array_flip(['reply_to', 'meta'])));
    }

    /**
     * إرسال صورة عبر رابط عام (بطاقة عرض مثلاً) مع تعليق اختياري. غلاف رقيق فوق send() لمسار الرابط القديم (E32):
     * الوثائق لا تُرسل برابط أبداً، وبطاقة العرض بالمعرّف خلف العلم offer_card_by_media_id.
     *
     * ميتا هي من تجلب الرابط، فيجب أن يكون HTTPS ومتاحاً بلا مصادقة —
     * وهذا ينطبق على /storage لأنّ storage:link يُنشأ عند إقلاع الحاوية.
     *
     * @return array{ok:bool, error:?string, code:?string, message_id:?string}
     */
    public static function sendImage(
        WaConversation $conv,
        string $url,
        ?string $caption = null,
        string $source = 'bot',
        ?int $staffId = null,
        ?string $sendKey = null,
        array $opts = []
    ): array {
        if (!str_starts_with($url, 'https://')) {
            return self::failLocal('BAD_MEDIA_URL', 'رابط الصورة يجب أن يكون HTTPS ليقبله واتساب.');
        }

        // نص الصف كما كان (التعليق نفسه)، لا صيغة المغلف الجديدة. meta (E33): رقم العرض لبطاقته
        return self::send($conv, Outbound\OutboundMessage::image(caption: $caption, link: $url), $source, $staffId,
            ['send_key' => $sendKey, 'display' => $caption ?: '📷 صورة'] + array_intersect_key($opts, array_flip(['reply_to', 'meta'])));
    }

    /**
     * إرسال أزرار (حتى 3) — معرّف كل زر يحمل الحالة مثل offer:12
     * @param array<array{id:string,title:string}> $buttons
     */
    public static function sendButtons(WaConversation $conv, string $body, array $buttons, string $source = 'bot', ?string $sendKey = null): array
    {
        $rows = [];
        foreach (array_slice($buttons, 0, 3) as $b) {
            $rows[] = ['type' => 'reply', 'reply' => [
                'id' => mb_substr($b['id'], 0, 256),
                'title' => mb_substr($b['title'], 0, 20),
            ]];
        }

        return self::dispatch($conv, [
            'messaging_product' => 'whatsapp',
            'type' => 'interactive',
            'interactive' => [
                'type' => 'button',
                'body' => ['text' => mb_substr($body, 0, 1024)],
                'action' => ['buttons' => $rows],
            ],
        ], $body, 'interactive', $source, sendKey: $sendKey);
    }

    /**
     * إرسال قائمة (حتى 10 صفوف)
     * @param array<array{id:string,title:string,description?:string}> $items
     */
    public static function sendList(WaConversation $conv, string $body, string $buttonLabel, array $items, string $source = 'bot', ?string $sendKey = null): array
    {
        $rows = [];
        foreach (array_slice($items, 0, 10) as $it) {
            $row = [
                'id' => mb_substr($it['id'], 0, 200),
                'title' => mb_substr($it['title'], 0, 24),
            ];
            if (!empty($it['description'])) {
                $row['description'] = mb_substr($it['description'], 0, 72);
            }
            $rows[] = $row;
        }

        return self::dispatch($conv, [
            'messaging_product' => 'whatsapp',
            'type' => 'interactive',
            'interactive' => [
                'type' => 'list',
                'body' => ['text' => mb_substr($body, 0, 1024)],
                'action' => [
                    'button' => mb_substr($buttonLabel, 0, 20),
                    'sections' => [['title' => 'الخيارات', 'rows' => $rows]],
                ],
            ],
        ], $body, 'interactive', $source, sendKey: $sendKey);
    }

    /**
     * زر «مشاركة رقم الهاتف» (request_contact_info): يرد العميل ببطاقة رقمه (contacts بأصل contact_request)
     * فتُملأ محادثته بلا رقم (ContactIdentity::acceptSharedPhone). داخل نافذة 24 ساعة كأي رسالة.
     */
    public static function sendContactRequest(WaConversation $conv, string $body, string $source = 'staff', ?int $staffId = null, ?string $sendKey = null): array
    {
        return self::dispatch($conv, [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'type' => 'interactive',
            'interactive' => [
                'type' => 'request_contact_info',
                'body' => ['text' => mb_substr($body, 0, 1024)],
                'action' => ['name' => 'request_contact_info'],
            ],
        ], self::CONTACT_REQUEST_LABEL, 'interactive', $source, $staffId, sendKey: $sendKey);
    }

    // ==================== الرسائل الغنية: التفاعل والموقع والبطاقات (E33) ====================

    /**
     * تفاعل الموظف بإيموجي على رسالة العميل (C008). لا معامل source: الموظف دائماً، ولا يستدعيها البوت ولا المساعد الإداري
     * (NoBotReactionsTest). تمر بـ dispatch كاملاً (النطاق والقناة والإيقاف والنافذة والسياسة والدفتر) بخيار as_reply=false:
     * إقرار باستلام لا رد، فلا يُسكت البوت ولا يمس شارة التدخّل ولا المعاينة ولا العلامة. '' يزيل تفاعلاً سابقاً.
     */
    public static function sendReaction(WaConversation $conv, WaMessage $target, string $emoji, int $staffId, ?string $sendKey = null): array
    {
        if (!\App\Support\FeatureFlags::on('wa_staff_reactions')) {
            return self::failLocal('FEATURE_OFF', 'تفاعل الموظف غير مفعّل.');
        }
        $emoji = trim($emoji);
        if ($emoji !== '' && !in_array($emoji, (array) config('whatsapp.staff_reactions', []), true)) {
            return self::failLocal('BAD_REACTION', 'هذا التفاعل غير مسموح.');
        }
        if ((int) $target->conversation_id !== (int) $conv->id || $target->direction !== 'in' || $target->source !== 'customer'
            || in_array($target->msg_type, ['reaction', 'system'], true) || blank($target->wamid)) {
            return self::failLocal('BAD_REACTION_TARGET', 'لا يمكن التفاعل مع هذه الرسالة.');
        }
        $days = (int) config('whatsapp.reaction_max_days', 30);
        if ($target->created_at === null || $target->created_at->lt(now()->subDays($days))) {
            return self::failLocal('REACTION_TOO_OLD', "لا يمكن التفاعل مع رسالة أقدم من {$days} يوماً");
        }

        $res = self::send($conv, Outbound\RichTypes::reaction((string) $target->wamid, $emoji), 'staff', $staffId,
            ['send_key' => $sendKey, 'as_reply' => false]);
        if (!$res['ok']) {
            if (($res['meta_code'] ?? null) === 131009) {
                $res['error'] = 'تعذّر التفاعل: الرسالة قديمة أو محذوفة.';
            }

            return $res;
        }
        ReactionProjector::applyOutbound($target, $emoji, $staffId);
        \App\Models\AuditLog::safe(\App\Enums\AuditAction::WaReactionSend, 'wa_message', $target->id, null,
            ['conversation_id' => $conv->id, 'removed' => $emoji === '']);

        return $res;
    }

    /**
     * دبوس موقع معتمد (C006): الإحداثيات من صف wa_places وحده (لا أرقام حرة)، وOutboundPolicy تطابق الحمولة مع الصف.
     * $opts: reply_to وsend_key وmeta.
     */
    public static function sendLocation(WaConversation $conv, \App\Models\WaPlace $place, string $source, ?int $staffId = null, array $opts = []): array
    {
        if (!\App\Support\FeatureFlags::on('wa_share_cards')) {
            return self::failLocal('FEATURE_OFF', 'إرسال الموقع غير مفعّل.');
        }
        if (!\App\Models\WaPlace::active()->whereKey($place->id)->exists()) {
            return self::failLocal('PLACE_INACTIVE', 'هذا الموقع غير فعّال.');
        }

        return self::send($conv, Outbound\RichTypes::location($place), $source, $staffId, [
            'send_key' => $opts['send_key'] ?? null,
            'reply_to' => $opts['reply_to'] ?? null,
            'meta' => ['place_id' => $place->id] + (array) ($opts['meta'] ?? []),
        ]);
    }

    /**
     * بطاقات اتصال معتمدة (C007): من 1 إلى 3 من wa_contact_cards الفعّالة، كلٌّ بموافقة صاحب الرقم. OutboundPolicy ترفض
     * أي رقم لا يطابق بطاقة معتمدة. كل إرسال ناجح في سجل التدقيق بمعرّفات البطاقات.
     *
     * @param  \Illuminate\Support\Collection<int, \App\Models\WaContactCard>  $cards
     */
    public static function sendContacts(WaConversation $conv, \Illuminate\Support\Collection $cards, string $source, ?int $staffId = null, array $opts = []): array
    {
        if (!\App\Support\FeatureFlags::on('wa_share_cards')) {
            return self::failLocal('FEATURE_OFF', 'إرسال البطاقات غير مفعّل.');
        }
        $cards = $cards->values();
        if ($cards->isEmpty() || $cards->count() > \App\Models\WaContactCard::MAX_PER_MESSAGE) {
            return self::failLocal('BAD_CONTACTS', 'اختر من بطاقة إلى '.\App\Models\WaContactCard::MAX_PER_MESSAGE.' بطاقات.');
        }
        $ids = $cards->map(fn ($c) => $c instanceof \App\Models\WaContactCard ? (int) $c->id : 0)->all();
        if (\App\Models\WaContactCard::active()->whereIn('id', $ids)->count() !== count(array_unique($ids)) || count(array_unique($ids)) !== count($ids)) {
            return self::failLocal('CARD_INACTIVE', 'بطاقة غير فعّالة.');
        }
        $res = self::send($conv, Outbound\RichTypes::contacts($cards), $source, $staffId, [
            'send_key' => $opts['send_key'] ?? null,
            'reply_to' => $opts['reply_to'] ?? null,
            'meta' => ['card_ids' => $ids] + (array) ($opts['meta'] ?? []),
        ]);
        if ($res['ok'] && empty($res['replayed'])) {
            \App\Models\AuditLog::safe(\App\Enums\AuditAction::WaShareCard, 'wa_conversation', $conv->id, null, ['card_ids' => $ids, 'source' => $source]);
        }

        return $res;
    }

    // ==================== إشعار القراءة ومؤشر الكتابة (E35) ====================

    /**
     * إشعار قراءة (العلامتان الزرقاوان) على أحدث وارد، ومعه «يكتب…» إن طُلب: طلب واحد عند ميتا
     * POST /{pnid}/messages {status: read, message_id, typing_indicator?}، يعلّم معه كل ما قبله.
     *
     * ليس رسالة، فلا يمر بـ dispatch: لا صف في wa_messages، ولا send_key ولا دفتر، ولا OutboundPolicy، ولا قفل النافذة
     * (حدّه 30 يوماً من الاستلام). يحترم القفلين الأولين وحدهما: القناة مهيّأة، والإيقاف الطارئ (يوقف كل ما يخرج).
     * كل رفض قبل أي HTTP: الوضع off أو العلم مطفأ (DISABLED)، والقناة، والإيقاف، والمحادثة المدموجة (MERGED)، ولا هدف
     * (NO_TARGET)، وإشعار بلا مؤشر لما أُبلغ سابقاً (ALREADY). المهلة 5 ثوانٍ والاتصال ثانيتان، ولا إعادة، ولا يرمي.
     * الفشل سطر info وعدّاد يومي (ReadReceipts::failed): لا wa_last_error ولا تنبيه ولا إيقاف.
     *
     * @return array{ok: bool, code: ?string}
     */
    public static function markRead(WaConversation $conv, bool $typing = false, ?int $uptoMessageId = null): array
    {
        try {
            if (ReadReceipts::mode() === 'off') {
                return ['ok' => false, 'code' => 'DISABLED'];
            }
            $s = WaBotSetting::current();
            if (!$s->channelReady()) {
                return ['ok' => false, 'code' => self::ERR_NOT_CONFIGURED];
            }
            if ($s->sendingSuspended()) {
                return ['ok' => false, 'code' => self::ERR_SUSPENDED];
            }
            if ($conv->status === 'merged') {
                return ['ok' => false, 'code' => 'MERGED'];
            }
            $target = ReadReceipts::target($conv, $uptoMessageId);
            if (!$target) {
                return ['ok' => false, 'code' => 'NO_TARGET'];
            }
            if (!$typing && $target->id <= ReadReceipts::upto($conv)) {
                return ['ok' => false, 'code' => 'ALREADY'];
            }

            $res = Graph\GraphClient::request('POST', [(string) $s->phoneNumberId(), 'messages'], [
                'messaging_product' => 'whatsapp',
                'status' => 'read',
                'message_id' => (string) $target->wamid,
            ] + ($typing ? ['typing_indicator' => ['type' => 'text']] : []), (string) $s->token(), ReadReceipts::TIMEOUT, ReadReceipts::CONNECT_TIMEOUT);
        } catch (\InvalidArgumentException) {
            ReadReceipts::failed($conv, self::ERR_CONFIG);

            return ['ok' => false, 'code' => self::ERR_CONFIG];
        } catch (\Throwable $e) {
            $code = $e instanceof \Illuminate\Http\Client\ConnectionException ? MetaError::networkKind($e) : 'ERROR';
            ReadReceipts::failed($conv, $code);

            return ['ok' => false, 'code' => $code];
        }

        if (!$res->successful()) {
            $err = MetaError::fromGraphResponse($res);
            ReadReceipts::failed($conv, $err->code, $err->metaCode, $err->fbtraceId, $res->status());

            return ['ok' => false, 'code' => $err->code];
        }
        ReadReceipts::advance($conv, (int) $target->id);

        return ['ok' => true, 'code' => null];
    }

    // ==================== المكوّنات الرسمية: الأوامر وأسئلة البداية (E37) ====================

    /**
     * أوامر «/» وأسئلة البداية (ice breakers) لرقمنا: POST /{pnid}/conversational_automation. إعداد للرقم لا رسالة،
     * فلا يمر بـ dispatch. القفلان الأولان: القناة مهيّأة، والإيقاف الطارئ (لا نكتب شيئاً لميتا). enable_welcome_message
     * دائماً false. لا يُسجَّل الرمز ولا الرد الخام: الرمز والفئة وfbtrace_id وحدها.
     *
     * @param  array{prompts: list<string>, commands: list<array{command_name: string, command_description: string}>}  $desired
     * @return array{ok: bool, code: ?string, meta_code?: ?int, category?: ?string, fbtrace_id?: ?string, message?: string}
     */
    public static function setConversationalAutomation(array $desired): array
    {
        $s = WaBotSetting::current();
        if ($locked = self::componentsLock($s)) {
            return $locked;
        }
        try {
            $res = Graph\GraphClient::request('POST', [(string) $s->phoneNumberId(), 'conversational_automation'], [
                'enable_welcome_message' => false,
                'prompts' => array_values($desired['prompts'] ?? []),
                'commands' => array_values($desired['commands'] ?? []),
            ], (string) $s->token(), 20);
        } catch (\Throwable $e) {
            return self::componentsFailure($e);
        }

        return $res->successful() ? ['ok' => true, 'code' => null] : self::componentsRejected($res);
    }

    /**
     * ما تعرضه ميتا الآن: GET /{pnid}?fields=conversational_automation (للمقارنة بالمحفوظ). قراءة بالقفلين نفسيهما.
     *
     * @return array{ok: bool, code: ?string, data?: array{enable_welcome_message: bool, prompts: list<string>, commands: list<array{command_name: string, command_description: string}>}}
     */
    public static function getConversationalAutomation(): array
    {
        $s = WaBotSetting::current();
        if ($locked = self::componentsLock($s)) {
            return $locked;
        }
        try {
            $res = Graph\GraphClient::request('GET', [(string) $s->phoneNumberId()], ['fields' => 'conversational_automation'], (string) $s->token(), 20);
        } catch (\Throwable $e) {
            return self::componentsFailure($e);
        }
        if (!$res->successful()) {
            return self::componentsRejected($res);
        }
        $data = (array) $res->json('conversational_automation', []);

        return ['ok' => true, 'code' => null, 'data' => [
            'enable_welcome_message' => (bool) ($data['enable_welcome_message'] ?? false),
            'prompts' => array_values(array_filter((array) ($data['prompts'] ?? []), 'is_string')),
            'commands' => array_values(array_map(fn ($c) => [
                'command_name' => (string) data_get($c, 'command_name', ''),
                'command_description' => (string) data_get($c, 'command_description', ''),
            ], array_filter((array) ($data['commands'] ?? []), 'is_array'))),
        ]];
    }

    private static function componentsLock(WaBotSetting $s): ?array
    {
        if (!$s->channelReady()) {
            return ['ok' => false, 'code' => self::ERR_NOT_CONFIGURED, 'message' => 'قناة الواتساب غير مهيّأة.'];
        }
        if ($s->sendingSuspended()) {
            return ['ok' => false, 'code' => self::ERR_SUSPENDED, 'message' => 'الإرسال موقوف حالياً (قفل طارئ): لا يُكتب شيء لميتا.'];
        }

        return null;
    }

    // ==================== إدارة جهات الاتصال: الحظر (E31) ====================

    /**
     * هدف الحظر: الرقم إن عُرف، وإلا المعرّف (BSUID) خلف العلم wa_block_bsuid، فالوثائق لم تؤكد قبوله بعد.
     * حقل واحد لا الاثنان (أولوية الرقم موثّقة للإرسال لا للحظر)، والمعرّف الأب (.ENT.) لا يُحظر أبداً.
     *
     * @return array{user: string}|array{user_id: string}|null
     */
    public static function blockTarget(WaConversation $c): ?array
    {
        $digits = PhoneNormalizer::digits((string) $c->wa_phone);
        if ($digits !== '') {
            return ['user' => '+'.$digits];
        }
        $uid = (string) $c->wa_user_id;
        if ($uid !== '' && !str_contains($uid, '.ENT.') && preg_match(InboundMessage::BSUID, $uid) && \App\Support\FeatureFlags::on('wa_block_bsuid')) {
            return ['user_id' => $uid];
        }

        return null;
    }

    private static function componentsFailure(\Throwable $e): array
    {
        $code = $e instanceof \InvalidArgumentException ? self::ERR_CONFIG
            : ($e instanceof \Illuminate\Http\Client\ConnectionException ? MetaError::networkKind($e) : 'ERROR');
        WaLog::warning('wa.conv_automation_failed', ['error_code' => $code, 'type' => class_basename($e)]);

        return ['ok' => false, 'code' => $code, 'message' => 'تعذّر الوصول إلى ميتا الآن — حاول بعد قليل.'];
    }

    private static function componentsRejected(Response $res): array
    {
        $err = MetaError::fromGraphResponse($res);
        WaLog::warning('wa.conv_automation_failed', ['meta_code' => $err->metaCode, 'error_code' => $err->code, 'category' => $err->category,
            'fbtrace_id' => $err->fbtraceId, 'http' => $res->status()]);

        return ['ok' => false, 'code' => $err->code, 'meta_code' => $err->metaCode, 'category' => $err->category,
            'fbtrace_id' => $err->fbtraceId, 'message' => $err->staffMessage];
    }

    // ==================== المغلف الموحّد والملفات (E32) ====================

    /**
     * المغلف الموحّد: كل صادر بنوعه الرسمي (نص أو صورة أو مستند أو فيديو، وما تسجّله E33/E34) يمر هنا ثم dispatch()
     * بأقفاله كلها والسياسة والدفتر. الاقتباس يُقبل من المحادثة نفسها وحدها، وأعمدة media_* تُكتب للصادر.
     *
     * @param  array{context?: string, send_key?: ?string, media?: ?array, display?: string, row?: ?WaMessage, reply_to?: ?WaMessage, meta?: array, as_reply?: bool}  $opts
     *   media: {mime, sha256, size, filename, path} للنسخة الخاصة على wa_media · row: صف محجوز بـ reserve() يُكمَل هو نفسه
     *   reply_to (E33): صف من المحادثة نفسها له wamid وليس تفاعلاً، وإلا BAD_REPLY_TARGET بلا HTTP · meta: payload._meta (لا يُرسل)
     *   as_reply=false (E33، تفاعل الموظف): لا يمس حقول «رد الموظف» ولا العلامة بعد النجاح
     * @return array{ok: bool, error: ?string, code: ?string, message_id: ?string}
     */
    public static function send(WaConversation $conv, Outbound\OutboundMessage $m, string $source = 'staff', ?int $staffId = null, array $opts = []): array
    {
        if ($fail = $m->validate()) {
            if (isset($opts['row'])) {
                self::failReserved($opts['row'], $fail);
            }

            return self::failLocal($fail['code'], $fail['error']);
        }
        if (($opts['reply_to'] ?? null) !== null) {
            if (!self::canQuote($conv, $opts['reply_to'])) {
                return self::failLocal('BAD_REPLY_TARGET', 'لا يمكن الاقتباس من هذه الرسالة.');
            }
            $reply = (string) $opts['reply_to']->wamid;
        } else {
            $reply = self::quotable($conv, $m->replyToWamid);
        }
        // التفاعل لا يُرسل مقتبساً
        if ($m->type === 'reaction') {
            $reply = null;
        }

        return self::dispatch($conv, $m->payload($reply), $opts['display'] ?? $m->displayBody(), $m->type, $source, $staffId,
            $opts['context'] ?? 'reply', $opts['send_key'] ?? null, self::envelopeColumns($m, $opts['media'] ?? null, $reply), $opts['row'] ?? null,
            (array) ($opts['meta'] ?? []), (bool) ($opts['as_reply'] ?? true));
    }

    /** يُقتبس (E33): صف من المحادثة نفسها، أُرسل أو وصل بمعرّف ميتا، وليس تفاعلاً */
    public static function canQuote(WaConversation $conv, mixed $message): bool
    {
        return $message instanceof WaMessage && (int) $message->conversation_id === (int) $conv->id
            && filled($message->wamid) && $message->msg_type !== 'reaction';
    }

    /**
     * رسالة صوتية من الموظف (E34): type=audio بعلم voice، وملف ogg/opus أحادي مرفوع بالمعرّف. لا معامل للمصدر: staff ثابت
     * هنا، والبوت والنظام لا يرسلان صوتاً أبداً (dispatch يرفضه لغير الموظف، وNoBotAudioTest يحرس أدوات البوت).
     *
     * @param  array{seconds?: float|int, send_key?: ?string, media?: ?array, row?: ?WaMessage}  $opts  row: الصف المحجوز في send-file
     */
    public static function sendVoice(WaConversation $conv, string $mediaId, int $staffId, array $opts = []): array
    {
        return self::send($conv, Outbound\VoiceNote::message($mediaId, (float) ($opts['seconds'] ?? 0)), 'staff', $staffId,
            array_intersect_key($opts, array_flip(['send_key', 'media', 'row'])));
    }

    /**
     * الأقفال 0–4 كما في dispatch (الموظف، والقناة، والإيقاف الطارئ، والنافذة، والوجهة) بلا إرسال: قبل أي رفع إلى ميتا،
     * وقبل حجز صف ملف، وفي الوظيفة المؤجلة قبل الرفع ثم قبل الإرسال. null: مسموح.
     */
    public static function preflight(WaConversation $conv, string $source = 'staff', ?int $staffId = null): ?array
    {
        return self::gate($conv, WaBotSetting::current(), $source, $staffId)[0];
    }

    /**
     * حجز صف queued لملف يُرفع لاحقاً في الطابور (E32.7): الأقفال كلها، ثم الصف بالمفتاح والنسخة الخاصة قبل أي رفع.
     * المفتاح نفسه مرة ثانية لا يحجز ثانياً: replay يعيد حالة الصف الأول (قيد الإرسال، أو أُرسل، أو غير مؤكد).
     * رد الموظف يوقف البوت فوراً، فلا يرد البوت على العميل والملف في الطريق.
     *
     * @param  array{send_key?: ?string, media?: ?array, display?: string, payload?: array}  $opts
     */
    public static function reserve(WaConversation $conv, Outbound\OutboundMessage $m, string $source, ?int $staffId, array $opts = []): array
    {
        if ($fail = $m->validate(deferred: true)) {
            return self::failLocal($fail['code'], $fail['error']);
        }
        if (!$m->isMedia() || !\Illuminate\Support\Facades\Schema::hasColumns('wa_messages', ['attempts', 'media_path'])) {
            return self::failLocal(self::ERR_UNSUPPORTED, 'الإرسال المؤجل للملفات وحدها.');
        }
        if ($source !== 'staff' && Outbound\VoiceNote::isVoicePayload($m->payload())) {
            return self::voiceRefused($conv, $source);
        }
        $s = WaBotSetting::current();
        [$fail] = self::gate($conv, $s, $source, $staffId);
        if ($fail !== null) {
            return $fail;
        }

        $reply = self::quotable($conv, $m->replyToWamid);
        $display = $opts['display'] ?? $m->displayBody();
        $columns = self::envelopeColumns($m, $opts['media'] ?? null, $reply);
        $sendKey = $opts['send_key'] ?? null;
        $hash = self::reqHash($conv, $m->type, $display, $columns);
        try {
            $row = WaMessage::create($columns + [
                'conversation_id' => $conv->id, 'direction' => 'out', 'source' => $source, 'msg_type' => $m->type, 'body' => $display,
                // التعليق لتعيد الوظيفة بناء الرسالة نفسها؛ يحل محله في الإرسال الحمولة الرسمية كاملة
                'payload' => ['_req_hash' => $hash, '_deferred' => 'media', '_caption' => $m->caption] + ($opts['payload'] ?? []),
                'status' => WaMessage::STATUS_QUEUED, 'staff_id' => $staffId, 'phone_number_id' => $s->phoneNumberId(),
                'send_key' => $sendKey !== null ? mb_substr($sendKey, 0, 64) : null, 'attempts' => 0,
            ]);
        } catch (\Illuminate\Database\UniqueConstraintViolationException) {
            return self::replay((string) $sendKey, $hash);
        }
        WaLog::info('wa.send.reserved', ['conv_id' => $conv->id, 'message_id' => $row->id, 'source' => $source, 'type' => $m->type]);
        if ($source === 'staff') {
            $conv->update(['bot_paused_until' => now()->addMinutes(max(1, (int) $s->pause_minutes))]);
        }

        return ['ok' => true, 'error' => null, 'code' => null, 'message_id' => null, 'meta_code' => null,
            'status' => WaMessage::STATUS_QUEUED, 'row_id' => $row->id, 'replayed' => false];
    }

    /**
     * رفع ملف إلى ميتا (E32.5): POST {pnid}/media متعدد الأجزاء من مورد مفتوح (لا نص في الذاكرة، memory_limit=256M)
     * بعد preflight. المعرّف يعيش عند ميتا 30 يوماً. لا إعادة آلية: رفع ملف كبير بعد مهلة قد يكرره.
     * السجل: المحادثة والحجم والنوع ورمز ميتا وfbtrace_id، بلا اسم الملف.
     *
     * @return array{ok: bool, media_id: ?string, code: ?string, error: ?string, meta_code?: ?int, fbtrace_id?: ?string}
     */
    public static function uploadMedia(WaConversation $conv, string $path, string $mime, string $filename, string $source = 'staff', ?int $staffId = null): array
    {
        if ($fail = self::preflight($conv, $source, $staffId)) {
            return $fail + ['media_id' => null];
        }
        $s = WaBotSetting::current();
        clearstatcache(true, $path);
        $size = is_file($path) ? (int) filesize($path) : 0;
        if ($size <= 0) {
            return self::failLocal('MEDIA_FILE_MISSING', MetaError::local('MEDIA_FILE_MISSING')->staffMessage) + ['media_id' => null];
        }
        $log = ['conv_id' => $conv->id, 'source' => $source, 'bytes' => $size, 'mime' => $mime];
        $started = microtime(true);
        try {
            $res = Graph\GraphClient::upload([(string) $s->phoneNumberId(), 'media'], $path, $mime, $filename,
                ['messaging_product' => 'whatsapp', 'type' => $mime], (string) $s->token(), self::uploadTimeout($size));
        } catch (\InvalidArgumentException $e) {
            $code = $e->getMessage() === 'MEDIA_FILE_UNREADABLE' ? 'MEDIA_FILE_MISSING' : self::ERR_CONFIG;
            WaLog::error($code === self::ERR_CONFIG ? 'graph.config.invalid' : 'wa.media.file_unreadable', ['reason' => $e->getMessage()]);
            $err = MetaError::local($code);

            return self::failWith($err, $code, $err->staffMessage) + ['media_id' => null];
        } catch (\Throwable $e) {
            $err = MetaError::network(MetaError::networkKind($e));
            WaLog::warning('wa.media.upload_failed', $log + ['error_code' => $err->code, 'category' => $err->category, 'exception' => $e]);
            MetaErrorReactor::react($err, ['conv_id' => $conv->id, 'source' => 'media_upload']);

            return self::failWith($err, $err->code, $err->code === 'HTTP_CONNECT_FAILED'
                ? 'تعذّر الاتصال بميتا لرفع الملف، ولم يُرسل.' : 'انقطع رفع الملف إلى ميتا قبل اكتماله، ولم يُرسل.') + ['media_id' => null];
        }

        $id = $res->json('id');
        if (!$res->successful() || !is_string($id) || $id === '') {
            $err = MetaError::fromGraphResponse($res);
            [$code, $shown] = self::shown($err);
            WaLog::warning('wa.media.upload_failed', $log + ['meta_code' => $err->metaCode, 'error_code' => $err->code, 'category' => $err->category,
                'fbtrace_id' => $err->fbtraceId, 'http' => $res->status()]);
            self::legacyReact($err);
            MetaErrorReactor::react($err, ['conv_id' => $conv->id, 'source' => 'media_upload']);

            return self::failWith($err, $code, $shown) + ['media_id' => null, 'fbtrace_id' => $err->fbtraceId];
        }
        WaLog::info('wa.media.uploaded', $log + ['ms' => (int) round((microtime(true) - $started) * 1000)]);

        return ['ok' => true, 'media_id' => mb_substr($id, 0, 128), 'code' => null, 'error' => null];
    }

    /** مهلة الرفع: 30 ثانية + ثانية لكل 256KB، بحد يبقي وظيفة الطابور (120 ثانية) فوق الرفع ونداء الإرسال معاً */
    public static function uploadTimeout(int $bytes): int
    {
        return (int) min((int) config('whatsapp.outbound.upload_timeout_max', 85), 30 + intdiv(max(0, $bytes), 262144));
    }

    /**
     * حسم صف محجوز لم يُرسل (E32): قفل، أو ملف مرفوض أو مفقود، أو فشل الرفع، أو تأخر الطابور. failed والمفتاح محرَّر
     * لإعادة مشروعة، بشرط أن يبقى queued ولم تبدأ محاولة إرسال (attempts=0)، فلا يكتب فوق ما حسمه غيره.
     */
    public static function failReserved(WaMessage $row, array $fail): bool
    {
        $code = mb_substr((string) ($fail['code'] ?? 'MEDIA_SEND_FAILED'), 0, 32);
        $metaCode = isset($fail['meta_code']) && is_numeric($fail['meta_code']) ? (int) $fail['meta_code'] : null;
        $meaning = MetaErrorCatalog::forStored($metaCode, $code, $fail['category'] ?? null);
        $current = WaMessage::find($row->id);
        if (!$current || $current->status !== WaMessage::STATUS_QUEUED || (int) $current->attempts !== 0) {
            return false;
        }
        $key = $current->send_key;
        $payload = ['_meta_error' => array_filter(['code' => $code, 'meta_code' => $metaCode])] + ($key ? ['_released_key' => $key] : [])
            + (array) ($current->payload ?? []);
        $update = [
            'status' => WaMessage::STATUS_FAILED, 'send_key' => null, 'meta_code' => $metaCode, 'error_code' => $code,
            'error_message' => mb_substr((string) ($fail['error'] ?? MetaError::local($code)->staffMessage), 0, 500),
            'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE),
        ];
        if (!empty($fail['fbtrace_id'])) {
            $update['fbtrace_id'] = mb_substr((string) $fail['fbtrace_id'], 0, 64);
        }
        self::$hasCategory ??= \Illuminate\Support\Facades\Schema::hasColumn('wa_messages', 'error_category');
        if (self::$hasCategory) {
            $update['error_category'] = $meaning['category'] ?? null;
        }
        $moved = WaMessage::whereKey($row->id)->where('status', WaMessage::STATUS_QUEUED)->where('attempts', 0)->update($update);
        if ($moved > 0) {
            WaLog::warning('wa.send_failed', ['conv_id' => $row->conversation_id, 'message_id' => $row->id, 'source' => $row->source, 'type' => $row->msg_type,
                'meta_code' => $metaCode, 'error_code' => $code, 'category' => $meaning['category'] ?? null, 'attempt' => 0, 'status' => 'failed']);
        }

        return $moved > 0;
    }

    /** الاقتباس من المحادثة نفسها وحدها: wamid من محادثة أخرى أو مجهول يُسقط بسطر سجل، فلا يقتبس أحد رسالة غيره */
    private static function quotable(WaConversation $conv, ?string $wamid): ?string
    {
        if ($wamid === null || $wamid === '') {
            return null;
        }
        if (WaMessage::where('conversation_id', $conv->id)->where('wamid', $wamid)->exists()) {
            return $wamid;
        }
        WaLog::notice('wa.reply_to.dropped', ['conv_id' => $conv->id]);

        return null;
    }

    /**
     * رفض ميتا الصريح للاقتباس (E33): 4xx بجسم JSON رمزه 131009 أو 100 وفي error_data.details ذكر لـ context أو message_id،
     * أي أن الرسالة لم تُرسل. المهلة و5xx بلا جسم ليسا منه (لا إعادة، كقواعد E19).
     */
    private static function contextRejected(Response $res, array $payload): bool
    {
        if (!isset($payload['context']) || $res->status() < 400 || $res->status() >= 500 || !is_array($res->json())) {
            return false;
        }
        $details = strtolower((string) data_get($res->json(), 'error.error_data.details', ''));

        return in_array((int) data_get($res->json(), 'error.code'), [131009, 100], true)
            && (str_contains($details, 'context') || str_contains($details, 'message_id'));
    }

    /** يُسقط الاقتباس من الحمولة والمحفوظ، بسطر سجل بالرمز وfbtrace_id وحدهما */
    private static function dropContext(WaConversation $conv, Response $res, array $payload, array $stored): array
    {
        unset($payload['context'], $stored['context']);
        $stored['_context_dropped'] = true;
        $err = MetaError::fromGraphResponse($res);
        WaLog::info('wa.send.context_dropped', ['conv_id' => $conv->id, 'meta_code' => $err->metaCode, 'fbtrace_id' => $err->fbtraceId]);

        return [$payload, $stored];
    }

    private static ?bool $hasEnvelopeColumns = null;

    /**
     * أعمدة الصادر من المغلف (أعمدة E11 وE14): الاقتباس، ومعرّف ميتا، والنسخة الخاصة على wa_media (المسار والبصمة والحجم
     * والنوع والاسم) بحالة stored، فيعرضها عارض E14 لمن يملك الرؤية ويقلّمها media-prune ويحذفها forget.
     */
    private static function envelopeColumns(Outbound\OutboundMessage $m, ?array $media, ?string $reply): array
    {
        self::$hasEnvelopeColumns ??= \Illuminate\Support\Facades\Schema::hasColumns('wa_messages',
            ['reply_to_wamid', 'media_id', 'media_path', 'media_status', 'media_archived_at']);
        if (!self::$hasEnvelopeColumns) {
            return [];
        }
        $out = $reply !== null ? ['reply_to_wamid' => $reply] : [];
        if (!$m->isMedia()) {
            return $out;
        }
        $media = (array) $media;
        $path = isset($media['path']) && $media['path'] !== '' ? (string) $media['path'] : null;

        return $out + array_filter([
            'media_id' => $m->mediaId,
            'media_mime' => $media['mime'] ?? null,
            'media_sha256' => $media['sha256'] ?? null,
            'media_size' => isset($media['size']) ? (int) $media['size'] : null,
            'media_filename' => isset($media['filename']) ? mb_substr((string) $media['filename'], 0, 255) : $m->filename,
            'media_path' => $path,
            'media_status' => $path !== null ? 'stored' : null,
            'media_archived_at' => $path !== null ? now() : null,
        ], fn ($v) => $v !== null);
    }

    /** بصمة الطلب لمفتاح الموظف (E19): المحادثة والنوع والنص، وبصمة الملف للوسائط (النص وحده لا يميّز ملفين) */
    private static function reqHash(WaConversation $conv, string $msgType, string $displayBody, array $columns = []): string
    {
        return hash('sha256', $conv->id.'|'.$msgType.'|'.$displayBody.(isset($columns['media_sha256']) ? '|'.$columns['media_sha256'] : ''));
    }

    /**
     * POST {pnid}/block_users. القفلان 1 و2، ونافذة الـ24 ساعة شرط ميتا (131047) فتُفحص محلياً بلا نداء.
     * لا إعادة تلقائية لأي رفض (E18). يُستدعى من BlockService وحده.
     *
     * @return array{ok: bool, code: ?string, error: ?string, meta_code: ?int}
     */
    public static function blockUsers(WaConversation $c): array
    {
        $s = WaBotSetting::current();
        if (!$s->channelReady()) {
            return self::fail(self::ERR_NOT_CONFIGURED, 'قناة الواتساب غير مهيّأة (رمز الوصول أو معرّف الرقم ناقص).');
        }
        if ($s->sendingSuspended()) {
            return self::fail(self::ERR_SUSPENDED, 'الإرسال موقوف حالياً (قفل طارئ): لا حظر ولا فك حتى يُرفع.');
        }
        if (!$c->isWindowOpen()) {
            return self::fail('BLOCK_WINDOW', 'لا يمكن الحظر إلا خلال 24 ساعة من آخر رسالة للعميل.');
        }
        $target = self::blockTarget($c);
        if ($target === null) {
            return self::fail(self::ERR_NO_RECIPIENT, 'رقم العميل غير ظاهر، والحظر بالمعرّف غير مفعّل بعد.');
        }

        return self::blockCall('POST', $target, $c->id);
    }

    /**
     * DELETE {pnid}/block_users بالجسم نفسه. القفلان 1 و2 وحدهما: الوثيقة تقيّد الحظر بالنافذة، والفك يُجرَّب بلا
     * هذا الشرط (رفض ميتا يُعرض كما هو). الهدف من صف الحظر (الرقم أو المعرّف كما حُظر به)، أو صريحاً من المزامنة.
     *
     * @param  WaConversation|\App\Models\WaBlock|array{user?: string, user_id?: string}  $x
     */
    public static function unblockUsers(WaConversation|\App\Models\WaBlock|array $x): array
    {
        $s = WaBotSetting::current();
        if (!$s->channelReady()) {
            return self::fail(self::ERR_NOT_CONFIGURED, 'قناة الواتساب غير مهيّأة (رمز الوصول أو معرّف الرقم ناقص).');
        }
        if ($s->sendingSuspended()) {
            return self::fail(self::ERR_SUSPENDED, 'الإرسال موقوف حالياً (قفل طارئ): لا حظر ولا فك حتى يُرفع.');
        }
        $target = match (true) {
            is_array($x) => self::validBlockTarget($x),
            $x instanceof WaConversation => self::blockTarget($x),
            default => self::validBlockTarget(array_filter([
                'user' => $x->phone ? '+'.PhoneNormalizer::digits($x->phone) : null,
                'user_id' => $x->phone ? null : $x->wa_user_id,
            ])),
        };
        if ($target === null) {
            return self::fail(self::ERR_NO_RECIPIENT, 'لا رقم ولا معرّف لهذا الحظر — فكّه من نتيجة المزامنة مع ميتا.');
        }

        return self::blockCall('DELETE', $target, $x instanceof WaConversation ? $x->id : ($x instanceof \App\Models\WaBlock ? $x->conversation_id : null));
    }

    /**
     * GET {pnid}/block_users?limit=100&after=… للمزامنة القرائية وحدها. القفل 1 وحده (قراءة).
     *
     * @return array{ok: bool, code: ?string, error: ?string, data: list<array{phone?: string, user_id?: string}>, next_after: ?string}
     */
    public static function listBlocked(?string $after = null): array
    {
        $s = WaBotSetting::current();
        if (!$s->channelReady()) {
            return self::fail(self::ERR_NOT_CONFIGURED, 'قناة الواتساب غير مهيّأة (رمز الوصول أو معرّف الرقم ناقص).') + ['data' => [], 'next_after' => null];
        }
        try {
            $res = Graph\GraphClient::request('GET', [(string) $s->phoneNumberId(), 'block_users'], array_filter(['limit' => 100, 'after' => $after]), (string) $s->token(), 20);
        } catch (\InvalidArgumentException) {
            return self::fail(self::ERR_CONFIG, 'إصدار Graph أو معرّف الرقم غير صالح في الإعدادات.') + ['data' => [], 'next_after' => null];
        } catch (\Throwable $e) {
            WaLog::warning('wa.block.list_failed', ['type' => class_basename($e)]);

            return self::fail('HTTP_ERROR', 'تعذّر الاتصال بميتا (مهلة أو شبكة).') + ['data' => [], 'next_after' => null];
        }
        if (!$res->successful()) {
            $err = MetaError::fromGraphResponse($res);
            WaLog::warning('wa.block.list_failed', ['meta_code' => $err->metaCode, 'error_code' => $err->code, 'fbtrace_id' => $err->fbtraceId, 'http' => $res->status()]);

            return self::fail($err->code, $err->staffMessage, $err->metaCode) + ['data' => [], 'next_after' => null];
        }

        $rows = [];
        foreach ((array) $res->json('data', []) as $row) {
            // عنصر لكل مستخدم، أو block_users داخل العنصر: يُقرأ الشكلان
            foreach (is_array($row) && isset($row['block_users']) ? (array) $row['block_users'] : [$row] as $u) {
                if (is_array($u) && ($entry = self::blockedEntry($u))) {
                    $rows[] = $entry;
                }
            }
        }
        $after = $res->json('paging.next') ? $res->json('paging.cursors.after') : null;

        return ['ok' => true, 'code' => null, 'error' => null, 'data' => $rows, 'next_after' => is_string($after) && $after !== '' ? $after : null];
    }

    /** @return array{phone?: string, user_id?: string}|null */
    private static function blockedEntry(array $u): ?array
    {
        $uid = data_get($u, 'user_id');
        $input = (string) data_get($u, 'input', '');
        if (!is_string($uid) && preg_match(InboundMessage::BSUID, $input)) {
            $uid = $input;
        }
        $phone = PhoneNormalizer::digits((string) (data_get($u, 'wa_id') ?: (preg_match(InboundMessage::BSUID, $input) ? '' : $input)));

        $out = array_filter(['phone' => $phone !== '' ? $phone : null, 'user_id' => is_string($uid) && $uid !== '' ? $uid : null]);

        return $out ?: null;
    }

    /** هدف صريح من صف أو من المزامنة: رقم (+أرقام) أو معرّف بصيغة BSUID، لا الاثنان ولا المعرّف الأب */
    private static function validBlockTarget(array $t): ?array
    {
        if (isset($t['user']) && preg_match('/^\+\d{6,15}$/', (string) $t['user'])) {
            return ['user' => (string) $t['user']];
        }
        $uid = (string) ($t['user_id'] ?? '');

        return $uid !== '' && !str_contains($uid, '.ENT.') && preg_match(InboundMessage::BSUID, $uid) ? ['user_id' => $uid] : null;
    }

    /**
     * النداء نفسه وتحليل الرد: الهدف ضمن added_users (أو removed_users للفك) = نجاح. فشل جزئي من failed_users
     * (الرمز الأدق لكل مستخدم)، وإلا error العام. السجل بالمعرّفات وحدها: لا رقم ولا BSUID.
     */
    private static function blockCall(string $method, array $target, ?int $convId): array
    {
        $s = WaBotSetting::current();
        $op = $method === 'POST' ? 'block' : 'unblock';
        try {
            $res = Graph\GraphClient::request($method, [(string) $s->phoneNumberId(), 'block_users'],
                ['messaging_product' => 'whatsapp', 'block_users' => [$target]], (string) $s->token(), 20, 10, jsonDelete: true);
        } catch (\InvalidArgumentException) {
            return self::fail(self::ERR_CONFIG, 'إصدار Graph أو معرّف الرقم غير صالح في الإعدادات.');
        } catch (\Throwable $e) {
            $err = MetaError::network(MetaError::networkKind($e));
            WaLog::warning('wa.block_failed', ['conv_id' => $convId, 'type' => $op, 'error_code' => $err->code, 'category' => $err->category]);

            return self::fail($err->code, 'تعذّر الاتصال بميتا (مهلة أو شبكة). راجع صفحة المحظورين قبل الإعادة.');
        }

        $json = (array) ($res->json() ?? []);
        $listed = (array) data_get($json, 'block_users.'.($method === 'POST' ? 'added_users' : 'removed_users'), []);
        if ($res->successful() && self::targetListed($listed, $target)) {
            WaLog::info('wa.block.ok', ['conv_id' => $convId, 'type' => $op]);

            return ['ok' => true, 'error' => null, 'code' => null, 'message_id' => null, 'meta_code' => null];
        }

        $failed = data_get($json, 'block_users.failed_users.0.errors.0');
        $topLevel = is_array(data_get($json, 'error'));
        $err = match (true) {
            is_array($failed) => MetaError::fromStatusError($failed),
            $topLevel || !$res->successful() => MetaError::fromGraphResponse($res),
            default => null,
        };
        if ($err === null) {
            // 2xx بلا الهدف في القائمة ولا خطأ: لا نعدّه حظراً حتى تؤكده المزامنة
            WaLog::warning('wa.block_failed', ['conv_id' => $convId, 'type' => $op, 'error_code' => 'BLOCK_UNCONFIRMED', 'http' => $res->status()]);

            return self::fail('BLOCK_UNCONFIRMED', 'لم تؤكد ميتا العملية. راجع المزامنة في صفحة المحظورين قبل الإعادة.');
        }

        WaLog::warning('wa.block_failed', ['conv_id' => $convId, 'type' => $op, 'meta_code' => $err->metaCode, 'error_code' => $err->code,
            'category' => $err->category, 'fbtrace_id' => $err->fbtraceId, 'http' => $res->status()]);
        if ($topLevel) {
            self::legacyReact($err);
            MetaErrorReactor::react($err, ['conv_id' => $convId, 'source' => 'block']);
        }

        return self::fail($err->code, MetaErrorCatalog::forBlock($err), $err->metaCode) + ['category' => $err->category, 'fbtrace_id' => $err->fbtraceId];
    }

    private static function targetListed(array $listed, array $target): bool
    {
        foreach ($listed as $u) {
            if (!is_array($u)) {
                continue;
            }
            if (isset($target['user'])) {
                $want = PhoneNormalizer::digits($target['user']);
                foreach (['wa_id', 'input'] as $k) {
                    if (PhoneNormalizer::digits((string) ($u[$k] ?? '')) === $want) {
                        return true;
                    }
                }
            } elseif (in_array($target['user_id'], [(string) ($u['user_id'] ?? ''), (string) ($u['input'] ?? '')], true)) {
                return true;
            }
        }

        return false;
    }

    // ==================== ملف الأعمال (E31) ====================

    private const PROFILE_FIELDS = 'about,address,description,email,profile_picture_url,websites,vertical';

    /**
     * GET {pnid}/whatsapp_business_profile. القفل 1 وحده: القراءة مسموحة أثناء الإيقاف الطارئ، فليست إرسالاً.
     *
     * @return array{ok: bool, code: ?string, error: ?string, data?: array<string, mixed>}
     */
    public static function getBusinessProfile(): array
    {
        $s = WaBotSetting::current();
        if (!$s->channelReady()) {
            return self::fail(self::ERR_NOT_CONFIGURED, 'قناة الواتساب غير مهيّأة (رمز الوصول أو معرّف الرقم ناقص).');
        }
        $res = self::profileCall('GET', [(string) $s->phoneNumberId(), 'whatsapp_business_profile'], ['fields' => self::PROFILE_FIELDS]);
        if (!$res['ok']) {
            return $res;
        }

        return $res + ['data' => (array) data_get($res['json'], 'data.0', [])];
    }

    /**
     * اسم العرض وحالته والرقم المعروض: للعرض فقط. لا يُغيَّر من هنا أبداً (WhatsApp Manager وحده).
     *
     * @return array{ok: bool, code: ?string, error: ?string, data?: array<string, mixed>}
     */
    public static function getPhoneIdentity(): array
    {
        $s = WaBotSetting::current();
        if (!$s->channelReady()) {
            return self::fail(self::ERR_NOT_CONFIGURED, 'قناة الواتساب غير مهيّأة (رمز الوصول أو معرّف الرقم ناقص).');
        }
        $res = self::profileCall('GET', [(string) $s->phoneNumberId()], ['fields' => 'verified_name,name_status,display_phone_number']);
        if (!$res['ok']) {
            return $res;
        }

        return $res + ['data' => array_intersect_key((array) $res['json'], array_flip(['verified_name', 'name_status', 'display_phone_number']))];
    }

    /**
     * POST {pnid}/whatsapp_business_profile بالحقول المحددة وحدها مع messaging_product. القفلان 1 و2:
     * لا تعديل للملف أثناء إيقاف طارئ احتياطاً.
     */
    public static function updateBusinessProfile(array $fields): array
    {
        $s = WaBotSetting::current();
        if (!$s->channelReady()) {
            return self::fail(self::ERR_NOT_CONFIGURED, 'قناة الواتساب غير مهيّأة (رمز الوصول أو معرّف الرقم ناقص).');
        }
        if ($s->sendingSuspended()) {
            return self::fail(self::ERR_SUSPENDED, 'الإرسال موقوف حالياً (قفل طارئ): لا تعديل للملف حتى يُرفع.');
        }
        $res = self::profileCall('POST', [(string) $s->phoneNumberId(), 'whatsapp_business_profile'], ['messaging_product' => 'whatsapp'] + $fields);
        if ($res['ok'] && data_get($res['json'], 'success') !== true) {
            return self::fail('PROFILE_UNCONFIRMED', 'لم تؤكد ميتا حفظ الملف. اقرأ الملف من جديد قبل الإعادة.');
        }

        return $res;
    }

    /**
     * صورة الملف بالرفع القابل للاستئناف: جلسة على التطبيق ({app-id}/uploads) ثم الملف الخام، فيعود handle
     * يُمرَّر في profile_picture_handle. يحتاج App ID (E08). القفلان 1 و2.
     *
     * @return array{ok: bool, code: ?string, error: ?string, handle?: string}
     */
    public static function uploadProfilePicture(string $bytes, string $mime, string $fileName): array
    {
        $s = WaBotSetting::current();
        if (!$s->channelReady()) {
            return self::fail(self::ERR_NOT_CONFIGURED, 'قناة الواتساب غير مهيّأة (رمز الوصول أو معرّف الرقم ناقص).');
        }
        if ($s->sendingSuspended()) {
            return self::fail(self::ERR_SUSPENDED, 'الإرسال موقوف حالياً (قفل طارئ): لا تعديل للملف حتى يُرفع.');
        }
        $appId = (string) $s->appId();
        if ($appId === '') {
            return self::fail('APP_ID_MISSING', 'أدخل App ID في إعدادات الواتساب أولاً.');
        }
        try {
            $session = Graph\GraphClient::startUpload($appId, $fileName, strlen($bytes), $mime, (string) $s->token());
            $id = (string) $session->json('id', '');
            if (!$session->successful() || $id === '') {
                return self::profileError($session, 'upload_session');
            }
            $file = Graph\GraphClient::uploadFile($id, $bytes, $mime, (string) $s->token());
        } catch (\InvalidArgumentException) {
            return self::fail(self::ERR_CONFIG, 'إعداد الرفع غير صالح (App ID أو إصدار Graph أو معرّف الجلسة).');
        } catch (\Throwable $e) {
            WaLog::warning('wa.profile.failed', ['type' => class_basename($e), 'field' => 'upload']);

            return self::fail('HTTP_ERROR', 'تعذّر الاتصال بميتا (مهلة أو شبكة).');
        }
        $handle = (string) $file->json('h', '');
        if (!$file->successful() || $handle === '') {
            return self::profileError($file, 'upload_file');
        }

        return ['ok' => true, 'code' => null, 'error' => null, 'handle' => $handle];
    }

    /** @return array{ok: bool, code: ?string, error: ?string, json?: array} */
    private static function profileCall(string $method, array $segments, array $data): array
    {
        try {
            $res = Graph\GraphClient::request($method, $segments, $data, (string) WaBotSetting::current()->token(), 20);
        } catch (\InvalidArgumentException) {
            return self::fail(self::ERR_CONFIG, 'إصدار Graph أو معرّف الرقم غير صالح في الإعدادات.');
        } catch (\Throwable $e) {
            WaLog::warning('wa.profile.failed', ['type' => class_basename($e), 'field' => $segments[1] ?? 'phone']);

            return self::fail('HTTP_ERROR', 'تعذّر الاتصال بميتا (مهلة أو شبكة).');
        }
        if (!$res->successful()) {
            return self::profileError($res, $segments[1] ?? 'phone');
        }

        return ['ok' => true, 'code' => null, 'error' => null, 'json' => (array) ($res->json() ?? [])];
    }

    private static function profileError(Response $res, string $field): array
    {
        $err = MetaError::fromGraphResponse($res);
        WaLog::warning('wa.profile.failed', ['field' => $field, 'meta_code' => $err->metaCode, 'error_code' => $err->code, 'category' => $err->category,
            'fbtrace_id' => $err->fbtraceId, 'http' => $res->status()]);
        self::legacyReact($err);
        MetaErrorReactor::react($err, ['source' => 'profile']);

        return self::fail($err->code, $err->staffMessage, $err->metaCode) + ['action' => $err->action];
    }

    // ==================== رموز QR والروابط القصيرة (E38) ====================

    /**
     * message_qrdls ليست رسائل فلا تمر بـ dispatch، لكنها تحترم العلم wa_qr_links والقفلين 1 و2: القناة مهيّأة،
     * ولا إيقاف طارئ (لا كتابة إلى ميتا أثناءه). لا يُسجَّل الرمز ولا الرابط ولا الرسالة المعبأة: رقم الصف ورمز الخطأ وحدهما.
     */
    private static function qrdlGate(): ?array
    {
        if (!\App\Support\FeatureFlags::on('wa_qr_links')) {
            return self::fail('QR_DISABLED', 'رموز QR مطفأة حالياً.');
        }
        $s = WaBotSetting::current();
        if (!$s->channelReady()) {
            return self::fail(self::ERR_NOT_CONFIGURED, 'قناة الواتساب غير مهيّأة (رمز الوصول أو معرّف الرقم ناقص).');
        }
        if ($s->sendingSuspended()) {
            return self::fail(self::ERR_SUSPENDED, 'الإرسال موقوف حالياً (قفل طارئ): لا تعديل للرموز حتى يُرفع.');
        }

        return null;
    }

    /**
     * POST {pnid}/message_qrdls: رمز ورابط قصير برسالة معبأة. data: code وprefilled_message (كما أعادتها ميتا،
     * وقد تختلف عن المرسلة) وdeep_link_url وqr_image_url (مؤقت غير مضمون البقاء: يُنزَّل فوراً).
     *
     * @return array{ok: bool, code: ?string, error: ?string, data?: array{code: string, prefilled_message: string, deep_link_url: string, qr_image_url: ?string}}
     */
    public static function createQrdl(string $prefilled, string $format = 'PNG', ?int $linkId = null): array
    {
        if ($fail = self::qrdlGate()) {
            return $fail;
        }

        return self::qrdlResult(self::qrdlCall('POST', [(string) WaBotSetting::current()->phoneNumberId(), 'message_qrdls'],
            ['prefilled_message' => $prefilled, 'generate_qr_image' => self::qrFormat($format)], $linkId));
    }

    /** POST على المسار نفسه مع code: يعيد qr_image_url جديداً (بصيغة أخرى مثلاً) للرمز نفسه */
    public static function updateQrdl(string $code, string $prefilled, string $format = 'PNG', ?int $linkId = null): array
    {
        if ($fail = self::qrdlGate()) {
            return $fail;
        }
        if (!self::validQrCode($code)) {
            return self::fail('QR_CODE_INVALID', 'معرّف الرمز غير صالح.');
        }

        return self::qrdlResult(self::qrdlCall('POST', [(string) WaBotSetting::current()->phoneNumberId(), 'message_qrdls'],
            ['code' => $code, 'prefilled_message' => $prefilled, 'generate_qr_image' => self::qrFormat($format)], $linkId));
    }

    /**
     * DELETE {pnid}/message_qrdls/{code}. gone=true إن لم تجده ميتا أصلاً (حُذف من WhatsApp Manager مثلاً).
     *
     * @return array{ok: bool, code: ?string, error: ?string, gone?: bool}
     */
    public static function deleteQrdl(string $code, ?int $linkId = null): array
    {
        if ($fail = self::qrdlGate()) {
            return $fail;
        }
        if (!self::validQrCode($code)) {
            return self::fail('QR_CODE_INVALID', 'معرّف الرمز غير صالح.');
        }
        try {
            $res = Graph\GraphClient::request('DELETE', [(string) WaBotSetting::current()->phoneNumberId(), 'message_qrdls', $code], [],
                (string) WaBotSetting::current()->token(), 20);
        } catch (\InvalidArgumentException) {
            return self::fail(self::ERR_CONFIG, 'إصدار Graph أو معرّف الرقم غير صالح في الإعدادات.');
        } catch (\Throwable $e) {
            WaLog::warning('wa.qrdl.failed', ['type' => class_basename($e), 'qr_link_id' => $linkId]);

            return self::fail('HTTP_ERROR', 'تعذّر الاتصال بميتا (مهلة أو شبكة).');
        }
        if ($res->status() === 404 || ((int) $res->json('error.code') === 100 && (int) $res->json('error.error_subcode') === 33)) {
            return ['ok' => true, 'code' => null, 'error' => null, 'gone' => true];
        }
        if (!$res->successful()) {
            return self::qrdlError($res, $linkId);
        }

        return ['ok' => true, 'code' => null, 'error' => null, 'gone' => false];
    }

    /**
     * GET {pnid}/message_qrdls بالصفحات (للمطابقة الأسبوعية وحدها). القائمة 2000 حداً أقصى.
     *
     * @return array{ok: bool, code: ?string, error: ?string, data?: list<array{code: string, prefilled_message: string, deep_link_url: string}>}
     */
    public static function listQrdls(int $maxPages = 25): array
    {
        if ($fail = self::qrdlGate()) {
            return $fail;
        }
        $out = [];
        $after = null;
        for ($page = 0; $page < $maxPages; $page++) {
            $res = self::qrdlCall('GET', [(string) WaBotSetting::current()->phoneNumberId(), 'message_qrdls'],
                array_filter(['limit' => 100, 'after' => $after]), null);
            if (!$res['ok']) {
                return $res;
            }
            foreach ((array) data_get($res['json'], 'data', []) as $row) {
                if (is_array($row) && is_string($row['code'] ?? null)) {
                    $out[] = ['code' => (string) $row['code'], 'prefilled_message' => (string) ($row['prefilled_message'] ?? ''),
                        'deep_link_url' => (string) ($row['deep_link_url'] ?? '')];
                }
            }
            $after = data_get($res['json'], 'paging.next') ? data_get($res['json'], 'paging.cursors.after') : null;
            if (!is_string($after) || $after === '') {
                break;
            }
        }

        return ['ok' => true, 'code' => null, 'error' => null, 'data' => $out, 'truncated' => isset($after) && is_string($after) && $after !== ''];
    }

    private static function qrFormat(string $format): string
    {
        return strtoupper($format) === 'SVG' ? 'SVG' : 'PNG';
    }

    private static function validQrCode(string $code): bool
    {
        return preg_match('/^[A-Za-z0-9_-]{1,64}$/', $code) === 1;
    }

    /** @return array{ok: bool, code: ?string, error: ?string, json?: array} */
    private static function qrdlCall(string $method, array $segments, array $data, ?int $linkId): array
    {
        try {
            $res = Graph\GraphClient::request($method, $segments, $data, (string) WaBotSetting::current()->token(), 20);
        } catch (\InvalidArgumentException) {
            return self::fail(self::ERR_CONFIG, 'إصدار Graph أو معرّف الرقم غير صالح في الإعدادات.');
        } catch (\Throwable $e) {
            WaLog::warning('wa.qrdl.failed', ['type' => class_basename($e), 'qr_link_id' => $linkId]);

            return self::fail('HTTP_ERROR', 'تعذّر الاتصال بميتا (مهلة أو شبكة).');
        }
        if (!$res->successful()) {
            return self::qrdlError($res, $linkId);
        }

        return ['ok' => true, 'code' => null, 'error' => null, 'json' => (array) ($res->json() ?? [])];
    }

    private static function qrdlResult(array $res): array
    {
        if (!$res['ok']) {
            return $res;
        }
        $j = $res['json'];
        $code = (string) ($j['code'] ?? '');
        $link = (string) ($j['deep_link_url'] ?? '');
        if (!self::validQrCode($code) || !str_starts_with($link, 'https://')) {
            return self::fail('QR_UNCONFIRMED', 'لم تُعد ميتا رمزاً صالحاً. اقرأ القائمة قبل الإعادة.');
        }

        return ['ok' => true, 'code' => null, 'error' => null, 'data' => [
            'code' => $code,
            'prefilled_message' => (string) ($j['prefilled_message'] ?? ''),
            'deep_link_url' => mb_substr($link, 0, 191),
            'qr_image_url' => is_string($j['qr_image_url'] ?? null) ? $j['qr_image_url'] : null,
        ]];
    }

    private static function qrdlError(Response $res, ?int $linkId): array
    {
        $err = MetaError::fromGraphResponse($res);
        WaLog::warning('wa.qrdl.failed', ['qr_link_id' => $linkId, 'meta_code' => $err->metaCode, 'error_code' => $err->code,
            'category' => $err->category, 'fbtrace_id' => $err->fbtraceId, 'http' => $res->status()]);
        self::legacyReact($err);
        MetaErrorReactor::react($err, ['source' => 'qrdl']);

        return self::fail($err->code, $err->staffMessage, $err->metaCode) + ['action' => $err->action];
    }

    // ==================== المحرّك ====================

    /** @return array{to: string}|array{recipient: string}|null */
    private static function target(WaConversation $conv, string $source): ?array
    {
        if (filled($conv->wa_phone)) {
            return ['to' => (string) $conv->wa_phone];
        }
        if (filled($conv->wa_user_id) && IdentityMode::allowsRecipient($source)) {
            return ['recipient' => (string) $conv->wa_user_id];
        }

        return null;
    }

    /**
     * @param  array  $columns  أعمدة المغلف (E32): الاقتباس وmedia_* للصادر
     * @param  WaMessage|null  $reserved  صف حجزه reserve() (ملف مؤجل): يُكمَل هو نفسه بالمفتاح نفسه بدل صف جديد
     */
    private static function dispatch(
        WaConversation $conv,
        array $payload,
        string $displayBody,
        string $msgType,
        string $source,
        ?int $staffId = null,
        string $context = 'reply',
        ?string $sendKey = null,
        array $columns = [],
        ?WaMessage $reserved = null,
        array $meta = [],
        bool $asReply = true
    ): array {
        // الرسالة الصوتية الأصلية (E34) من الموظف وحده: لا يرسل البوت ولا النظام صوتاً أياً كان المسار
        if ($source !== 'staff' && Outbound\VoiceNote::isVoicePayload($payload)) {
            if ($reserved !== null) {
                self::failReserved($reserved, ['code' => self::ERR_UNSUPPORTED, 'error' => 'الرسائل الصوتية من الموظف وحده.']);
            }

            return self::voiceRefused($conv, $source);
        }
        $s = WaBotSetting::current();

        // ── الأقفال 0–4 (الموظف، القناة، الإيقاف، النافذة، الوجهة). تُعاد كلها قبل كل إعادة في الدفتر
        [$fail, $target] = self::gate($conv, $s, $source, $staffId);
        if ($fail !== null) {
            if ($reserved !== null) {
                self::failReserved($reserved, $fail);
            }

            return $fail;
        }
        $payload = ['messaging_product' => 'whatsapp'] + $target + $payload;

        // معاينة الرابط (E33): هنا وحدها تُقرَّر، لرد الموظف بالعلم wa_link_preview ولرابط عام تقبله LinkPreviewPolicy.
        // البوت والنظام false دائماً ولو طُلبت
        if (isset($payload['text']) && is_array($payload['text']) && array_key_exists('preview_url', $payload['text'])) {
            $payload['text']['preview_url'] = $payload['text']['preview_url'] === true && $source === 'staff'
                && \App\Support\FeatureFlags::on('wa_link_preview') && LinkPreviewPolicy::allows((string) ($payload['text']['body'] ?? ''));
        }

        // الدبوس والبطاقة (E33): من الجدولين المعتمدين حرفياً، لكل مصدر وأياً كان وضع السياسة، قبل أي HTTP
        if ($why = Outbound\OutboundPolicy::structured($payload, $meta)) {
            WaLog::warning('wa.policy', ['conv_id' => $conv->id, 'source' => $source, 'policy_reason' => $why, 'type' => $msgType]);

            return self::failLocal('POLICY_'.strtoupper($why), 'رُفضت الرسالة: '.Outbound\OutboundPolicy::describe([$why]).'.');
        }

        // ── البوابة 5: سياسة المحتوى الصادر (E15.9). القرار يُحفظ مع الرسالة (payload._policy) ولا يُرسل لميتا
        $decision = Outbound\OutboundPolicy::evaluate($conv, $payload, $displayBody, $source, $context);
        $stored = $payload;
        // بيانات وصفية للصف (E33): رقم العرض والموقع والبطاقات والتصحيح. تُحفظ ولا تُرسل لميتا
        if ($meta !== []) {
            $stored['_meta'] = $meta;
        }
        if ($decision->reasons || $decision->warnings) {
            $enforce = $source !== 'staff' && Outbound\OutboundPolicy::enforced();
            WaLog::warning('wa.policy', ['conv_id' => $conv->id, 'source' => $source, 'policy_reason' => implode(',', $decision->reasons ?: $decision->warnings)]);
            if ($enforce && $decision->blocks()) {
                if ($reserved !== null) {
                    self::failReserved($reserved, ['code' => 'POLICY_BLOCKED', 'error' => 'حُجبت الرسالة قبل الإرسال بسياسة المحتوى الصادر.']);
                }

                return self::blockByPolicy($conv, $decision, $payload, $displayBody, $msgType, $source, $staffId, $s->phoneNumberId(), $reserved ? [] : $columns);
            }
            $stored['_policy'] = ['mode' => $enforce ? 'enforce' : 'log', 'context' => $context] + $decision->toArray();
        }

        // ── دفتر الإرسال (E19، العلم send_ledger): صف queued قبل النداء، ثم حسمه. الملف المحجوز (E32) يمر به دائماً
        if (self::ledgerOn() || $reserved !== null) {
            return self::ledger($conv, $s, $payload, $stored, $displayBody, $msgType, $source, $staffId, $sendKey, $columns, $reserved, $asReply);
        }

        // إشعار القراءة عند رد الموظف (E35): أعلى وارد لحظة الإرسال، فلا يُعلَّم مقروءاً ما يصل أثناء النداء
        $receiptUpto = ReadReceipts::replyUpto($conv, $source, $msgType);
        try {
            $res = Graph\GraphClient::request('POST', [(string) $s->phoneNumberId(), 'messages'], $payload, (string) $s->token(), 25);
            // ميتا رفضت الاقتباس صراحةً (E33): إعادة واحدة بلا context، والصف يحمل _context_dropped
            if (!$res->successful() && self::contextRejected($res, $payload)) {
                [$payload, $stored] = self::dropContext($conv, $res, $payload, $stored);
                $res = Graph\GraphClient::request('POST', [(string) $s->phoneNumberId(), 'messages'], $payload, (string) $s->token(), 25);
            }
        } catch (\InvalidArgumentException $e) {
            WaLog::error('graph.config.invalid', ['reason' => $e->getMessage()]);
            $err = MetaError::local(self::ERR_CONFIG);

            return self::failWith($err, self::ERR_CONFIG, $err->staffMessage);
        } catch (\Throwable $e) {
            return self::networkFailure($e, $conv, $displayBody, $msgType, $source, $staffId, $stored, $s->phoneNumberId(), $columns);
        }

        $json = $res->json() ?? [];

        if (!$res->successful()) {
            // رمز ميتا لا عنوانها: الصف failed بالرمز والفئة وfbtrace_id، ونص ميتا الخام لا يُخزَّن (details مقنّعة في الحمولة)
            $err = MetaError::fromGraphResponse($res);
            [$code, $shown] = self::shown($err);
            $row = self::store($conv, null, $displayBody, $msgType, $source, 'failed', $shown, $staffId, $stored + ['_meta_error' => $err->forPayload()],
                $err->metaCode, $err->fbtraceId, $err->code, $s->phoneNumberId(), $err->category, $columns);
            self::emitFailed($row, $err, $source, $staffId);
            WaLog::warning('wa.send_failed', ['conv_id' => $conv->id, 'message_id' => $row?->id, 'source' => $source, 'type' => $msgType,
                'meta_code' => $err->metaCode, 'error_code' => $err->code, 'category' => $err->category, 'fbtrace_id' => $err->fbtraceId,
                'http' => $res->status(), 'attempt' => 1]);
            self::legacyReact($err);
            MetaErrorReactor::react($err, ['conv_id' => $conv->id, 'message_id' => $row?->id, 'source' => $source]);
            SendHealthMonitor::recordFailure($err);

            return self::failWith($err, $code, $shown);
        }

        // 200 بلا معرّف رسالة: قبلتها ميتا ولا دليل على الإرسال، فلا تُعرض «أُرسلت» (E12.8)
        $wamid = data_get($json, 'messages.0.id');
        $status = is_string($wamid) && $wamid !== '' ? 'sent' : WaMessage::STATUS_UNKNOWN;
        $row = self::store($conv, $status === 'sent' ? $wamid : null, $displayBody, $msgType, $source, $status, null, $staffId, $stored, phoneNumberId: $s->phoneNumberId(), columns: $columns);
        if ($status === WaMessage::STATUS_UNKNOWN) {
            WaLog::warning('wa.send.unknown', ['conv_id' => $conv->id, 'source' => $source, 'type' => $msgType]);
        } else {
            WaLog::info('wa.send.ok', ['conv_id' => $conv->id, 'wamid' => $wamid, 'source' => $source, 'type' => $msgType]);
            self::afterWamid($row, (string) $wamid, $source, $staffId);
            SendHealthMonitor::recordSuccess($s);
        }

        self::afterSent($conv, $s, $source, $displayBody, $asReply);
        if ($status === 'sent') {
            ReadReceipts::queueAfterReply($conv, $receiptUpto);
        }

        return ['ok' => true, 'error' => null, 'code' => null, 'message_id' => $status === 'sent' ? $wamid : null] + ($status === WaMessage::STATUS_UNKNOWN ? ['unconfirmed' => true, 'unknown' => true] : []);
    }

    /**
     * الأقفال بالترتيب: 0) الموظف يرى المحادثة (سياج E04، يغطي الرد من الصندوق وإرسال السعر؛ البوت والنظام لا يمران به)
     * 1) القناة مهيّأة 2) الإيقاف الطارئ 3) نافذة 24 ساعة 4) الوجهة: الرقم إن عُرف، وإلا BSUID إن سمح وضع الهوية. لا الاثنان معاً.
     *
     * @return array{0: ?array, 1: ?array} [نتيجة الفشل، الوجهة]
     */
    private static function gate(WaConversation $conv, WaBotSetting $s, string $source, ?int $staffId): array
    {
        if ($source === 'staff') {
            $staff = $staffId ? \App\Models\User::find($staffId) : null;
            if (!$staff || !TopicRouter::canSee($conv, $staff)) {
                return [self::failLocal(self::ERR_OUT_OF_SCOPE, 'هذه المحادثة خارج نطاقك — لا يمكنك مراسلة هذا العميل.'), null];
            }
        }
        if (!$s->channelReady()) {
            return [self::failLocal(self::ERR_NOT_CONFIGURED, 'قناة الواتساب غير مهيّأة (رمز الوصول أو معرّف الرقم ناقص).'), null];
        }
        if ($s->sendingSuspended()) {
            return [self::failLocal(self::ERR_SUSPENDED, 'الإرسال موقوف حالياً (قفل طارئ).'), null];
        }
        // حجب ميتا (E22): BLOCKED يمنع دائماً، وTOKEN_INVALID بالعلم channel_health_block_on_token. LIMITED لا يمنع شيئاً
        if ($blocked = ChannelHealth::gateError($s)) {
            return [self::failLocal(...$blocked), null];
        }
        if (!$conv->isWindowOpen()) {
            return [self::failLocal(self::ERR_WINDOW, 'انتهت نافذة الـ24 ساعة — لا يمكن الإرسال حتى يراسلك العميل من جديد.'), null];
        }
        // المحظور رسمياً (E31): بلا نداء ولا صف. القفل بعد النافذة وقبل اختيار الوجهة، فلا يتغيّر ترتيب ما قبله
        if ($conv->isBlocked()) {
            return [self::failLocal(self::ERR_BLOCKED, 'هذا العميل محظور — فكّ الحظر أولاً من صفحة المحظورين.'), null];
        }
        $target = self::target($conv, $source);
        if ($target === null) {
            return [self::failLocal(self::ERR_NO_RECIPIENT, 'العميل يراسل باسم مستخدم ورقمه غير ظاهر، والرد عليه غير مفعّل بعد — أبلغ المدير.'), null];
        }

        return [null, $target];
    }

    /**
     * بعد قبول ميتا (أو مهلة رسالة الموظف): وقت المحادثة ومعاينتها، وردّ الموظف يوقف البوت مؤقتاً ويصفّر المؤشرات.
     * $asReply=false (تفاعل الموظف، E33): إقرار لا رد، فلا يُسكت البوت ولا يخفي شارة التدخّل ولا يمس المعاينة والعلامة.
     */
    private static function afterSent(WaConversation $conv, WaBotSetting $s, string $source, string $displayBody, bool $asReply = true): void
    {
        if (!$asReply) {
            return;
        }
        $update = [
            'last_outbound_at' => now(),
            'last_message_at' => now(),
            'last_message_preview' => mb_substr(trim($displayBody), 0, 160),
        ];
        if ($source === 'staff') {
            $update['bot_paused_until'] = now()->addMinutes(max(1, (int) $s->pause_minutes));
            $update['unread_count'] = 0;
            $update += InboxFollowup::available() ? ['marked_unread_at' => null, 'marked_unread_by' => null] : [];
            $update['needs_attention'] = false;
            if (ReplyState::available()) {
                $update['attention_reason'] = null;
            }
        }
        $conv->update($update);

        // رد الموظف يخدم كل ما وصل قبله: لا يجيب البوت عنه لاحقاً
        if ($source === 'staff') {
            ReplyState::advanceToNewest($conv);
        }
    }

    // ==================== دفتر الإرسال (E19) ====================

    private static ?bool $hasLedgerColumns = null;

    /** العلم send_ledger، وبعد الهجرة 2026_10_14_000019 وحدها */
    private static function ledgerOn(): bool
    {
        if (!\App\Support\FeatureFlags::on('send_ledger')) {
            return false;
        }

        return self::$hasLedgerColumns ??= \Illuminate\Support\Facades\Schema::hasColumns('wa_messages', ['attempts', 'answers_in_id', 'sent_at', 'error_category']);
    }

    /** biz_opaque_callback_data: البادئة ورقم الصف وحدهما، بلا أي بيان شخصي. تعيده ميتا في ويبهوك الحالة */
    public static function opaque(int $rowId): string
    {
        return config('whatsapp.send.opaque_prefix', 'nj').':m:'.$rowId;
    }

    /**
     * الصف queued يُكتب بعد كل الأقفال وقبل النداء، ثم يُحسم الصف نفسه: sent أو failed (المفتاح محرَّر) أو unknown (محجوز).
     * لا معاملة تلف نداء HTTP: كل كتابة عبارة قصيرة مستقلة. وحسم الحالة مشروط بـ status='queued' فلا يكتب فوق ما حسمه الويبهوك.
     * الإعادة (العلم send_retry) لرموز ميتا العابرة والخنق ولفشل الاتصال قبل الإرسال فقط، بتأخير 4^X وإعادة الأقفال قبل كل محاولة.
     * لا إعادة أبداً بعد مهلة الطلب ولا بعد 5xx بلا JSON: ربما قبلتها ميتا.
     */
    private static function ledger(WaConversation $conv, WaBotSetting $s, array $payload, array $stored, string $displayBody,
        string $msgType, string $source, ?int $staffId, ?string $sendKey, array $columns = [], ?WaMessage $reserved = null, bool $asReply = true): array
    {
        $hash = self::reqHash($conv, $msgType, $displayBody, $columns);
        $run = ReplyRun::current();
        if ($reserved !== null) {
            // ملف محجوز (E32): يُطالَب به ذرياً (queued ولم تبدأ محاولة)، فلا يرسله تشغيلان ولا يكتب فوق ما حسمه المنظّف
            $row = $reserved;
            $keep = array_diff_key(array_filter((array) ($reserved->payload ?? []), fn ($k) => str_starts_with((string) $k, '_'), ARRAY_FILTER_USE_KEY), ['_caption' => 1]);
            $claimed = WaMessage::whereKey($row->id)->where('status', WaMessage::STATUS_QUEUED)->where('attempts', 0)->update($columns + [
                'payload' => json_encode($stored + $keep, JSON_UNESCAPED_UNICODE), 'body' => $displayBody, 'attempts' => 1,
            ]);
            if ($claimed === 0) {
                WaLog::warning('wa.send.reserved_lost', ['conv_id' => $conv->id, 'message_id' => $row->id, 'source' => $source, 'type' => $msgType]);

                return self::failLocal('SEND_IN_PROGRESS', MetaError::local('SEND_IN_PROGRESS')->staffMessage)
                    + ['status' => 'queued', 'row_id' => $row->id, 'replayed' => false, 'conflict' => true];
            }
        } else {
            try {
                $row = WaMessage::create($columns + [
                    'conversation_id' => $conv->id, 'direction' => 'out', 'source' => $source, 'msg_type' => $msgType,
                    'body' => $displayBody, 'payload' => $stored + ['_req_hash' => $hash], 'status' => WaMessage::STATUS_QUEUED,
                    'staff_id' => $staffId, 'phone_number_id' => $s->phoneNumberId(), 'send_key' => $sendKey !== null ? mb_substr($sendKey, 0, 64) : null,
                    'answers_in_id' => $source !== 'staff' && $run?->uptoId ? $run->uptoId : null, 'attempts' => 0,
                ]);
            } catch (\Illuminate\Database\UniqueConstraintViolationException) {
                return self::replay((string) $sendKey, $hash);
            }
        }
        WaLog::info('wa.send.queued', ['conv_id' => $conv->id, 'message_id' => $row->id, 'source' => $source, 'type' => $msgType]);
        $payload['biz_opaque_callback_data'] = self::opaque($row->id);

        $maxRetries = \App\Support\FeatureFlags::on('send_retry')
            ? (int) config($source === 'staff' ? 'whatsapp.send.retry_staff_max' : 'whatsapp.send.retry_system_max', 1) : 0;
        $retries = 0;
        $slept = 0.0;
        $contextDropped = false;
        for ($attempt = 1; ; $attempt++) {
            WaMessage::whereKey($row->id)->update(['attempts' => $attempt]);
            $res = null;
            $exc = null;
            try {
                $res = Graph\GraphClient::request('POST', [(string) $s->phoneNumberId(), 'messages'], $payload, (string) $s->token(), 25, 5);
            } catch (\InvalidArgumentException $e) {
                WaLog::error('graph.config.invalid', ['reason' => $e->getMessage()]);
                $err = MetaError::local(self::ERR_CONFIG);

                return self::resolveFailed($row, $conv, $err, $sendKey, $source, $msgType, $attempt, null, $err->staffMessage);
            } catch (\Throwable $e) {
                $exc = $e;
            }

            if ($res !== null && $res->successful()) {
                return self::resolveAccepted($row, $conv, $s, $res, $source, $displayBody, $msgType, $attempt, $sendKey, $asReply);
            }

            // ميتا رفضت الاقتباس صراحةً (E33): الصف نفسه يُعاد مرة واحدة بلا context، بلا انتظار ولا من حصة الإعادات
            if ($res !== null && !$contextDropped && self::contextRejected($res, $payload)) {
                [$payload] = self::dropContext($conv, $res, $payload, []);
                $contextDropped = true;
                $fresh = (array) (WaMessage::find($row->id)?->payload ?? []);
                unset($fresh['context']);
                WaMessage::whereKey($row->id)->update(['payload' => json_encode($fresh + ['_context_dropped' => true], JSON_UNESCAPED_UNICODE)]);

                continue;
            }

            $ref = null;
            if ($exc !== null) {
                WaLog::error('send.connection_failed', ['conv_id' => $conv->id, 'message_id' => $row->id, 'type' => class_basename($exc)]);
                $ref = \App\Support\ErrorRef::log($exc, 'wa.send.connection_failed', ['conv_id' => $conv->id]);
            }
            $err = $exc !== null ? MetaError::network(MetaError::networkKind($exc)) : MetaError::fromGraphResponse($res);
            $notSent = $exc !== null && $err->code === 'HTTP_CONNECT_FAILED';
            // مهلة، أو انقطاع بعد الاتصال، أو 5xx بلا JSON: ربما قبلتها ميتا
            if (!$notSent && ($exc !== null || $err->code === 'HTTP_5XX_NO_BODY')) {
                return self::resolveUnknown($row, $conv, $s, $err, $source, $displayBody, $msgType, $attempt, $ref, $res?->status(), $asReply);
            }

            $delay = self::retryDelay($err, $notSent, $retries, $maxRetries, $slept, $source);
            if ($delay === null) {
                return self::resolveFailed($row, $conv, $err, $sendKey, $source, $msgType, $attempt, $res?->status(), null, $ref);
            }

            WaLog::info('wa.send.retry', ['message_id' => $row->id, 'attempt' => $attempt, 'meta_code' => $err->metaCode, 'category' => $err->category, 'error_code' => $err->code]);
            \Illuminate\Support\Sleep::for((int) round($delay * 1000))->milliseconds();
            $slept += $delay;
            $retries++;

            // قبل كل محاولة: الأقفال كلها من القاعدة الآن، وللبوت هل ما زال الرد مناسباً (ردّ موظف؟ أُطفئ البوت؟)
            $conv->refresh();
            $s = WaBotSetting::current();
            [$fail] = self::gate($conv, $s, $source, $staffId);
            $stale = $fail === null && $source !== 'staff' ? $run?->mayDeliver($conv) : null;
            if ($fail !== null || $stale !== null) {
                $local = MetaError::local($fail['code'] ?? 'REPLY_STALE');

                return self::resolveFailed($row, $conv, $local, $sendKey, $source, $msgType, $attempt, null, $fail['error'] ?? $local->staffMessage, null, array_filter(['_stale' => $stale]));
            }
        }
    }

    /** التأخير قبل المحاولة التالية (ثوانٍ)، أو null: لا إعادة */
    private static function retryDelay(MetaError $err, bool $notSent, int $retries, int $maxRetries, float $slept, string $source): ?float
    {
        if ($retries >= $maxRetries) {
            return null;
        }
        if (!$notSent) {
            if ($err->metaCode === null || MetaErrorCatalog::neverRetry($err->metaCode, $err->category)
                || !in_array($err->category, ['throttle', 'transient'], true)) {
                return null;
            }
            // العابر مرة واحدة، والخنق حتى محاولتين
            if ($err->category === 'transient' && $retries >= 1) {
                return null;
            }
        }
        $delay = (4 ** $retries) + random_int(0, 250) / 1000;
        if ($slept + $delay > (float) config('whatsapp.send.retry_max_sleep', 8)) {
            return null;
        }
        // سلسلة ثوابت E07: ما بقي من ميزانية الرد يكفي الانتظار ونداءً كاملاً
        $run = ReplyRun::current();
        if ($run && $source !== 'staff' && $run->remaining() <= $delay + 30) {
            return null;
        }

        return $delay;
    }

    /** 2xx: sent بالمعرّف، أو unknown إن لم يأتِ معرّف */
    private static function resolveAccepted(WaMessage $row, WaConversation $conv, WaBotSetting $s, Response $res, string $source,
        string $displayBody, string $msgType, int $attempt, ?string $sendKey, bool $asReply = true): array
    {
        $wamid = data_get($res->json() ?? [], 'messages.0.id');
        if (!is_string($wamid) || $wamid === '') {
            return self::resolveUnknown($row, $conv, $s, MetaError::local('OUTCOME_UNKNOWN'), $source, $displayBody, $msgType, $attempt, null, $res->status(), $asReply);
        }
        $moved = WaMessage::whereKey($row->id)->where('status', WaMessage::STATUS_QUEUED)->update(['status' => 'sent', 'wamid' => $wamid, 'sent_at' => now()]);
        if ($moved === 0) {
            // سبق الويبهوك (بـ biz_opaque) فرفع الحالة: يُكمَل المعرّف وحده
            WaMessage::whereKey($row->id)->whereNull('wamid')->update(['wamid' => $wamid, 'sent_at' => now()]);
        }
        WaLog::info('wa.send.ok', ['conv_id' => $conv->id, 'message_id' => $row->id, 'wamid' => $wamid, 'source' => $source, 'type' => $msgType, 'attempt' => $attempt]);
        self::afterWamid($row, $wamid, $source, $row->staff_id !== null ? (int) $row->staff_id : null);
        SendHealthMonitor::recordSuccess($s);
        self::afterSent($conv, $s, $source, $displayBody, $asReply);
        // إشعار القراءة عند رد الموظف (E35): ما وصل قبل صف الرد وحده (الصف كُتب قبل النداء)
        ReadReceipts::queueAfterReply($conv, ReadReceipts::replyUpto($conv, $source, $msgType, $row->id));

        return ['ok' => true, 'error' => null, 'code' => null, 'message_id' => $wamid, 'meta_code' => null,
            'status' => 'sent', 'row_id' => $row->id, 'replayed' => false];
    }

    /** رفض صريح من ميتا، أو فشل اتصال مؤكد قبل الإرسال، أو قفل بعد إعادة الفحص: failed، والمفتاح محرَّر لإعادة مشروعة */
    private static function resolveFailed(WaMessage $row, WaConversation $conv, MetaError $err, ?string $sendKey, string $source, string $msgType,
        int $attempt, ?int $http, ?string $message = null, ?string $ref = null, array $extra = []): array
    {
        [$code, $shown] = $message !== null ? [$err->code, $message] : self::shown($err);
        if ($ref !== null) {
            $shown .= " رقم المرجع: {$ref}";
        }
        // الحمولة من القاعدة الآن: ويبهوك قد أضاف statusHistory أثناء النداء
        $payload = (array) (WaMessage::find($row->id)?->payload ?? []);
        $payload = $extra + ['_meta_error' => $err->forPayload()] + ($sendKey !== null ? ['_released_key' => $sendKey] : []) + $payload;
        $moved = WaMessage::whereKey($row->id)->where('status', WaMessage::STATUS_QUEUED)->update([
            'status' => WaMessage::STATUS_FAILED, 'send_key' => null, 'error_message' => $shown,
            'meta_code' => $err->metaCode, 'fbtrace_id' => $err->fbtraceId, 'error_code' => mb_substr($err->code, 0, 32), 'error_category' => $err->category,
            'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE),
        ]);
        if ($moved > 0) {
            self::emitFailed($row, $err, $source, $row->staff_id !== null ? (int) $row->staff_id : null);
        }
        WaLog::warning('wa.send_failed', ['conv_id' => $conv->id, 'message_id' => $row->id, 'source' => $source, 'type' => $msgType,
            'meta_code' => $err->metaCode, 'error_code' => $err->code, 'category' => $err->category, 'fbtrace_id' => $err->fbtraceId,
            'http' => $http, 'attempt' => $attempt, 'status' => 'failed']);
        if ($err->metaCode !== null || str_starts_with($err->code, 'HTTP_') || $err->code === self::ERR_PROOF) {
            self::legacyReact($err);
            MetaErrorReactor::react($err, ['conv_id' => $conv->id, 'message_id' => $row->id, 'source' => $source]);
            SendHealthMonitor::recordFailure($err);
        }

        return self::failWith($err, $code, $shown) + ['status' => 'failed', 'row_id' => $row->id, 'replayed' => false];
    }

    /** لا نعرف هل وصلت: unknown والمفتاح محجوز (لا يكرره أحد). رسالة الموظف توقف البوت كأنها أُرسلت */
    private static function resolveUnknown(WaMessage $row, WaConversation $conv, WaBotSetting $s, MetaError $err, string $source,
        string $displayBody, string $msgType, int $attempt, ?string $ref, ?int $http, bool $asReply = true): array
    {
        $shown = $err->staffMessage.($ref !== null ? " رقم المرجع: {$ref}" : '');
        WaMessage::whereKey($row->id)->where('status', WaMessage::STATUS_QUEUED)->update([
            'status' => WaMessage::STATUS_UNKNOWN, 'error_message' => $shown, 'error_code' => mb_substr($err->code, 0, 32), 'error_category' => $err->category,
        ]);
        WaLog::warning('wa.send.unknown', ['conv_id' => $conv->id, 'message_id' => $row->id, 'source' => $source, 'type' => $msgType,
            'error_code' => $err->code, 'http' => $http, 'attempt' => $attempt, 'status' => 'unknown']);
        MetaErrorReactor::react($err, ['conv_id' => $conv->id, 'message_id' => $row->id, 'source' => $source]);
        SendHealthMonitor::recordFailure($err);
        if ($source === 'staff') {
            self::afterSent($conv, $s, $source, $displayBody, $asReply);
        }

        return self::failWith($err, $err->code, $shown) + ['status' => 'unknown', 'unknown' => true, 'row_id' => $row->id, 'replayed' => false];
    }

    /**
     * المفتاح محجوز بصف سابق: أُرسل (يعود replayed بلا نداء)، أو قيد الإرسال، أو غير مؤكد. الصف failed لا يصطدم لأن مفتاحه حُرّر.
     * نص مختلف بمفتاح الموظف نفسه يُرفض (خلل في المنشئ). أما المفتاح الآلي فحالة صفه تحكم: رد النموذج المعاد توليده
     * يختلف نصه غالباً، والعميل نال رداً لهذه الرسائل فلا يُرسل ثانٍ.
     */
    private static function replay(string $sendKey, string $hash): array
    {
        $existing = WaMessage::where('send_key', $sendKey)->first();
        if (!$existing) {
            return self::failLocal('SEND_IN_PROGRESS', MetaError::local('SEND_IN_PROGRESS')->staffMessage) + ['status' => 'queued', 'replayed' => false, 'conflict' => true];
        }
        if (str_starts_with($sendKey, 'staff:') && data_get($existing->payload, '_req_hash') !== $hash) {
            return self::failLocal('IDEMPOTENCY_MISMATCH', 'مفتاح الإرسال مستعمل لنص آخر — اضغط «إرسال» مرة أخرى.') + ['status' => 'mismatch', 'replayed' => false, 'conflict' => true];
        }
        WaLog::info('wa.send.replayed', ['conv_id' => $existing->conversation_id, 'message_id' => $existing->id, 'status' => $existing->status]);
        if (in_array($existing->status, ['sent', 'delivered', 'read', 'played'], true)) {
            return ['ok' => true, 'error' => null, 'code' => null, 'message_id' => $existing->wamid, 'meta_code' => null,
                'status' => 'replayed', 'row_id' => $existing->id, 'replayed' => true];
        }
        if ($existing->status === WaMessage::STATUS_QUEUED) {
            return self::failLocal('SEND_IN_PROGRESS', MetaError::local('SEND_IN_PROGRESS')->staffMessage) + ['status' => 'queued', 'row_id' => $existing->id, 'replayed' => false, 'conflict' => true];
        }

        return self::failLocal('OUTCOME_UNKNOWN', 'قد تكون الرسالة وصلت. تحقق من الخيط قبل إعادة الإرسال.')
            + ['status' => 'unknown', 'unknown' => true, 'row_id' => $existing->id, 'replayed' => false, 'conflict' => true];
    }

    /**
     * فشل الاتصال بميتا: نص الاستثناء يحمل الرابط، فللسجل منقّحاً ورقم مرجعي للواجهة.
     * رسالة الموظف تترك أثراً دائماً (E12)، ومع meta_error_v2 كل رسالة: تعذّر الاتصال => failed (لم تُرسل)،
     * والمهلة => unknown «غير مؤكد» (قد تكون وصلت، فلا يعيدها أحد دون أن يرى ذلك).
     */
    private static function networkFailure(\Throwable $e, WaConversation $conv, string $displayBody, string $msgType, string $source, ?int $staffId, array $stored, ?string $phoneNumberId, array $columns = []): array
    {
        WaLog::error('send.connection_failed', ['conv_id' => $conv->id, 'type' => class_basename($e)]);
        $ref = \App\Support\ErrorRef::log($e, 'wa.send.connection_failed', ['conv_id' => $conv->id]);
        $err = MetaError::network(MetaError::networkKind($e));
        $v2 = MetaErrorCatalog::v2();

        $row = null;
        if ($source === 'staff' || $v2) {
            $status = !$v2 || $err->code !== 'HTTP_CONNECT_FAILED' ? WaMessage::STATUS_UNKNOWN : 'failed';
            $text = $v2 ? "{$err->staffMessage} رقم المرجع: {$ref}" : "لم يصل رد ميتا (مهلة أو شبكة). رقم المرجع: {$ref}";
            $row = self::store($conv, null, $displayBody, $msgType, $source, $status, $text, $staffId, $stored + ['_meta_error' => $err->forPayload()],
                errorCode: $err->code, phoneNumberId: $phoneNumberId, category: $err->category, columns: $columns);
            if ($status === 'failed') {
                self::emitFailed($row, $err, $source, $staffId);
            }
        }
        WaLog::warning('wa.send_failed', ['conv_id' => $conv->id, 'message_id' => $row?->id, 'source' => $source, 'type' => $msgType,
            'error_code' => $err->code, 'category' => $err->category, 'attempt' => 1]);
        MetaErrorReactor::react($err, ['conv_id' => $conv->id, 'message_id' => $row?->id, 'source' => $source]);
        SendHealthMonitor::recordFailure($err);

        return $v2
            ? self::failWith($err, $err->code, "{$err->staffMessage} رقم المرجع: {$ref}")
            : self::fail('HTTP_ERROR', "تعذّر الاتصال بميتا (مهلة أو شبكة). رقم المرجع: {$ref}");
    }

    /**
     * رسالة آلية حجبتها السياسة: لا نداء لميتا، والمحفوظ نص بديل وبصمة sha256 للأصل وحدها (لا يبقى المحتوى المحجوب)،
     * والمحادثة «تحتاج تدخّلاً» لموظف. المتصل لا يرسل بديلاً (BotEngine لا يستدعي failSafe لفشل الإرسال).
     */
    private static function blockByPolicy(WaConversation $conv, Outbound\Decision $decision, array $payload, string $displayBody,
        string $msgType, string $source, ?int $staffId, ?string $phoneNumberId, array $columns = []): array
    {
        $label = Outbound\OutboundPolicy::describe($decision->reasons);
        $hash = hash('sha256', implode("\n", Outbound\OutboundPolicy::texts($payload, $displayBody)));
        self::store($conv, null, "[حُجبت رسالة آلية: {$label}]", $msgType, $source, 'blocked',
            "حُجبت قبل الإرسال بسياسة المحتوى الصادر ({$label})، ولم تصل العميل.", $staffId,
            ['_policy' => ['mode' => 'enforce', 'reasons' => $decision->reasons, 'sha256' => $hash]],
            errorCode: $decision->errorCode(), phoneNumberId: $phoneNumberId, category: MetaErrorCatalog::byCode($decision->errorCode())['category'] ?? null,
            columns: $columns);
        ReplyState::markUnserved($conv, 'policy_block');

        return self::failLocal('POLICY_BLOCKED', 'حُجبت الرسالة قبل الإرسال بسياسة المحتوى الصادر.');
    }

    // ==================== الأحداث الداخلية (E21) ====================

    /**
     * بعد كتابة wamid على الصف: إيصال سبقه في الكاش يُطبَّق الآن (OrphanStatus، مع delivery_status_v2)، ثم WaMessageSent.
     * لا يرمي: الرسالة خرجت فعلاً، وما بعدها لا يغيّر نتيجة الإرسال.
     */
    private static function afterWamid(?WaMessage $row, string $wamid, string $source, ?int $staffId): void
    {
        if ($row === null || $wamid === '') {
            return;
        }
        try {
            OrphanStatus::drain($wamid);
        } catch (\Throwable $e) {
            WaLog::error('wa.status.orphan_failed', ['message_id' => $row->id, 'type' => class_basename($e)]);
        }
        EventBus::emit(new WaMessageSent($row->id, (int) $row->conversation_id, $source, $staffId));
    }

    /** صف صار failed داخل dispatch (رفض ميتا المتزامن أو تعذّر الاتصال). قفل محلي قبل ميتا بلا صف، فلا حدث */
    private static function emitFailed(?WaMessage $row, MetaError $err, string $source, ?int $staffId): void
    {
        if ($row !== null) {
            EventBus::emit(new WaMessageFailed($row->id, (int) $row->conversation_id, $source, $err->metaCode, $err->category, $err->scope, 'send', $staffId));
        }
    }

    private static ?bool $hasMetaColumns = null;

    private static ?bool $hasE11Columns = null;

    private static ?bool $hasCategory = null;

    private static function store(
        WaConversation $conv, ?string $wamid, string $body, string $msgType,
        string $source, string $status, ?string $error, ?int $staffId, array $payload,
        ?int $metaCode = null, ?string $fbtraceId = null, ?string $errorCode = null, ?string $phoneNumberId = null, ?string $category = null,
        array $columns = []
    ): ?WaMessage {
        // بين النشر والهجرة 2026_10_14_000006 (و000012) لا أعمدة بعد، والرسالة تُحفظ رغم ذلك
        self::$hasMetaColumns ??= \Illuminate\Support\Facades\Schema::hasColumns('wa_messages', ['meta_code', 'fbtrace_id']);
        self::$hasE11Columns ??= \Illuminate\Support\Facades\Schema::hasColumns('wa_messages', ['phone_number_id', 'error_code']);
        $meta = self::$hasMetaColumns ? ['meta_code' => $metaCode, 'fbtrace_id' => $fbtraceId] : [];
        if (self::$hasE11Columns) {
            // الرقم الذي أُرسل منه: إن بُدّل رقم القناة لاحقاً بقي معروفاً لكل رسالة
            $meta += ['phone_number_id' => $phoneNumberId, 'error_code' => $errorCode !== null ? mb_substr($errorCode, 0, 32) : null];
        }
        // بين النشر والهجرة 2026_10_14_000018: لا عمود فئة بعد
        self::$hasCategory ??= \Illuminate\Support\Facades\Schema::hasColumn('wa_messages', 'error_category');
        if (self::$hasCategory) {
            $meta['error_category'] = $category;
        }

        // أعمدة المغلف (E32): الاقتباس والنسخة الخاصة للملف
        return WaMessage::create($meta + $columns + [
            'conversation_id' => $conv->id,
            'wamid' => $wamid,
            'direction' => 'out',
            'source' => $source,
            'msg_type' => $msgType,
            'body' => $body,
            'payload' => $payload,
            'status' => $status,
            'error_message' => $error,
            'staff_id' => $staffId,
        ]);
    }

    /** قفل محلي قبل ميتا (بلا صف): النص كما هو، ومع meta_error_v2 فئته وإجراؤه من الجدول */
    private static function voiceRefused(WaConversation $conv, string $source): array
    {
        WaLog::warning('wa.voice.refused', ['conv_id' => $conv->id, 'source' => $source]);

        return self::failLocal(self::ERR_UNSUPPORTED, 'الرسائل الصوتية من الموظف وحده.');
    }

    private static function failLocal(string $code, string $msg): array
    {
        return self::failWith(MetaError::local($code), $code, $msg);
    }

    /** meta_code: رقم ميتا إن ردّت بخطأ (لإعادة مؤجلة على الخنق وحده) */
    private static function fail(string $code, string $msg, ?int $metaCode = null): array
    {
        return ['ok' => false, 'error' => $msg, 'code' => $code, 'message_id' => null, 'meta_code' => $metaCode];
    }
}
