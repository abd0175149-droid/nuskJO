<?php

namespace App\Services\Integrations;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * التحقق من Cf-Access-Jwt-Assertion (E40.9): Cloudflare Access أمام /mcp/* بسياسة Service Auth، والتطبيق يتحقق
 * من الرمز نفسه أيضاً (فطلب يصل المنفذ مباشرة متجاوزاً النفق يُرفض). RS256 بمفاتيح الفريق (JWKS، كاش ساعة)،
 * والجمهور (AUD) والمُصدر والمدة. مكتوب داخلياً بـ openssl بلا حزمة JWT.
 */
class CloudflareAccess
{
    private const LEEWAY = 60;

    public static function configured(): bool
    {
        return self::team() !== null && trim((string) config('integrations.mcp.cloudflare.aud')) !== '';
    }

    /** نطاق الفريق بصيغة Cloudflare وحدها (team.cloudflareaccess.com)، فلا يصير الإعداد طريقاً لطلب مضيف آخر */
    public static function team(): ?string
    {
        $team = strtolower(trim((string) config('integrations.mcp.cloudflare.team_domain')));
        $team = preg_replace('~^https://~', '', rtrim($team, '/'));

        return preg_match('/^[a-z0-9][a-z0-9-]{0,62}\.cloudflareaccess\.com$/', (string) $team) ? $team : null;
    }

    public function verify(string $jwt): bool
    {
        $team = self::team();
        if ($team === null) {
            return false;
        }
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            return false;
        }
        $header = json_decode((string) self::b64($parts[0]), true);
        $claims = json_decode((string) self::b64($parts[1]), true);
        $signature = self::b64($parts[2]);
        if (!is_array($header) || !is_array($claims) || $signature === null || ($header['alg'] ?? null) !== 'RS256' || !is_string($header['kid'] ?? null)) {
            return false;
        }

        $pem = $this->keys($team)[$header['kid']] ?? $this->keys($team, refresh: true)[$header['kid']] ?? null;
        if ($pem === null || openssl_verify($parts[0].'.'.$parts[1], $signature, $pem, OPENSSL_ALGO_SHA256) !== 1) {
            return false;
        }

        $now = time();
        $aud = (array) ($claims['aud'] ?? []);

        return in_array((string) config('integrations.mcp.cloudflare.aud'), $aud, true)
            && ($claims['iss'] ?? null) === "https://{$team}"
            && is_int($claims['exp'] ?? null) && $claims['exp'] >= $now - self::LEEWAY
            && (!isset($claims['nbf']) || (is_int($claims['nbf']) && $claims['nbf'] <= $now + self::LEEWAY))
            && (!isset($claims['iat']) || (is_int($claims['iat']) && $claims['iat'] <= $now + self::LEEWAY));
    }

    /** @return array<string, string> kid => PEM */
    private function keys(string $team, bool $refresh = false): array
    {
        $key = 'mcp:cf-jwks:'.sha1($team);
        if ($refresh) {
            // تدوير المفاتيح عند Cloudflare: إعادة الجلب مرة في الدقيقة على الأكثر
            if (!Cache::add($key.':refresh', 1, 60)) {
                return [];
            }
            Cache::forget($key);
        }

        return Cache::remember($key, 3600, function () use ($team) {
            try {
                $res = Http::connectTimeout(3)->timeout(5)->acceptJson()->get("https://{$team}/cdn-cgi/access/certs");
            } catch (\Throwable $e) {
                Log::warning('mcp.cf_jwks_failed', ['exception' => $e::class]);

                return [];
            }
            $out = [];
            foreach ((array) $res->json('keys', []) as $jwk) {
                if (($jwk['kty'] ?? null) === 'RSA' && is_string($jwk['kid'] ?? null) && is_string($jwk['n'] ?? null) && is_string($jwk['e'] ?? null)) {
                    $pem = self::pem($jwk['n'], $jwk['e']);
                    if ($pem !== null) {
                        $out[$jwk['kid']] = $pem;
                    }
                }
            }

            return $out;
        });
    }

    /** مفتاح RSA العام من JWK (n وe) إلى PEM: SubjectPublicKeyInfo بترميز DER */
    public static function pem(string $n, string $e): ?string
    {
        $mod = self::b64($n);
        $exp = self::b64($e);
        if (!$mod || !$exp) {
            return null;
        }
        $rsa = self::der("\x30", self::int($mod).self::int($exp));
        $spki = self::der("\x30", hex2bin('300d06092a864886f70d0101010500').self::der("\x03", "\x00".$rsa));

        return "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($spki), 64, "\n")."-----END PUBLIC KEY-----\n";
    }

    private static function int(string $bytes): string
    {
        $bytes = ltrim($bytes, "\x00") ?: "\x00";

        return self::der("\x02", ord($bytes[0]) > 0x7F ? "\x00".$bytes : $bytes);
    }

    private static function der(string $tag, string $content): string
    {
        $len = strlen($content);
        if ($len < 0x80) {
            return $tag.chr($len).$content;
        }
        $bytes = ltrim(pack('N', $len), "\x00");

        return $tag.chr(0x80 | strlen($bytes)).$bytes.$content;
    }

    private static function b64(string $value): ?string
    {
        $decoded = base64_decode(strtr($value, '-_', '+/').str_repeat('=', (4 - strlen($value) % 4) % 4), true);

        return $decoded === false ? null : $decoded;
    }
}
