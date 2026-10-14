<?php

namespace Tests\Feature\Infra;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class StrayRequestGuardTest extends TestCase
{
    public function test_unfaked_outbound_request_is_blocked(): void
    {
        $this->expectException(\RuntimeException::class);

        Http::get('https://graph.facebook.com/x');
    }
}
