<?php

namespace Tests\Concerns;

use Illuminate\Testing\TestResponse;

/**
 * إرسال حمولة ويبهوك موقّعة كما ترسلها ميتا.
 * التوقيع يُحسب على البايتات الخام للملف، ولذلك لا نستعمل postJson (يعيد الترميز فيكسر التوقيع).
 */
trait SignsMetaWebhooks
{
    protected function fixturePath(string $fixture): string
    {
        return base_path('tests/Fixtures/whatsapp/'.$fixture);
    }

    protected function postMetaWebhook(string $fixture, ?string $secret = 'test-app-secret', ?string $signature = null): TestResponse
    {
        $raw = file_get_contents($this->fixturePath($fixture));

        return $this->postRawWebhook($raw, $secret, $signature);
    }

    protected function postRawWebhook(string $raw, ?string $secret = 'test-app-secret', ?string $signature = null): TestResponse
    {
        $signature ??= $secret === null ? null : 'sha256='.hash_hmac('sha256', $raw, $secret);

        $server = ['CONTENT_TYPE' => 'application/json'];
        if ($signature !== null) {
            $server['HTTP_X_HUB_SIGNATURE_256'] = $signature;
        }

        return $this->call('POST', '/api/whatsapp/webhook', [], [], [], $server, $raw);
    }
}
