<?php

namespace Tests\Feature\Legal;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** قناة الإبلاغ عن الثغرات في صفحة /privacy العامة (E25، البند 6.a من شروط ميتا): بالعربية والإنجليزية وبمرساة #security */
class SecurityContactTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_privacy_page_offers_a_security_reporting_channel(): void
    {
        $this->get('/privacy')->assertOk()
            ->assertSee('id="security"', false)
            ->assertSee('للإبلاغ عن ثغرة أمنية')
            ->assertSee('نرد خلال 72 ساعة')
            ->assertSee('Reporting a security vulnerability')
            ->assertSee('we reply within 72 hours');
    }
}
