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
 * وappsecret_proof خلف WA_APPSECRET_PROOF، وأخطاء ميتا تتحول إلى رموز صريحة (META_CODES).
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

    private const RATE_TEXT = 'تجاوزنا حد الإرسال عند ميتا — أعد المحاولة بعد قليل.';
    private const ACCOUNT_TEXT = 'حساب واتساب مقيّد من ميتا — راجع حالة الحساب في لوحة ميتا.';

    /** رمز خطأ ميتا => [رمزنا، النص المحفوظ مع الرسالة الفاشلة] */
    private const META_CODES = [
        190 => [self::ERR_TOKEN, 'رمز ميتا منتهٍ أو مُلغى — يحدّثه المدير من إعدادات الواتساب.'],
        131047 => [self::ERR_WINDOW, 'انتهت نافذة الـ24 ساعة عند ميتا — لا يمكن الإرسال حتى يراسلنا العميل.'],
        131051 => [self::ERR_UNSUPPORTED, 'نوع الرسالة غير مدعوم في واتساب.'],
        130429 => [self::ERR_RATE, self::RATE_TEXT],
        131056 => [self::ERR_RATE, self::RATE_TEXT],
        4 => [self::ERR_RATE, self::RATE_TEXT],
        80007 => [self::ERR_RATE, self::RATE_TEXT],
        131031 => [self::ERR_ACCOUNT, self::ACCOUNT_TEXT],
        368 => [self::ERR_ACCOUNT, self::ACCOUNT_TEXT],
    ];

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
     * خطأ ميتا => [رمزنا، النص المحفوظ]. السجل يحمل الرمز وfbtrace_id وحالة HTTP فقط.
     *
     * @return array{0: string, 1: string}
     */
    private static function mapGraphError(Response $res): array
    {
        $e = self::graphError($res);
        WaLog::error('graph.error', ['meta_code' => $e['code'], 'http' => $e['status'], 'fbtrace_id' => $e['trace']]);

        if ($e['code'] === null) {
            return ['META_ERROR', $e['message']];
        }
        if ($e['code'] === 100 && str_contains(strtolower($e['message']), 'appsecret_proof')) {
            self::alertOnce('proof', '⛔ ميتا رفضت appsecret_proof',
                'كل نداءات Graph تُرفض. عطّل WA_APPSECRET_PROOF أو صحّح سر التطبيق، ثم config:cache.', '/whatsapp/settings');

            return [self::ERR_PROOF, '[100] إثبات سر التطبيق مرفوض — راجع WA_APPSECRET_PROOF وسر التطبيق.'];
        }
        if (isset(self::META_CODES[$e['code']])) {
            [$ours, $text] = self::META_CODES[$e['code']];
            if ($ours === self::ERR_ACCOUNT) {
                // لا إيقاف تلقائي هنا: الإيقاف من ويبهوك account_update
                self::alertOnce('account', '⚠️ ميتا تقيّد حساب الواتساب', "رفضت ميتا الإرسال بالرمز {$e['code']}. راجع حالة الحساب في لوحة ميتا.", '/whatsapp/settings');
            }

            return [$ours, "[{$e['code']}] {$text}"];
        }

        return ['META_ERROR', SecretRedactor::clean("[{$e['code']}] {$e['message']}")];
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
            WaLog::error('media.lookup.failed', ['http' => $meta->status(), 'meta_code' => data_get($meta->json(), 'error.code')]);

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
            'to' => $conv->wa_phone,
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
            'to' => $conv->wa_phone,
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
            'to' => $conv->wa_phone,
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
            'to' => $conv->wa_phone,
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

    // ==================== المحرّك ====================

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
        if ($s->wa_suspended || config('services.whatsapp.suspended')) {
            return self::fail(self::ERR_SUSPENDED, 'الإرسال موقوف حالياً (قفل طارئ).');
        }

        // ── القفل 3: نافذة 24 ساعة
        if (!$conv->isWindowOpen()) {
            return self::fail(self::ERR_WINDOW, 'انتهت نافذة الـ24 ساعة — لا يمكن الإرسال حتى يراسلك العميل من جديد.');
        }

        try {
            $url = self::graphPath((string) $s->phoneNumberId(), 'messages');
        } catch (\InvalidArgumentException $e) {
            WaLog::error('graph.config.invalid', ['reason' => $e->getMessage()]);

            return self::fail(self::ERR_CONFIG, 'إصدار Graph أو معرّف الرقم غير صالح في الإعدادات.');
        }

        try {
            $res = self::graph($s, 25)->post($url, $payload);
        } catch (\Throwable $e) {
            // نص الاستثناء يحمل الرابط: للسجل منقّحاً، لا للواجهة
            WaLog::error('send.connection_failed', ['conv_id' => $conv->id, 'exception' => $e]);

            return self::fail('HTTP_ERROR', 'تعذّر الاتصال بميتا (مهلة أو شبكة).');
        }

        $json = $res->json() ?? [];

        if (!$res->successful()) {
            [$code, $err] = self::mapGraphError($res);
            self::store($conv, null, $displayBody, $msgType, $source, 'failed', $err, $staffId, $payload);

            return self::fail($code, $err);
        }

        $wamid = data_get($json, 'messages.0.id');
        self::store($conv, $wamid, $displayBody, $msgType, $source, 'sent', null, $staffId, $payload);

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
        }
        $conv->update($update);

        return ['ok' => true, 'error' => null, 'code' => null, 'message_id' => $wamid];
    }

    private static function store(
        WaConversation $conv, ?string $wamid, string $body, string $msgType,
        string $source, string $status, ?string $error, ?int $staffId, array $payload
    ): void {
        WaMessage::create([
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

    private static function fail(string $code, string $msg): array
    {
        return ['ok' => false, 'error' => $msg, 'code' => $code, 'message_id' => null];
    }
}
