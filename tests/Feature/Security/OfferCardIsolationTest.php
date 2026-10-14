<?php

namespace Tests\Feature\Security;

use App\Models\Offer;
use App\Services\OfferCardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** بطاقة العرض تُصيَّر بلا شبكة: سياسة CSP في القالب، وأعلام عزل كروميوم ما لم تُطفأ بـ CHROMIUM_NET_ISOLATION. */
class OfferCardIsolationTest extends TestCase
{
    use RefreshDatabase;

    private function arguments(): array
    {
        $method = new \ReflectionMethod(OfferCardService::class, 'chromiumArguments');

        return $method->invoke(null, '/usr/bin/chromium', '/tmp/profile', '/tmp/card.html', '/tmp/card.png');
    }

    public function test_card_html_forbids_any_network_or_file_load(): void
    {
        $html = OfferCardService::html(Offer::factory()->ready()->create());

        $this->assertStringContainsString(
            '<meta http-equiv="Content-Security-Policy" content="default-src \'none\'; img-src data:;',
            $html,
        );
        $this->assertDoesNotMatchRegularExpression('/(src|href)="https?:/i', $html);
    }

    public function test_chromium_runs_behind_a_dead_proxy_that_also_covers_loopback(): void
    {
        config(['services.chromium.net_isolation' => true]);
        $args = $this->arguments();

        foreach (['--proxy-server=127.0.0.1:9', '--proxy-bypass-list=<-loopback>', '--host-resolver-rules=MAP * ~NOTFOUND', '--disable-background-networking'] as $flag) {
            $this->assertContains($flag, $args);
        }
        $this->assertSame('file:///tmp/card.html', end($args), 'الملف آخر وسيط');
    }

    public function test_isolation_can_be_switched_off_without_code_changes(): void
    {
        config(['services.chromium.net_isolation' => false]);

        $this->assertNotContains('--proxy-server=127.0.0.1:9', $this->arguments());
    }
}
