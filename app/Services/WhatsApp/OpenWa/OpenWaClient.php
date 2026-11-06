<?php

namespace App\Services\WhatsApp\OpenWa;

use App\Models\WaChannel;
use App\Services\WhatsApp\WaLog;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * النداء HTTP الوحيد إلى بوابة OpenWA (GatewayOnlyTest يحرس ذلك): العنوان والمفتاح والمهلات من config('whatsapp.openwa').
 *
 * - admin(): المفتاح الرئيس، للإدارة وحدها (إنشاء الجلسات ومفاتيحها المحصورة والـ webhooks وحذفها).
 * - for($channel): مفتاح الرقم المحصور بجلسته، لكل إرسال وقراءة. لا يصل مفتاح رقم إلى جلسة رقم آخر.
 *
 * لا يرمي: كل فشل (اتصال أو مهلة أو رد غير ناجح) يعود OpenWaResponse بـ error مصنَّف (OpenWaError).
 * لا يُسجَّل جسم طلب ولا رد (فيهما أرقام ونصوص عملاء وbase64): المسار والحالة والمدة ورمز البوابة وحدها.
 */
final class OpenWaClient
{
    private function __construct(private readonly string $key, private readonly ?WaChannel $channel = null) {}

    public static function admin(): self
    {
        return new self((string) config('whatsapp.openwa.admin_key', ''));
    }

    public static function for(WaChannel $channel): self
    {
        return new self((string) ($channel->api_key ?: ''), $channel);
    }

    public function configured(): bool
    {
        return $this->key !== '' && (string) config('whatsapp.openwa.url', '') !== '';
    }

    /** مسار داخل جلسة الرقم: /sessions/{id}{path} */
    public function session(string $method, string $path, ?array $body = null, string $timeout = 'default', array $headers = [], array $query = []): OpenWaResponse
    {
        $sid = (string) $this->channel?->openwa_session_id;
        if ($sid === '') {
            return OpenWaResponse::failed(OpenWaError::local('GW_SESSION_MISSING'));
        }

        return $this->request($method, '/sessions/'.rawurlencode($sid).$path, $body, $timeout, $headers, $query);
    }

    /**
     * @param  string  $timeout  default | send | start (ثوانٍ من config)
     */
    public function request(string $method, string $path, ?array $body = null, string $timeout = 'default', array $headers = [], array $query = []): OpenWaResponse
    {
        if (!$this->configured()) {
            return OpenWaResponse::failed(OpenWaError::local($this->channel ? 'GW_AUTH' : 'GW_UNREACHABLE', 'not configured'));
        }
        $t = (array) config('whatsapp.openwa.timeouts', []);
        $seconds = (int) ($t[$timeout] ?? $t['default'] ?? 20);
        $url = config('whatsapp.openwa.url').'/api'.$path;
        $started = microtime(true);
        try {
            $req = Http::withHeaders(['X-API-Key' => $this->key, 'Accept' => 'application/json'] + $headers)
                ->timeout($seconds)
                ->connectTimeout((int) ($t['connect'] ?? 5))
                ->withOptions(['allow_redirects' => false]);
            if ($query !== []) {
                $req = $req->withQueryParameters($query);
            }
            $res = match (strtoupper($method)) {
                'GET' => $req->get($url),
                'DELETE' => $body === null ? $req->delete($url) : $req->asJson()->delete($url, $body),
                'PATCH' => $req->asJson()->patch($url, $body ?? []),
                'PUT' => $req->asJson()->put($url, $body ?? []),
                default => $req->asJson()->post($url, $body ?? []),
            };
        } catch (ConnectionException $e) {
            $error = OpenWaError::fromException($e);
            WaLog::warning('openwa.request_failed', ['path' => self::shape($path), 'error_code' => $error->code, 'channel_id' => $this->channel?->id]);

            return OpenWaResponse::failed($error);
        } catch (\Throwable $e) {
            WaLog::error('openwa.request_error', ['path' => self::shape($path), 'type' => class_basename($e), 'channel_id' => $this->channel?->id]);

            return OpenWaResponse::failed(OpenWaError::local('GW_ERROR', class_basename($e)));
        }
        $ms = (int) round((microtime(true) - $started) * 1000);
        if ($res->successful()) {
            WaLog::debug('openwa.ok', ['path' => self::shape($path), 'http' => $res->status(), 'ms' => $ms]);

            return OpenWaResponse::ok($res);
        }
        $error = OpenWaError::fromResponse($res);
        WaLog::warning('openwa.rejected', ['path' => self::shape($path), 'http' => $res->status(), 'error_code' => $error->code, 'ms' => $ms, 'channel_id' => $this->channel?->id]);

        return OpenWaResponse::rejected($res, $error);
    }

    /** المسار بلا معرّفات (jid ومعرّف رسالة وجلسة) للسجل */
    private static function shape(string $path): string
    {
        return preg_replace(['#/sessions/[^/]+#', '#/(messages|contacts)/[^/]+@[^/]+(/[^/]+)?#', '#/check/\d+#'], ['/sessions/:id', '/$1/:jid$2', '/check/:n'], $path) ?? $path;
    }
}
