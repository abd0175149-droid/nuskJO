<?php

namespace App\Services\WhatsApp\Llm;

use App\Support\SecretRedactor;

/**
 * خطأ مزوّد الذكاء مصنَّفاً (E12): النوع يقرر الإعادة الداخلية وعدّ القاطع. الرسالة منقّاة وقصيرة (200 حرف)
 * بلا مفتاح ولا رابط ولا نص محادثة: المزوّد ورمز HTTP وجملة الخطأ فقط. التصنيف كله في LlmErrorClassifier.
 */
abstract class LlmException extends \RuntimeException
{
    public const KIND = 'unknown';

    public function __construct(
        public readonly string $provider,
        string $message,
        public readonly ?int $httpStatus = null,
        public readonly ?int $retryAfter = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(self::clean($message), 0, $previous);
    }

    public function kind(): string
    {
        return static::KIND;
    }

    /** يُحتسب إخفاقاً في القاطع */
    public function counts(): bool
    {
        return true;
    }

    /** يفتح القاطع من أول مرة (مفتاح مرفوض، حصة منتهية) */
    public function tripsImmediately(): bool
    {
        return false;
    }

    /** تستحق إعادة داخلية واحدة (خلل عابر، أو خنق قصير ≤ 5 ثوانٍ) */
    public function retryable(): bool
    {
        return false;
    }

    /** منقّاة: أنماط المفاتيح وkey= وأي رابط، ثم 200 حرف */
    public static function clean(string $message): string
    {
        $m = SecretRedactor::clean($message);
        $m = preg_replace(['/AIza[0-9A-Za-z_\-]{20,}/', '/sk-ant-[\w\-]+/', '/key=[^&\s"\']+/i', '#https?://\S+#'], ['***', '***', 'key=***', '[url]'], $m) ?? $m;

        return mb_substr(trim($m), 0, 200);
    }
}
