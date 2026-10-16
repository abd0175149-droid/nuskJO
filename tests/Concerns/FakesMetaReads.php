<?php

namespace Tests\Concerns;

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * قراءات Graph للمراقب والتشخيص (E23) من tests/Fixtures/graph: عقدة الرقم، واشتراك الحساب، واشتراكات التطبيق،
 * وأرقام الحساب، وdebug_token. Http::fake يُراكم الأنماط فيُستدعى مرة لكل اختبار، و$this->metaCalls يحفظ الترتيب.
 */
trait FakesMetaReads
{
    protected const OUR_APP_ID = '100000000000002';

    protected const OUR_URL = 'https://nuskjo.example.test';

    /** @var list<string> node | waba | app | numbers | token | send */
    protected array $metaCalls = [];

    /** @var list<Request> */
    protected array $metaRequests = [];

    protected function graphFixture(string $name): array
    {
        return json_decode((string) file_get_contents(base_path("tests/Fixtures/graph/{$name}.json")), true);
    }

    /**
     * @param  array<string, string|callable|PromiseInterface>  $overrides  مفتاح => اسم ملف أو رد أو دالة (Request)
     */
    protected function fakeMetaReads(array $overrides = []): void
    {
        $defaults = ['node' => 'phone_node_ok', 'waba' => 'subscribed_apps', 'app' => 'app_subscriptions_ok', 'numbers' => 'phone_numbers', 'token' => 'debug_token_ok'];
        $this->metaCalls = [];
        $this->metaRequests = [];
        Http::fake(['graph.facebook.com/*' => function (Request $r) use ($overrides, $defaults) {
            $path = (string) parse_url($r->url(), PHP_URL_PATH);
            $key = match (true) {
                str_ends_with($path, '/subscribed_apps') => 'waba',
                str_ends_with($path, '/subscriptions') => 'app',
                str_ends_with($path, '/phone_numbers') => 'numbers',
                str_ends_with($path, '/debug_token') => 'token',
                str_ends_with($path, '/messages') => 'send',
                default => 'node',
            };
            $this->metaCalls[] = $key;
            $this->metaRequests[] = $r;
            $resp = $overrides[$key] ?? ($key === 'send' ? fn () => Http::response(['messaging_product' => 'whatsapp', 'messages' => [['id' => 'wamid.TEST.'.count($this->metaCalls)]]]) : $defaults[$key]);

            return match (true) {
                is_string($resp) => Http::response($this->graphFixture($resp)),
                is_callable($resp) => $resp($r),
                default => $resp,
            };
        }]);
    }

    protected function metaCallCount(string $key): int
    {
        return count(array_filter($this->metaCalls, fn ($k) => $k === $key));
    }
}
