<?php

namespace App\Services\Integrations;

/**
 * توقيع Standard Webhooks حرفياً (E40): HMAC-SHA256 على «id.timestamp.body» بمفتاح السر بعد whsec_ (base64)،
 * والترويسة «v1,<base64>»، وتوقيعان مفصولان بمسافة خلال نافذة التدوير. لا صيغة توقيع ثانية.
 * المستقبل يتحقق بأي مكتبة Standard Webhooks جاهزة، ويرفض الطابع الأقدم من 5 دقائق (docs/integrations/webhooks.md).
 */
final class WebhookSigner
{
    public const PREFIX = 'whsec_';

    public static function generateSecret(): string
    {
        return self::PREFIX.base64_encode(random_bytes(32));
    }

    public static function sign(string $msgId, int $timestamp, string $body, string $secret): string
    {
        return 'v1,'.base64_encode(hash_hmac('sha256', "{$msgId}.{$timestamp}.{$body}", self::key($secret), true));
    }

    /** @param  list<string>  $secrets  الحالي أولاً ثم السابق خلال التدوير */
    public static function header(string $msgId, int $timestamp, string $body, array $secrets): string
    {
        return implode(' ', array_map(fn (string $s) => self::sign($msgId, $timestamp, $body, $s), array_values(array_unique($secrets))));
    }

    /**
     * ترويسات التسليم. webhook-id ثابت عبر المحاولات (منع التكرار عند المستقبل)، وX-Nusk-Delivery-Id جديد لكل محاولة
     * للتتبع فقط وخارج التوقيع.
     *
     * @param  list<string>  $secrets
     * @return array<string, string>
     */
    public static function headers(string $msgId, int $timestamp, string $body, array $secrets, string $event, string $deliveryId): array
    {
        return [
            'webhook-id' => $msgId,
            'webhook-timestamp' => (string) $timestamp,
            'webhook-signature' => self::header($msgId, $timestamp, $body, $secrets),
            'Content-Type' => 'application/json',
            'User-Agent' => 'NuskJO-Webhooks/1',
            'X-Nusk-Event' => $event,
            'X-Nusk-Delivery-Id' => $deliveryId,
        ];
    }

    /** للتوثيق والاختبار: ما يفعله المستقبل (أي توقيع صالح يكفي، وطابع ضمن النافذة) */
    public static function verify(string $msgId, int $timestamp, string $body, string $header, string $secret, int $tolerance = 300, ?int $now = null): bool
    {
        if (abs(($now ?? time()) - $timestamp) > $tolerance) {
            return false;
        }
        $expected = self::sign($msgId, $timestamp, $body, $secret);
        foreach (explode(' ', $header) as $candidate) {
            if (hash_equals($expected, $candidate)) {
                return true;
            }
        }

        return false;
    }

    private static function key(string $secret): string
    {
        $raw = str_starts_with($secret, self::PREFIX) ? substr($secret, strlen(self::PREFIX)) : $secret;
        $key = base64_decode($raw, true);
        if ($key === false || $key === '') {
            throw new \InvalidArgumentException('WEBHOOK_SECRET_INVALID');
        }

        return $key;
    }
}
