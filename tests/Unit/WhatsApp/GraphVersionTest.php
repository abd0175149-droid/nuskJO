<?php

namespace Tests\Unit\WhatsApp;

use App\Services\WhatsApp\WhatsAppChannel;
use Tests\TestCase;

class GraphVersionTest extends TestCase
{
    private function graphBase(): string
    {
        $m = new \ReflectionMethod(WhatsAppChannel::class, 'graphBase');

        return $m->invoke(null);
    }

    public function test_default_graph_version_is_supported_beyond_v21_removal(): void
    {
        config(['services.whatsapp.graph_version' => null]);

        $this->assertSame('https://graph.facebook.com/v25.0', $this->graphBase());
    }

    public function test_graph_version_follows_configuration_for_rollback(): void
    {
        config(['services.whatsapp.graph_version' => 'v21.0']);

        $this->assertSame('https://graph.facebook.com/v21.0', $this->graphBase());
    }
}
