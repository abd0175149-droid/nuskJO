<?php

namespace Tests\Feature\Security;

use App\Models\User;
use App\Services\WhatsApp\ChannelDoctor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * CORS مغلق (E26.6): التطبيق من أصل واحد، فلا يُضاف Access-Control-Allow-Origin لأي أصل، وwhatsapp:doctor
 * ينبّه إن فُتح من جديد.
 */
class CorsTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_preflight_from_another_origin_gets_no_allow_origin(): void
    {
        foreach (['/api/whatsapp/conversations', '/api/whatsapp/webhook', '/csp-report'] as $uri) {
            $response = $this->call('OPTIONS', $uri, [], [], [], [
                'HTTP_ORIGIN' => 'https://evil.example',
                'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
                'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'content-type',
            ]);

            $this->assertFalse($response->headers->has('Access-Control-Allow-Origin'), $uri);
            $this->assertFalse($response->headers->has('Access-Control-Allow-Credentials'), $uri);
        }
    }

    public function test_a_cross_origin_read_gets_no_allow_origin(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->getJson('/api/whatsapp/conversations', ['Origin' => 'https://evil.example']);

        $response->assertOk();
        $this->assertFalse($response->headers->has('Access-Control-Allow-Origin'));
    }

    public function test_the_doctor_warns_when_cors_is_reopened(): void
    {
        $codes = fn () => array_column(ChannelDoctor::checks(), 'code');
        $this->assertNotContains('CORS_OPEN', $codes());

        config(['cors.paths' => ['api/*']]);
        $this->assertContains('CORS_OPEN', $codes());

        config(['cors.paths' => [], 'cors.allowed_origins' => ['*']]);
        $this->assertContains('CORS_OPEN', $codes());
    }
}
