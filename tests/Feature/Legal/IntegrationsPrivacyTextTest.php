<?php

namespace Tests\Feature\Legal;

use App\Models\IntegrationEndpoint;
use App\Services\Integrations\WebhookSigner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * /privacy والتكاملات (E40.10): أداة الأتمتة تُذكر حين تعمل فعلاً (العلم ووجهة نشطة)، ومساعد المدير حين يُفعَّل خادم MCP،
 * بالعربية والإنجليزية. قبل ذلك لا تَعِد الصفحة بشيء لا يجري.
 */
class IntegrationsPrivacyTextTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_automation_tool_is_named_only_while_an_active_endpoint_receives_events(): void
    {
        $this->get('/privacy')->assertOk()->assertDontSee('أدوات أتمتة داخلية')->assertDontSee('internal automation tools');

        config(['features.env.integration_outbox' => true]);
        $endpoint = new IntegrationEndpoint;
        $endpoint->forceFill(['name' => 'n8n', 'url' => 'https://hooks.example.com/x', 'events' => ['invoice.approved'], 'secret' => WebhookSigner::generateSecret(), 'is_active' => false])->save();
        $this->get('/privacy')->assertDontSee('أدوات أتمتة داخلية');

        $endpoint->forceFill(['is_active' => true])->save();
        $this->get('/privacy')->assertSee('أدوات أتمتة داخلية نشغّلها نحن')->assertSee('which receive only request and invoice numbers');
    }

    public function test_the_manager_assistant_is_named_while_the_mcp_server_is_on(): void
    {
        $this->get('/privacy')->assertDontSee('مساعد ذكاء اصطناعي يستعمله مدير الشركة');

        config(['features.env.mcp_server' => true]);
        $this->get('/privacy')->assertSee('مساعد ذكاء اصطناعي يستعمله مدير الشركة')->assertSee('an AI assistant used by the company manager');
    }
}
