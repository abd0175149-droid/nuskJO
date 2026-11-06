<?php

namespace App\Services\WhatsApp\Transport;

use App\Models\WaChannel;
use App\Services\WhatsApp\OpenWa\OpenWaResponse;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * ناقل بلا شبكة للاختبار والتطوير المحلي فقط (WA_TRANSPORT=log). لا يُربط في production أبداً (AppServiceProvider
 * يربط OpenWaTransport ويكتب Log::critical، وwhatsapp:doctor يعلّمه خطأ).
 *
 * - الإرسال (POST …/messages/send-* و react) يعيد 201 بمعرّف true_{chatId}_LOG{ulid}، فتمر الرسالة بالأقفال والسياسة والدفتر كما هي.
 * - contacts/check يعيد exists=true، وغيره {} بـ 200.
 * - يحفظ آخر 50 طلباً في الذاكرة (requests) لفحصها، ويسجّل الطريقة والمسار وحدهما بلا جسم.
 */
final class LogTransport implements ChannelTransport
{
    private const KEEP = 50;

    /** @var list<array{channel_id: int, method: string, path: string, body: ?array, headers: array}> */
    private array $requests = [];

    public function call(WaChannel $channel, string $method, string $path, ?array $body = null, string $timeout = 'default', array $headers = [], array $query = []): OpenWaResponse
    {
        $method = strtoupper($method);
        $this->requests[] = ['channel_id' => (int) $channel->id, 'method' => $method, 'path' => $path, 'body' => $body, 'headers' => $headers];
        $this->requests = array_slice($this->requests, -self::KEEP);
        Log::debug('wa.transport.log', ['method' => $method, 'path' => preg_replace('/\d{6,}/', '…', $path)]);

        return OpenWaResponse::ok(new Response(new PsrResponse($method === 'POST' ? 201 : 200, ['Content-Type' => 'application/json'],
            (string) json_encode($this->body($method, $path, (array) $body)))));
    }

    public function name(): string
    {
        return 'log';
    }

    /** @return list<array{channel_id: int, method: string, path: string, body: ?array, headers: array}> */
    public function requests(): array
    {
        return $this->requests;
    }

    private function body(string $method, string $path, array $body): array
    {
        if ($method === 'POST' && (str_contains($path, '/messages/send-') || str_ends_with($path, '/messages/react') || str_ends_with($path, '/messages/reply'))) {
            return ['messageId' => 'true_'.($body['chatId'] ?? 'x@c.us').'_LOG'.Str::ulid(), 'timestamp' => now()->getTimestamp()];
        }
        if (str_contains($path, '/contacts/check/')) {
            $digits = (string) preg_replace('/\D/', '', (string) substr($path, strrpos($path, '/') + 1));

            return ['exists' => true, 'whatsappId' => $digits.'@c.us', 'number' => $digits];
        }

        return $method === 'GET' ? [] : ['success' => true];
    }
}
