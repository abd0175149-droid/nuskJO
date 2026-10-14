<?php

namespace Tests\Feature\WhatsApp;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Tests\Concerns\ConfiguresWhatsApp;
use Tests\Concerns\SignsMetaWebhooks;
use Tests\TestCase;

/**
 * الويبهوك خارج مجموعة web (routes/webhooks.php): كل إشعار من ميتا كان يكتب صف جلسة في القاعدة نفسها
 * التي يكتب فيها رد البوت. withoutMiddleware(StartSession) لا يكفي: CSRF يطلب الجلسة فيرد 500.
 */
class WebhookSessionlessTest extends TestCase
{
    use ConfiguresWhatsApp, RefreshDatabase, SignsMetaWebhooks;

    protected function setUp(): void
    {
        parent::setUp();
        // جلسات الإنتاج في القاعدة
        config(['session.driver' => 'database']);
        Queue::fake();
        $this->fakeGraph();
        $this->configureChannel();
    }

    public function test_a_signed_notification_writes_no_session_and_sets_no_cookie(): void
    {
        $response = $this->postMetaWebhook('text.json');

        $response->assertOk()->assertExactJson(['ok' => true]);
        $this->assertSame([], $response->headers->getCookies());
        $this->assertSame(0, DB::table('sessions')->count());
    }

    public function test_the_verification_handshake_writes_no_session_either(): void
    {
        $response = $this->get('api/whatsapp/webhook?hub_mode=subscribe&hub_verify_token='.self::TEST_VERIFY_TOKEN.'&hub_challenge=12345');

        $response->assertOk()->assertSee('12345');
        $this->assertSame([], $response->headers->getCookies());
        $this->assertSame(0, DB::table('sessions')->count());
    }

    public function test_a_page_still_gets_a_session(): void
    {
        // الضابط: الاختبار يرى الجلسة حين تُكتب فعلاً
        $this->get('/login')->assertOk();

        $this->assertSame(1, DB::table('sessions')->count());
    }

    public function test_the_webhook_routes_carry_no_group_middleware(): void
    {
        foreach (['GET', 'POST'] as $method) {
            $route = Route::getRoutes()->match(Request::create('/api/whatsapp/webhook', $method));

            $this->assertSame([], $route->gatherMiddleware(), $method);
        }
    }
}
