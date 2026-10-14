<?php

namespace App\Services\WhatsApp;

use App\Models\WaBotSetting;
use App\Models\WaConversation;
use App\Models\WaMessage;
use App\Support\SecretRedactor;
use App\Support\UrlPath;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * بوّابة الإرسال الوحيدة إلى ميتا — كل صادر (بوت/موظف/نظام) يمرّ من هنا.
 *
 * السائق الوحيد المسموح هو WhatsApp Cloud API الرسمية عبر graph.facebook.com. يُمنع إضافة
 * whatsapp-web.js أو Baileys أو أي عميل غير رسمي، لأن ذلك يخالف شروط واتساب ويعرّض الرقم الوحيد للحظر.
 *
 * مرفوض عمداً، ويعيد UNSUPPORTED لا نجاحاً وهمياً: القوالب (قرار المالك)، والمجموعات، والحالات (stories).
 *
 * الأقفال بالترتيب:
 *   0) الموظف يرى المحادثة (لرسائل الموظف وحدها)
 *   1) القناة مهيّأة (رمز + معرّف رقم)
 *   2) قفل الإرسال الطارئ (wa_suspended)
 *   3) نافذة 24 ساعة من آخر رسالة واردة — لا استثناء لأحد
 *
 * عميل Graph رفيع (graph/graphPath): الإصدار من الإعداد وحده، وكل مقطع مسار مرمَّز، ولا تحويلات،
 * وappsecret_proof خلف WA_APPSECRET_PROOF، وأخطاء ميتا تتحول إلى رموز صريحة (MetaErrorCatalog).
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

    /** نص الزر المعروض في الصندوق (الجسم المرسل للعميل غيره) */
    public const CONTACT_REQUEST_LABEL = '📱 طلب مشاركة رقم الهاتف';

    /** أنواع الصوت التي نفرّغها؛ غيرها لا يُنزَّل */
    private const AUDIO_MIME = ['audio/ogg', 'audio/opus', 'audio/mpeg', 'audio/mp4', 'audio/aac', 'audio/amr'];

    // ==================== عميل Graph ====================

    /** الإصدار من الإعداد وحده (لا قيمة احتياطية هنا) */
    private static function graphVersion(): ?string
    {
        $version = config('services.whatsapp.graph_version');

        return GraphVersions::isValid($version) ? $version : null;
    }

    /** @throws \InvalidArgumentException إصدار غير صالح أو مقطع يغيّر المسار */
    private static function graphPath(string ...$segments): string
    {
        $version = self::graphVersion() ?? throw new \InvalidArgumentException('GRAPH_VERSION_INVALID');

        return 'https://graph.facebook.com/' . $version . '/' . implode('/', array_map(UrlPath::segment(...), $segments));
    }

    private static function graph(WaBotSetting $s, int $timeout): PendingRequest
    {
        $request = Http::withToken((string) $s->token())
            ->withoutRedirecting()
            ->connectTimeout(10)
            ->timeout($timeout)
            ->acceptJson();

        $secret = $s->appSecret();
        if (config('services.whatsapp.appsecret_proof') && $secret) {
            $request = $request->withQueryParameters(['appsecret_proof' => hash_hmac('sha256', (string) $s->token(), $secret)]);
        }

        return $request;
    }

    /** @return array{code: ?int, message: string, details: ?string, trace: ?string, status: int} */
    private static function graphError(Response $res): array
    {
        $json = $res->json() ?? [];

        return [
            'code' => is_numeric(data_get($json, 'error.code')) ? (int) data_get($json, 'error.code') : null,
            'message' => (string) data_get($json, 'error.message', 'HTTP ' . $res->status()),
            'details' => data_get($json, 'error.error_data.details'),
            'trace' => data_get($json, 'error.fbtrace_id'),
            'status' => $res->status(),
        ];
    }

    /**
     * خطأ ميتا => [رمزنا، النص المحفوظ، رمز ميتا، fbtrace_id]، ثم فعل الجدول (تنبيه أو إيقاف).
     * السجل يحمل الرمز وfbtrace_id وحالة HTTP فقط.
     *
     * @return array{0: string, 1: string, 2: ?int, 3: ?string}
     */
    private static function mapGraphError(Response $res): array
    {
        $e = self::graphError($res);
        WaLog::error('graph.error', ['meta_code' => $e['code'], 'http' => $e['status'], 'fbtrace_id' => $e['trace']]);

        if ($e['code'] === 100 && str_contains(strtolower($e['message']), 'appsecret_proof')) {
            self::alertOnce('proof', '⛔ ميتا رفضت appsecret_proof',
                'كل نداءات Graph تُرفض. عطّل WA_APPSECRET_PROOF أو صحّح سر التطبيق، ثم config:cache.', '/whatsapp/settings');

            return [self::ERR_PROOF, '[100] إثبات سر التطبيق مرفوض — راجع WA_APPSECRET_PROOF وسر التطبيق.', 100, $e['trace']];
        }

        MetaErrorCatalog::react($e['code']);

        return [
            MetaErrorCatalog::codeFor($e['code']),
            SecretRedactor::clean(MetaErrorCatalog::message($e['code'], $e['message'])),
            $e['code'],
            $e['trace'],
        ];
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
    public static function fetchMedia(string $mediaId, string $kind = 'audio', int $maxBytes = 15_000_000): array
    {
        $s = WaBotSetting::current();
        $token = $s->token();
        if (!$token) {
            return self::mediaFail(self::ERR_NOT_CONFIGURED, 'قناة الواتساب غير مهيّأة.');
        }
        if ($kind !== 'audio') {
            return self::mediaFail(self::ERR_UNSUPPORTED, 'تنزيل هذا النوع من الوسائط غير مدعوم.');
        }

        // (أ) البيانات الوصفية
        try {
            $lookupUrl = self::graphPath($mediaId);
        } catch (\InvalidArgumentException) {
            return self::mediaFail(self::ERR_CONFIG, 'معرّف الوسائط أو إصدار Graph غير صالح.');
        }
        try {
            $meta = self::graph($s, 20)->get($lookupUrl, array_filter(['phone_number_id' => $s->phoneNumberId()]));
        } catch (\Throwable $e) {
            WaLog::error('media.lookup.connection_failed', ['exception' => $e]);

            return self::mediaFail('HTTP_ERROR', 'تعذّر الاتصال بميتا (مهلة أو شبكة).');
        }
        $url = (string) $meta->json('url', '');
        if (!$meta->successful() || $url === '') {
            $metaCode = is_numeric(data_get($meta->json(), 'error.code')) ? (int) data_get($meta->json(), 'error.code') : null;
            WaLog::error('media.lookup.failed', ['http' => $meta->status(), 'meta_code' => $metaCode]);
            // رمز مرفوض أو صلاحية ناقصة يظهر هنا أيضاً (تنزيل الصوت)، فينبّه كما ينبّه الإرسال
            if (in_array($meta->status(), [401, 403], true) && MetaErrorCatalog::isAccountError($metaCode)) {
                MetaErrorCatalog::react($metaCode);
            }

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
            $file = Http::withToken($token)->connectTimeout(10)->timeout(40)
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
            $res = self::graph($s, 20)->get(self::graphPath($mediaId), array_filter(['phone_number_id' => $s->phoneNumberId()]));
        } catch (\InvalidArgumentException) {
            return ['ok' => false, 'http' => null, 'meta_code' => null, 'code' => self::ERR_CONFIG];
        } catch (\Throwable $e) {
            WaLog::warning('media.lookup.connection_failed', ['type' => $e::class]);

            return ['ok' => false, 'http' => null, 'meta_code' => null, 'code' => 'HTTP_ERROR'];
        }
        $metaCode = is_numeric(data_get($res->json(), 'error.code')) ? (int) data_get($res->json(), 'error.code') : null;
        $url = (string) $res->json('url', '');
        if (!$res->successful() || $url === '') {
            WaLog::warning('media.lookup.failed', ['http' => $res->status(), 'meta_code' => $metaCode]);
            if (in_array($res->status(), [401, 403], true) && MetaErrorCatalog::isAccountError($metaCode)) {
                MetaErrorCatalog::react($metaCode);
            }

            return ['ok' => false, 'http' => $res->status(), 'meta_code' => $metaCode, 'code' => 'META_ERROR',
                'not_found' => $res->status() === 404 || $metaCode === 100 || str_contains(strtolower((string) data_get($res->json(), 'error.message', '')), 'not found')];
        }

        return ['ok' => true, 'http' => $res->status(), 'meta_code' => null, 'url' => $url,
            'mime' => (string) $res->json('mime_type', ''), 'file_size' => (int) $res->json('file_size', 0), 'sha256' => (string) $res->json('sha256', '')];
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
            $res = Http::withToken($s->appId().'|'.$s->appSecret())
                ->withoutRedirecting()->connectTimeout(5)->timeout(15)->acceptJson()
                ->get(self::graphPath('debug_token'), ['input_token' => (string) $s->token()]);
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
            $url = self::graphPath($mediaId);
            $res = self::graph($s, 10)->withQueryParameters(array_filter(['phone_number_id' => $s->phoneNumberId()]))->delete($url);
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
            $res = self::graph($s, 15)->get(self::graphPath((string) $s->phoneNumberId()), ['fields' => 'health_status,quality_rating,verified_name']);
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
            $res = self::graph($s, 15)->get(self::graphPath($phoneNumberId), ['fields' => 'id']);
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
            $url = self::graphPath((string) $s->phoneNumberId());
        } catch (\InvalidArgumentException) {
            return ['ok' => false, 'code' => self::ERR_CONFIG, 'status' => null];
        }
        try {
            $res = self::graph($s, 20)->get($url, ['fields' => 'id,display_phone_number,quality_rating,verified_name']);
        } catch (\Throwable $e) {
            WaLog::error('graph.smoke.connection_failed', ['exception' => $e]);

            return ['ok' => false, 'code' => 'HTTP_ERROR', 'status' => null];
        }
        if (!$res->successful()) {
            return ['ok' => false, 'code' => self::mapGraphError($res)[0], 'status' => $res->status()];
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
     * إرسال رسالة نصية.
     * @return array{ok:bool, error:?string, code:?string, message_id:?string}
     */
    public static function sendText(WaConversation $conv, string $text, string $source = 'bot', ?int $staffId = null): array
    {
        return self::dispatch($conv, [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'type' => 'text',
            'text' => ['preview_url' => false, 'body' => mb_substr($text, 0, 4096)],
        ], $text, 'text', $source, $staffId);
    }

    /**
     * إرسال صورة عبر رابط عام (بطاقة عرض مثلاً) مع تعليق اختياري.
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
        ?int $staffId = null
    ): array {
        if (!str_starts_with($url, 'https://')) {
            return self::fail('BAD_MEDIA_URL', 'رابط الصورة يجب أن يكون HTTPS ليقبله واتساب.');
        }

        $image = ['link' => $url];
        if ($caption !== null && trim($caption) !== '') {
            $image['caption'] = mb_substr($caption, 0, 1024);
        }

        return self::dispatch($conv, [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'type' => 'image',
            'image' => $image,
        ], $caption ?: '📷 صورة', 'image', $source, $staffId);
    }

    /**
     * إرسال أزرار (حتى 3) — معرّف كل زر يحمل الحالة مثل offer:12
     * @param array<array{id:string,title:string}> $buttons
     */
    public static function sendButtons(WaConversation $conv, string $body, array $buttons, string $source = 'bot'): array
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
        ], $body, 'interactive', $source);
    }

    /**
     * إرسال قائمة (حتى 10 صفوف)
     * @param array<array{id:string,title:string,description?:string}> $items
     */
    public static function sendList(WaConversation $conv, string $body, string $buttonLabel, array $items, string $source = 'bot'): array
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
        ], $body, 'interactive', $source);
    }

    /**
     * زر «مشاركة رقم الهاتف» (request_contact_info): يرد العميل ببطاقة رقمه (contacts بأصل contact_request)
     * فتُملأ محادثته بلا رقم (ContactIdentity::acceptSharedPhone). داخل نافذة 24 ساعة كأي رسالة.
     */
    public static function sendContactRequest(WaConversation $conv, string $body, string $source = 'staff', ?int $staffId = null): array
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
        ], self::CONTACT_REQUEST_LABEL, 'interactive', $source, $staffId);
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

    private static function dispatch(
        WaConversation $conv,
        array $payload,
        string $displayBody,
        string $msgType,
        string $source,
        ?int $staffId = null
    ): array {
        // ── القفل 0: الموظف يرى المحادثة (سياج E04). يغطي الرد من الصندوق وإرسال السعر معاً.
        //    رسائل البوت والنظام لا تمر به: الويبهوك والطابور والمساعد الإداري بلا مستخدم.
        if ($source === 'staff') {
            $staff = $staffId ? \App\Models\User::find($staffId) : null;
            if (!$staff || !TopicRouter::canSee($conv, $staff)) {
                return self::fail(self::ERR_OUT_OF_SCOPE, 'هذه المحادثة خارج نطاقك — لا يمكنك مراسلة هذا العميل.');
            }
        }

        $s = WaBotSetting::current();

        // ── القفل 1: القناة مهيّأة؟
        if (!$s->channelReady()) {
            return self::fail(self::ERR_NOT_CONFIGURED, 'قناة الواتساب غير مهيّأة (رمز الوصول أو معرّف الرقم ناقص).');
        }

        // ── القفل 2: إيقاف إرسال طارئ
        if ($s->sendingSuspended()) {
            return self::fail(self::ERR_SUSPENDED, 'الإرسال موقوف حالياً (قفل طارئ).');
        }

        // ── القفل 3: نافذة 24 ساعة
        if (!$conv->isWindowOpen()) {
            return self::fail(self::ERR_WINDOW, 'انتهت نافذة الـ24 ساعة — لا يمكن الإرسال حتى يراسلك العميل من جديد.');
        }

        // ── القفل 4: إلى من؟ الرقم إن عُرف، وإلا معرّف ميتا (BSUID) إن سمح وضع الهوية لهذا المصدر. لا الاثنان معاً
        $target = self::target($conv, $source);
        if ($target === null) {
            return self::fail(self::ERR_NO_RECIPIENT, 'العميل يراسل باسم مستخدم ورقمه غير ظاهر، والرد عليه غير مفعّل بعد — أبلغ المدير.');
        }
        $payload = ['messaging_product' => 'whatsapp'] + $target + $payload;

        try {
            $url = self::graphPath((string) $s->phoneNumberId(), 'messages');
        } catch (\InvalidArgumentException $e) {
            WaLog::error('graph.config.invalid', ['reason' => $e->getMessage()]);

            return self::fail(self::ERR_CONFIG, 'إصدار Graph أو معرّف الرقم غير صالح في الإعدادات.');
        }

        try {
            $res = self::graph($s, 25)->post($url, $payload);
        } catch (\Throwable $e) {
            // نص الاستثناء يحمل الرابط: للسجل منقّحاً، ورقم مرجعي للواجهة
            WaLog::error('send.connection_failed', ['conv_id' => $conv->id, 'exception' => $e]);
            $ref = \App\Support\ErrorRef::log($e, 'wa.send.connection_failed', ['conv_id' => $conv->id]);
            // رسالة الموظف تترك أثراً «غير مؤكد»: المهلة قد تقع بعد أن سلّمتها ميتا، فلا يعيد كتابتها دون أن يرى ذلك
            if ($source === 'staff') {
                self::store($conv, null, $displayBody, $msgType, $source, 'unconfirmed', "لم يصل رد ميتا (مهلة أو شبكة). رقم المرجع: {$ref}", $staffId, $payload, errorCode: 'HTTP_ERROR', phoneNumberId: $s->phoneNumberId());
            }

            return self::fail('HTTP_ERROR', "تعذّر الاتصال بميتا (مهلة أو شبكة). رقم المرجع: {$ref}");
        }

        $json = $res->json() ?? [];

        if (!$res->successful()) {
            [$code, $err, $metaCode, $trace] = self::mapGraphError($res);
            self::store($conv, null, $displayBody, $msgType, $source, 'failed', $err, $staffId, $payload, $metaCode, $trace, $code, $s->phoneNumberId());
            WaLog::warning('wa.send.failed', ['conv_id' => $conv->id, 'source' => $source, 'type' => $msgType, 'code' => $code, 'meta_code' => $metaCode, 'fbtrace_id' => $trace]);

            return self::fail($code, $err, $metaCode);
        }

        // 200 بلا معرّف رسالة: قبلتها ميتا ولا دليل على الإرسال، فلا تُعرض «أُرسلت» (E12.8)
        $wamid = data_get($json, 'messages.0.id');
        $status = is_string($wamid) && $wamid !== '' ? 'sent' : 'unconfirmed';
        self::store($conv, $status === 'sent' ? $wamid : null, $displayBody, $msgType, $source, $status, null, $staffId, $payload, phoneNumberId: $s->phoneNumberId());
        if ($status === 'unconfirmed') {
            WaLog::warning('wa.send.unconfirmed', ['conv_id' => $conv->id, 'source' => $source, 'type' => $msgType]);
        } else {
            WaLog::info('wa.send.ok', ['conv_id' => $conv->id, 'wamid' => $wamid, 'source' => $source, 'type' => $msgType]);
        }

        // ردّ الموظف يُوقف البوت مؤقتاً ويصفّر المؤشرات
        $update = [
            'last_outbound_at' => now(),
            'last_message_at' => now(),
            'last_message_preview' => mb_substr(trim($displayBody), 0, 160),
        ];
        if ($source === 'staff') {
            $update['bot_paused_until'] = now()->addMinutes(max(1, (int) $s->pause_minutes));
            $update['unread_count'] = 0;
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

        return ['ok' => true, 'error' => null, 'code' => null, 'message_id' => $status === 'sent' ? $wamid : null] + ($status === 'unconfirmed' ? ['unconfirmed' => true] : []);
    }

    private static ?bool $hasMetaColumns = null;

    private static ?bool $hasE11Columns = null;

    private static function store(
        WaConversation $conv, ?string $wamid, string $body, string $msgType,
        string $source, string $status, ?string $error, ?int $staffId, array $payload,
        ?int $metaCode = null, ?string $fbtraceId = null, ?string $errorCode = null, ?string $phoneNumberId = null
    ): void {
        // بين النشر والهجرة 2026_10_14_000006 (و000012) لا أعمدة بعد، والرسالة تُحفظ رغم ذلك
        self::$hasMetaColumns ??= \Illuminate\Support\Facades\Schema::hasColumns('wa_messages', ['meta_code', 'fbtrace_id']);
        self::$hasE11Columns ??= \Illuminate\Support\Facades\Schema::hasColumns('wa_messages', ['phone_number_id', 'error_code']);
        $meta = self::$hasMetaColumns ? ['meta_code' => $metaCode, 'fbtrace_id' => $fbtraceId] : [];
        if (self::$hasE11Columns) {
            // الرقم الذي أُرسل منه: إن بُدّل رقم القناة لاحقاً بقي معروفاً لكل رسالة
            $meta += ['phone_number_id' => $phoneNumberId, 'error_code' => $errorCode];
        }

        WaMessage::create($meta + [
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

    /** meta_code: رقم ميتا إن ردّت بخطأ (لإعادة مؤجلة على الخنق وحده) */
    private static function fail(string $code, string $msg, ?int $metaCode = null): array
    {
        return ['ok' => false, 'error' => $msg, 'code' => $code, 'message_id' => null, 'meta_code' => $metaCode];
    }
}
