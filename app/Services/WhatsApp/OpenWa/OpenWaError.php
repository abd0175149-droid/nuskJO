<?php

namespace App\Services\WhatsApp\OpenWa;

use App\Services\WhatsApp\MetaError;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;

/**
 * تصنيف رد البوابة غير الناجح إلى رمز من MetaErrorCatalog::LOCAL (GW_* وما حولها)، مع سؤالين يحكمان الدفتر:
 *  - notSent: مؤكد أن الرسالة لم تخرج (تعذّر الاتصال، أو رفض قبل نداء المحرك) فالإعادة آمنة والمفتاح يُحرَّر.
 *  - retryAfter: ثوانٍ من Retry-After عند 429.
 * غير ذلك (مهلة بعد الإرسال، 5xx من المحرك، IDEMPOTENCY_OUTCOME_UNKNOWN): «غير مؤكد»، لا إعادة آلية.
 *
 * حد البوابة الخاص (SEND_PACING_LIMITED) لا يحدث: SEND_PACING_ENABLED=false. إن ظهر فإعداد البوابة انحرف، فيُصنَّف GW_RATE ويُسجَّل.
 */
final class OpenWaError
{
    public function __construct(
        public readonly string $code,
        public readonly ?int $http,
        public readonly bool $notSent,
        public readonly ?int $retryAfter = null,
        public readonly ?string $gatewayCode = null,
        public readonly ?string $detail = null,
    ) {}

    public static function local(string $code, ?string $detail = null): self
    {
        return new self($code, null, true, null, null, $detail);
    }

    public static function fromException(ConnectionException $e): self
    {
        $kind = MetaError::networkKind($e);

        return $kind === 'HTTP_CONNECT_FAILED'
            ? new self('GW_UNREACHABLE', null, true)
            : new self('GW_TIMEOUT', null, false);
    }

    public static function fromResponse(Response $res): self
    {
        $status = $res->status();
        $json = is_array($res->json()) ? $res->json() : [];
        $gw = self::str(data_get($json, 'code') ?? data_get($json, 'error.code') ?? data_get($json, 'errorCode'));
        $message = data_get($json, 'message');
        $message = is_array($message) ? implode('; ', array_map('strval', $message)) : self::str($message ?? data_get($json, 'error'));
        $text = strtolower((string) $message.' '.(string) $gw);
        $retryAfter = is_numeric($res->header('Retry-After')) ? max(1, (int) $res->header('Retry-After')) : null;

        if ($gw === 'IDEMPOTENCY_KEY_IN_PROGRESS') {
            return new self('IDEMPOTENCY_IN_PROGRESS', $status, false, null, $gw, $message);
        }
        if ($gw === 'IDEMPOTENCY_OUTCOME_UNKNOWN') {
            return new self('GW_ERROR', $status, false, null, $gw, $message);
        }
        if ($gw === 'IDEMPOTENCY_KEY_REUSED' || $status === 422) {
            return new self('IDEMPOTENCY_MISMATCH', $status, true, null, $gw, $message);
        }

        // الاقتباس من رسالة لم تعد على الهاتف: البوابة ترفض قبل المحرك ولا ترسل بلا اقتباس
        if (str_contains($text, 'quoted') && preg_match('/not found|cannot|could not|unable|unknown|resolve/', $text)) {
            return new self('QUOTE_NOT_FOUND', $status, true, null, $gw, $message);
        }
        if (preg_match('/not (registered|on whatsapp)|no whatsapp account|not a whatsapp user|number does not exist|invalid wid/', $text)) {
            return new self('RECIPIENT_NOT_ON_WA', $status, true, null, $gw, $message);
        }

        return match (true) {
            $status === 401, $status === 403 => new self('GW_AUTH', $status, true, null, $gw, $message),
            $status === 404 && str_contains($text, 'session') => new self('GW_SESSION_MISSING', $status, true, null, $gw, $message),
            $status === 429 => new self('GW_RATE', $status, true, $retryAfter ?? 30, $gw, $message),
            // الجلسة لم تجهز أو المحرك غير مستعد، أو تعذّر البحث عن المستلم: لم يُستدعَ الإرسال
            $status === 409, $status === 503 => new self('CHANNEL_NOT_READY', $status, true, $retryAfter, $gw, $message),
            $status === 413 => new self('GW_REJECTED', $status, true, null, $gw, $message),
            // 400 قبل المحرك (تحقق DTO): لم يُرسل
            $status === 400, $status === 404 => new self('GW_REJECTED', $status, true, null, $gw, $message),
            $status === 501 => new self('GW_REJECTED', $status, true, null, $gw, $message),
            // 5xx من المحرك: ربما خرجت الرسالة
            default => new self('GW_ERROR', $status, false, null, $gw, $message),
        };
    }

    public function toMetaError(): MetaError
    {
        return MetaError::gateway($this->code, $this->http, $this->detail);
    }

    private static function str(mixed $v): ?string
    {
        return is_scalar($v) && (string) $v !== '' ? mb_substr((string) $v, 0, 300) : null;
    }
}
