<?php

namespace App\Services\WhatsApp;

use Illuminate\Http\Client\Response;

/**
 * خطأ واحد من ميتا (أو قبلها) بمعنى ثابت في كل النظام (E18): الرمز الداخلي والفئة والنطاق والرسالة العربية
 * والإجراء وسياسة الإعادة. المعنى كله من MetaErrorCatalog؛ هنا القراءة من أشكال ميتا الثلاثة وحدها.
 *
 * details من ميتا مقصوصة ومقنّعة الأرقام، ولا تُسجَّل ولا تُعرض إلا لصاحب whatsapp.settings.
 * metaMessage (نص ميتا الخام الإنجليزي) للمسار القديم في وضع الظل وحده: لا يُخزَّن ولا يصل للنموذج ولا للسجل.
 */
final class MetaError
{
    public function __construct(
        public readonly ?int $metaCode,
        public readonly ?int $httpStatus,
        public readonly ?string $fbtraceId,
        public readonly ?string $details,
        public readonly string $code,
        public readonly string $category,
        public readonly string $scope,
        public readonly string $staffMessage,
        public readonly string $action,
        public readonly string $retry,
        public readonly bool $alert,
        public readonly ?string $type = null,
        public readonly ?string $metaMessage = null,
    ) {}

    public static function fromGraphResponse(Response $res): self
    {
        $json = $res->json();
        $error = is_array($json) ? data_get($json, 'error') : null;
        if (!is_array($error)) {
            // بلا جسم JSON مفهوم: 5xx خلل مؤقت، وغيره رفض بلا رمز
            return $res->status() >= 500
                ? self::network('HTTP_5XX_NO_BODY', $res->status())
                : self::make(null, (string) ('HTTP '.$res->status()), $res->status(), null, null, null);
        }

        return self::make(
            self::int(data_get($error, 'code')),
            (string) data_get($error, 'message', 'HTTP '.$res->status()),
            $res->status(),
            self::str(data_get($error, 'fbtrace_id')),
            self::str(data_get($error, 'error_data.details')),
            self::str(data_get($error, 'type')),
        );
    }

    /** statuses[].errors[0] في ويبهوك الحالة (code وtitle وmessage وerror_data.details) */
    public static function fromStatusError(array $err): self
    {
        return self::make(
            self::int(data_get($err, 'code')),
            (string) (data_get($err, 'message') ?: data_get($err, 'title', '')),
            null,
            null,
            self::str(data_get($err, 'error_data.details')),
            null,
        );
    }

    /** value.errors[] (على مستوى الحمولة) وvalue.messages[].errors[] */
    public static function fromWebhookError(array $err): self
    {
        return self::fromStatusError($err);
    }

    /** أخطاء ما قبل ميتا (القناة والقفل والنافذة والمستلم) وسياسة المحتوى */
    public static function local(string $code): self
    {
        $e = MetaErrorCatalog::byCode($code) ?? MetaErrorCatalog::byCode('META_ERROR');

        return new self(null, null, null, null, $code, $e['category'], $e['scope'], $e['message'], $e['action'], $e['retry'], false);
    }

    /** HTTP_CONNECT_FAILED (لم يُرسل شيء) · HTTP_TIMEOUT (قد تكون وصلت) · HTTP_5XX_NO_BODY */
    public static function network(string $kind, ?int $httpStatus = null): self
    {
        $e = MetaErrorCatalog::byCode($kind);

        return new self(null, $httpStatus, null, null, $kind, $e['category'], $e['scope'], $e['message'], $e['action'], $e['retry'], false);
    }

    /** نوع فشل الاتصال من نص الاستثناء (cURL): مهلة بعد الاتصال أم تعذّر الاتصال نفسه */
    public static function networkKind(\Throwable $e): string
    {
        $text = '';
        for ($x = $e; $x; $x = $x->getPrevious()) {
            $text .= ' '.$x->getMessage();
        }

        // «Connection timed out» ومعرّفات الوصول (6 و7 و35) قبل أي بايت: لم تصل الرسالة
        if (preg_match('/Connection timed out|cURL error (6|7|35)\b|Could not resolve|Failed to connect/i', $text)) {
            return 'HTTP_CONNECT_FAILED';
        }

        return preg_match('/cURL error 28|timed out/i', $text) ? 'HTTP_TIMEOUT' : 'HTTP_CONNECT_FAILED';
    }

    private static function make(?int $metaCode, string $message, ?int $http, ?string $trace, ?string $details, ?string $type): self
    {
        $e = MetaErrorCatalog::classify($metaCode, $message, $http);

        return new self($metaCode, $http, $trace !== null ? mb_substr($trace, 0, 64) : null, MetaErrorCatalog::maskDetails($details),
            $e['code'], $e['category'], $e['scope'], $e['message'], $e['action'], $e['retry'], $e['alert'], $type !== null ? mb_substr($type, 0, 40) : null, $message);
    }

    public function isChannelWide(): bool
    {
        return $this->scope === 'channel';
    }

    public function isRetryable(): bool
    {
        return $this->retry !== 'none';
    }

    /** للنتيجة والواجهة: بلا details ولا نص ميتا الخام */
    public function toArray(): array
    {
        return [
            'meta_code' => $this->metaCode,
            'http' => $this->httpStatus,
            'fbtrace_id' => $this->fbtraceId,
            'code' => $this->code,
            'category' => $this->category,
            'scope' => $this->scope,
            'message' => $this->staffMessage,
            'action' => $this->action,
            'retry' => $this->retry,
            'alert' => $this->alert,
        ];
    }

    /** ما يُحفظ في payload._meta_error للدعم: الرمز والنوع والتفاصيل المقنّعة */
    public function forPayload(): array
    {
        return array_filter(['code' => $this->code, 'meta_code' => $this->metaCode, 'type' => $this->type, 'details' => $this->details], fn ($v) => $v !== null);
    }

    private static function int(mixed $v): ?int
    {
        return is_numeric($v) ? (int) $v : null;
    }

    private static function str(mixed $v): ?string
    {
        return is_scalar($v) && (string) $v !== '' ? (string) $v : null;
    }
}
