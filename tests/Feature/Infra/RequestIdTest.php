<?php

namespace Tests\Feature\Infra;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Context;
use Tests\TestCase;

/** رقم مرجعي لكل طلب: في الترويسة، وفي سياق السجل، وفي سطر التدقيق، وفي صفحة الخطأ. */
class RequestIdTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_response_carries_a_request_id(): void
    {
        $id = $this->get('/up')->assertOk()->headers->get('X-Request-ID');

        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $id);
    }

    public function test_a_valid_incoming_id_or_cf_ray_is_reused_and_anything_else_replaced(): void
    {
        $this->get('/up', ['X-Request-ID' => 'abc12345-trace'])->assertHeader('X-Request-ID', 'abc12345-trace');
        $this->get('/up', ['CF-Ray' => '8d2f1c3b4a5e6f70-AMM'])->assertHeader('X-Request-ID', '8d2f1c3b4a5e6f70-AMM');

        $replaced = $this->get('/up', ['X-Request-ID' => "bad id\nInjected: 1"])->headers->get('X-Request-ID');
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $replaced);
    }

    public function test_audit_rows_keep_the_request_id(): void
    {
        $this->actingAs(User::factory()->create());
        Context::add('request_id', 'req-12345678');

        $row = AuditLog::log('update', 'wa_bot_settings', 1, 'إعدادات البوت', ['changed' => ['model']]);

        $this->assertSame('req-12345678', $row->fresh()->request_id);
    }

    public function test_error_page_shows_a_short_reference(): void
    {
        Context::add('request_id', 'deadbeef-0000-1111');

        $html = view('errors.500')->render();

        $this->assertStringContainsString('رقم المرجع: <code>deadbeef</code>', $html);
    }
}
