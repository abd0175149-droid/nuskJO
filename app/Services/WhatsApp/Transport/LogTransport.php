<?php

namespace App\Services\WhatsApp\Transport;

use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * ناقل بلا شبكة للاختبار والتطوير المحلي فقط (WA_TRANSPORT=log). لا يُربط في production أبداً (AppServiceProvider
 * يربط CloudApiTransport ويكتب Log::critical، وwhatsapp:doctor يعلّمه خطأ).
 *
 * - الإرسال (POST …/messages) يعيد 200 بمعرّف wamid.LOG.{ulid}، فتمر الرسالة بالأقفال والسياسة والدفتر كما هي.
 * - القراءة (GET) تعيد ردوداً ثابتة من tests/Fixtures/graph/*.json إن وُجدت، وإلا {} بـ 200.
 * - يحفظ آخر 50 طلباً في الذاكرة (requests) لفحصها، ويسجّل الطريقة والمسار وحدهما بلا جسم ولا رمز.
 */
final class LogTransport implements WhatsAppTransport
{
    private const KEEP = 50;

    /** @var list<array{method: string, segments: list<string>, data: array}> */
    private array $requests = [];

    public function request(string $method, array $segments, array $data = [], #[\SensitiveParameter] ?string $token = null, int $timeout = 25, int $connectTimeout = 10): Response
    {
        $method = strtoupper($method);
        $this->requests[] = ['method' => $method, 'segments' => array_values(array_map('strval', $segments)), 'data' => $data];
        $this->requests = array_slice($this->requests, -self::KEEP);
        Log::debug('wa.transport.log', ['method' => $method, 'path' => implode('/', $segments)]);

        return new Response(new PsrResponse(200, ['Content-Type' => 'application/json'], (string) json_encode($this->body($method, $segments, $data))));
    }

    public function name(): string
    {
        return 'log';
    }

    /** @return list<array{method: string, segments: list<string>, data: array}> */
    public function requests(): array
    {
        return $this->requests;
    }

    private function body(string $method, array $segments, array $data): array
    {
        $last = (string) end($segments);
        if ($method === 'POST' && $last === 'messages') {
            return isset($data['status'])
                ? ['success' => true]
                : ['messaging_product' => 'whatsapp', 'messages' => [['id' => 'wamid.LOG.'.Str::ulid()]]];
        }
        if ($method !== 'GET') {
            return ['success' => true];
        }

        $fixture = match (true) {
            $last === 'subscribed_apps' => 'subscribed_apps',
            $last === 'subscriptions' => 'app_subscriptions_ok',
            $last === 'phone_numbers' => 'phone_numbers',
            $last === 'debug_token' => 'debug_token_ok',
            count($segments) === 1 && str_contains((string) ($data['fields'] ?? ''), 'health_status') => 'phone_node_ok',
            default => null,
        };
        $path = $fixture !== null ? base_path("tests/Fixtures/graph/{$fixture}.json") : null;

        return $path !== null && is_readable($path) ? (array) json_decode((string) file_get_contents($path), true) : [];
    }
}
