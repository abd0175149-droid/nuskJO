<?php

namespace Tests\Concerns;

use App\Models\WaChannel;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

/**
 * بوابة OpenWA وهمية للاختبار (2026-11-06): رقم جاهز، وHttp::fake لمسارات البوابة، وwebhook موقّع بسر الرقم.
 * الإرسال يعيد messageId بالصيغة wamid.OUT.{n} (كما كانت ردود ميتا الوهمية)، فتبقى اختبارات السجل كما هي.
 */
trait FakesOpenWa
{
    protected const TEST_WEBHOOK_SECRET = 'test-webhook-secret';

    protected const TEST_CHANNEL_PHONE = '962790000001';

    protected function makeChannel(array $overrides = []): WaChannel
    {
        $n = WaChannel::count() + 1;

        return WaChannel::create(array_merge([
            'name' => 'رقم '.$n, 'phone' => $n === 1 ? self::TEST_CHANNEL_PHONE : '96279000000'.($n + 1),
            'openwa_session_id' => 'sess-'.$n, 'api_key' => 'key-'.$n, 'webhook_secret' => self::TEST_WEBHOOK_SECRET, 'webhook_id' => 'wh-'.$n,
            'status' => 'ready', 'status_at' => now(), 'last_probe_ok_at' => now(), 'probe_failures' => 0,
            'roles' => ['sales'], 'bot_mode' => 'full', 'is_active' => true, 'warmup_started_at' => now()->subDays(60), 'sort' => $n,
        ], $overrides))->refresh();
    }

    /** الرقم الأول (يُنشأ إن لم يوجد) */
    protected function testChannel(): WaChannel
    {
        return WaChannel::query()->orderBy('id')->first() ?? $this->makeChannel();
    }

    /**
     * يزيّف البوابة. $overrides: نمط => رد يغلب الافتراضي، مثل نمط مسار send-text برد 503.
     */
    protected function fakeGateway(array $overrides = []): void
    {
        config(['whatsapp.openwa.url' => 'http://nuskjo-openwa:2785', 'whatsapp.openwa.admin_key' => 'admin-test-key', 'whatsapp.transport' => 'openwa']);
        $n = 0;
        Http::fake(array_merge($overrides, [
            'nuskjo-openwa:2785/*' => function ($request) use (&$n) {
                $url = $request->url();
                if (preg_match('#/messages/(send-[a-z]+|react|reply)$#', $url)) {
                    $n++;

                    return Http::response(['messageId' => 'wamid.OUT.'.$n, 'timestamp' => now()->getTimestamp()], 201);
                }
                if (preg_match('#/contacts/check/(\d+)#', $url, $m)) {
                    return Http::response(['exists' => true, 'whatsappId' => $m[1].'@c.us', 'number' => $m[1]]);
                }
                if (str_contains($url, '/media')) {
                    return Http::response('MEDIA-BYTES', 200, ['Content-Type' => 'audio/ogg']);
                }

                return Http::response(['success' => true]);
            },
        ]));
    }

    /** طلبات الإرسال إلى البوابة بالترتيب: [المسار بعد /messages، الجسم] */
    protected function gatewaySends(): array
    {
        return collect(Http::recorded())->map(fn ($pair) => $pair[0])
            ->filter(fn ($r) => preg_match('#/messages/(send-[a-z]+|react|reply)$#', $r->url()))
            ->map(fn ($r) => ['path' => (string) preg_replace('#^.*/messages/#', '', $r->url()), 'body' => $r->data(), 'headers' => $r->headers()])
            ->values()->all();
    }

    /** نص كل رسالة أُرسلت (text أو caption) */
    protected function sentTexts(): array
    {
        return array_map(fn ($s) => (string) ($s['body']['text'] ?? $s['body']['caption'] ?? ''), $this->gatewaySends());
    }

    /** حدث OpenWA موقّع إلى webhook الرقم */
    protected function postOpenWa(string $event, array $data, ?WaChannel $channel = null, ?string $secret = null, ?string $idempotency = null): TestResponse
    {
        $channel ??= $this->testChannel();
        $raw = json_encode([
            'event' => $event, 'timestamp' => now()->toIso8601String(), 'sessionId' => $channel->openwa_session_id,
            'idempotencyKey' => $idempotency ?? bin2hex(random_bytes(8)), 'deliveryId' => bin2hex(random_bytes(6)), 'data' => $data,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $this->postOpenWaRaw($raw, $channel, $secret ?? self::TEST_WEBHOOK_SECRET, $idempotency);
    }

    protected function postOpenWaRaw(string $raw, WaChannel $channel, ?string $secret = self::TEST_WEBHOOK_SECRET, ?string $idempotency = null): TestResponse
    {
        $server = ['CONTENT_TYPE' => 'application/json'];
        if ($secret !== null) {
            $server['HTTP_X_OPENWA_SIGNATURE'] = 'sha256='.hash_hmac('sha256', $raw, $secret);
        }
        if ($idempotency !== null) {
            $server['HTTP_X_OPENWA_IDEMPOTENCY_KEY'] = $idempotency;
        }

        return $this->call('POST', '/api/openwa/webhook/'.$channel->uuid, [], [], [], $server, $raw);
    }

    /** IncomingMessage نصي من هاتف عميل */
    protected function incoming(string $from, string $body = 'مرحبا', array $overrides = []): array
    {
        return array_replace_recursive([
            'id' => 'false_'.$from.'@c.us_'.strtoupper(bin2hex(random_bytes(6))),
            'from' => $from.'@c.us', 'to' => self::TEST_CHANNEL_PHONE.'@c.us', 'chatId' => $from.'@c.us',
            'body' => $body, 'type' => 'text', 'timestamp' => now()->getTimestamp(), 'fromMe' => false, 'isGroup' => false,
            'kind' => 'user', 'senderPhone' => $from, 'contact' => ['pushName' => 'عميل'],
        ], $overrides);
    }
}
