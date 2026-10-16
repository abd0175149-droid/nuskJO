<?php

namespace App\Services\WhatsApp\Graph;

use App\Models\WaBotSetting;
use App\Services\WhatsApp\GraphVersions;
use App\Support\UrlPath;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * الطريق الوحيد إلى graph.facebook.com (E18، منقول من عميل E05 في WhatsAppChannel حرفياً). يحرسه GraphCallsUseClientTest.
 *
 * - الإصدار من الإعداد وحده، وكل مقطع مسار مرمَّز (UrlPath::segment)، ولا تحويلات، ومهلة اتصال قصيرة.
 * - appsecret_proof خلف WA_APPSECRET_PROOF، محسوباً على الرمز المرسل نفسه (رمز النظام أو رمز التطبيق في debug_token)،
 *   ويُرسل معاملاً في الرابط كما في E05، والمنقّح يحجبه من أي رابط في السجل.
 * - رابط تنزيل الوسائط (lookaside) ليس نداء Graph: media() يرسل Authorization وحده، بلا إثبات.
 * - عدّادات يومية في مخزن file (WA_GRAPH_COUNTER_STORE): نداءات، ومنها بإثبات، ورفض إثبات. يعرضها whatsapp:doctor
 *   وبطاقة الإعدادات، وسبعة أيام بنسبة 100% وصفر رفض شرط دخول E26 (Require App Secret).
 */
final class GraphClient
{
    private const HOST = 'https://graph.facebook.com/';

    /** @throws \InvalidArgumentException إصدار غير صالح أو مقطع يغيّر المسار */
    public static function url(string ...$segments): string
    {
        $version = config('services.whatsapp.graph_version');
        if (!GraphVersions::isValid($version)) {
            throw new \InvalidArgumentException('GRAPH_VERSION_INVALID');
        }

        return self::HOST.$version.'/'.implode('/', array_map(UrlPath::segment(...), $segments));
    }

    /**
     * نداء Graph واحد. GET وDELETE: $data معاملات في الرابط، وPOST: جسم JSON.
     *
     * @param  list<string>  $segments
     *
     * @throws \InvalidArgumentException إعداد غير صالح (قبل أي اتصال)
     * @throws ConnectionException فشل الاتصال أو المهلة
     */
    public static function request(string $method, array $segments, array $data = [], #[\SensitiveParameter] ?string $token = null, int $timeout = 25, int $connectTimeout = 10): Response
    {
        $url = self::url(...$segments);
        $token ??= (string) WaBotSetting::current()->token();
        $method = strtoupper($method);

        $request = Http::withToken($token)
            ->withoutRedirecting()
            ->connectTimeout($connectTimeout)
            ->timeout($timeout)
            ->acceptJson();

        $proof = self::proof($token);
        if ($proof !== null) {
            $request = $request->withQueryParameters(['appsecret_proof' => $proof]);
        }
        self::count('calls');
        if ($proof !== null) {
            self::count('proof');
        }

        $response = match ($method) {
            'POST' => $request->post($url, $data),
            'DELETE' => $request->withQueryParameters($data)->delete($url),
            default => $request->get($url, $data),
        };

        if (!$response->successful() && self::isProofRejection($response)) {
            self::count('proof_err');
        }

        return $response;
    }

    /** الإثبات على الرمز المرسل نفسه، أو null إن كان المفتاح مطفأ أو لا سر */
    public static function proof(#[\SensitiveParameter] string $token): ?string
    {
        if (!config('services.whatsapp.appsecret_proof')) {
            return null;
        }
        $secret = WaBotSetting::current()->appSecret();

        return $secret ? hash_hmac('sha256', $token, $secret) : null;
    }

    /** رابط الوسائط المؤقت من ميتا (lookaside): الرمز في Authorization وحده، بلا إثبات. المتصل يقرر التحويلات والتدفق */
    public static function media(int $timeout = 40, #[\SensitiveParameter] ?string $token = null): PendingRequest
    {
        return Http::withToken($token ?? (string) WaBotSetting::current()->token())->connectTimeout(10)->timeout($timeout);
    }

    public static function isProofRejection(Response $res): bool
    {
        return (int) data_get($res->json(), 'error.code') === 100 && str_contains(strtolower((string) data_get($res->json(), 'error.message', '')), 'appsecret_proof');
    }

    /**
     * آخر $days أيام، الأحدث أولاً.
     *
     * @return list<array{day: string, calls: int, proof: int, proof_err: int}>
     */
    public static function counters(int $days = 7): array
    {
        $out = [];
        for ($i = 0; $i < $days; $i++) {
            $day = now()->subDays($i)->format('Ymd');
            $row = ['day' => $day];
            foreach (['calls', 'proof', 'proof_err'] as $kind) {
                $row[$kind] = (int) self::store()->get("wa:graph:{$kind}:{$day}", 0);
            }
            $out[] = $row;
        }

        return $out;
    }

    /** ملخص الأيام السبعة لبطاقة الإعدادات: النسبة ورفض الإثبات، وهل تحقق شرط E26 */
    public static function proofSummary(int $days = 7): array
    {
        $rows = self::counters($days);
        $calls = array_sum(array_column($rows, 'calls'));
        $proof = array_sum(array_column($rows, 'proof'));
        $errors = array_sum(array_column($rows, 'proof_err'));
        $fullDays = count(array_filter($rows, fn ($r) => $r['calls'] > 0 && $r['calls'] === $r['proof'] && $r['proof_err'] === 0));

        return [
            'enabled' => (bool) config('services.whatsapp.appsecret_proof'),
            'days' => $days,
            'calls' => $calls,
            'proof' => $proof,
            'proof_err' => $errors,
            'percent' => $calls > 0 ? (int) floor($proof * 100 / $calls) : null,
            'ready_for_require' => $fullDays === $days,
        ];
    }

    private static function count(string $kind): void
    {
        try {
            $key = "wa:graph:{$kind}:".now()->format('Ymd');
            $store = self::store();
            $store->add($key, 0, now()->addDays(10));
            $store->increment($key);
        } catch (\Throwable) {
            // العدّاد لا يُسقط نداءً أبداً
        }
    }

    private static function store(): Repository
    {
        return Cache::store(config('services.whatsapp.graph_counter_store') ?: 'file');
    }
}
