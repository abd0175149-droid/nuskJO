<?php

namespace App\Services\WhatsApp\Llm;

use App\Services\WhatsApp\Llm\Exceptions\LlmAuthFailed;
use App\Services\WhatsApp\Llm\Exceptions\LlmBadRequest;
use App\Services\WhatsApp\Llm\Exceptions\LlmQuotaExhausted;
use App\Services\WhatsApp\Llm\Exceptions\LlmRateLimited;
use App\Services\WhatsApp\Llm\Exceptions\LlmUnavailable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;

/**
 * تصنيف أخطاء المزوّد في مكان واحد (E12): ما يُعاد داخلياً، وما يعدّه القاطع، وما يفتحه فوراً.
 * الافتراض محافظ: 5xx المجهول «غير متاح»، و4xx المجهول «طلب مرفوض» لا يُحتسب.
 */
final class LlmErrorClassifier
{
    public static function fromResponse(string $provider, Response $res): LlmException
    {
        return $provider === 'anthropic' ? self::anthropic($res) : self::gemini($res);
    }

    /** خطأ اتصال (مهلة، DNS، TLS) أو استثناء HTTP؛ غيرهما (خلل في الكود) ليس خطأ مزوّد: null */
    public static function fromThrowable(string $provider, \Throwable $e): ?LlmException
    {
        return match (true) {
            $e instanceof LlmException => $e,
            $e instanceof ConnectionException => new LlmUnavailable($provider, self::label($provider).': connection failed (timeout or network)', null, null, $e),
            $e instanceof RequestException => self::fromResponse($provider, $e->response),
            default => null,
        };
    }

    private static function gemini(Response $res): LlmException
    {
        $status = $res->status();
        $error = (array) data_get($res->json(), 'error', []);
        $details = (array) ($error['details'] ?? []);
        $message = 'Gemini HTTP '.$status.': '.mb_substr((string) ($error['message'] ?? ''), 0, 150);
        $reasons = array_filter(array_map(fn ($d) => data_get($d, 'reason'), $details));
        $quotaIds = [];
        foreach ($details as $d) {
            foreach ((array) data_get($d, 'violations', []) as $v) {
                $quotaIds[] = (string) data_get($v, 'quotaId', '');
            }
        }
        $retry = self::retryDelay($details) ?? self::retryHeader($res);

        return match (true) {
            $status === 400 && in_array('API_KEY_INVALID', $reasons, true) => new LlmAuthFailed('google', $message, $status),
            $status === 400 && ($error['status'] ?? null) === 'FAILED_PRECONDITION' => new LlmQuotaExhausted('google', $message, $status),
            in_array($status, [401, 403], true) => new LlmAuthFailed('google', $message, $status),
            $status === 402 => new LlmQuotaExhausted('google', $message, $status, $retry),
            // اسم نموذج تغيّر أو أُوقف: لا يصلحه الانتظار
            $status === 404 => new LlmAuthFailed('google', $message.' (model_not_found)', $status),
            $status === 429 && (bool) array_filter($quotaIds, fn ($id) => str_contains($id, 'PerDay')) => new LlmQuotaExhausted('google', $message, $status, $retry),
            $status === 429 => new LlmRateLimited('google', $message, $status, $retry),
            $status >= 500 => new LlmUnavailable('google', $message, $status),
            default => new LlmBadRequest('google', $message, $status),
        };
    }

    private static function anthropic(Response $res): LlmException
    {
        $status = $res->status();
        $type = (string) data_get($res->json(), 'error.type', '');
        $text = (string) data_get($res->json(), 'error.message', '');
        $message = 'Claude HTTP '.$status.': '.mb_substr($text, 0, 150);
        $retry = self::retryHeader($res);

        return match (true) {
            in_array($status, [401, 403, 404], true) => new LlmAuthFailed('anthropic', $message, $status),
            $status === 402 || $type === 'billing_error' => new LlmQuotaExhausted('anthropic', $message, $status, $retry),
            $status === 400 && str_contains(strtolower($text), 'spend limit') => new LlmQuotaExhausted('anthropic', $message, $status),
            // 429 بلا retry-after سقف إنفاق حسب التوثيق
            $status === 429 && $retry !== null => new LlmRateLimited('anthropic', $message, $status, $retry),
            $status === 429 => new LlmQuotaExhausted('anthropic', $message, $status),
            $status >= 500 => new LlmUnavailable('anthropic', $message, $status),
            default => new LlmBadRequest('anthropic', $message, $status),
        };
    }

    /** RetryInfo.retryDelay بصيغة «37s» أو «1.5s» */
    private static function retryDelay(array $details): ?int
    {
        foreach ($details as $d) {
            $delay = data_get($d, 'retryDelay');
            if (is_string($delay) && preg_match('/^(\d+(?:\.\d+)?)s$/', $delay, $m)) {
                return (int) ceil((float) $m[1]);
            }
        }

        return null;
    }

    private static function retryHeader(Response $res): ?int
    {
        $value = $res->header('Retry-After');

        return is_numeric($value) ? max(0, (int) ceil((float) $value)) : null;
    }

    private static function label(string $provider): string
    {
        return $provider === 'anthropic' ? 'Claude' : 'Gemini';
    }
}
