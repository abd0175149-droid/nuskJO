<?php

namespace Tests\Feature\Security;

use App\Models\AuditLog;
use App\Models\User;
use App\Support\ErrorRef;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Context;
use RuntimeException;
use Tests\Concerns\CapturesLogs;
use Tests\TestCase;

/** نص الاستثناء الخام لا يصل المستخدم: يُسجَّل كاملاً ويُعرض رقم مرجعي. */
class GenericErrorsTest extends TestCase
{
    use CapturesLogs;
    use RefreshDatabase;

    public function test_error_ref_logs_the_exception_and_returns_the_request_prefix(): void
    {
        $this->captureLogs();
        Context::add('request_id', 'abcdef12-3456');

        $ref = ErrorRef::log(new RuntimeException('SQLSTATE[HY000] details'), 'thing.failed', ['conv_id' => 3]);

        $this->assertSame('abcdef12', $ref);
        $this->assertSame('thing.failed', $this->logged[0]['message']);
        $this->assertSame('abcdef12', $this->logged[0]['context']['ref']);
        $this->assertInstanceOf(RuntimeException::class, $this->logged[0]['context']['exception']);
    }

    public function test_saving_whatsapp_settings_is_audited_by_field_name_only(): void
    {
        $owner = User::factory()->withPermissions(['whatsapp.settings'], 'owner')->create();

        $this->actingAs($owner)->put('/whatsapp/settings', ['wa_token' => 'EAAG-very-secret-token-1', 'model' => 'gemini-2.5-pro'])
            ->assertSessionHasNoErrors();

        $row = AuditLog::where('entity_type', 'wa_bot_settings')->sole();
        $this->assertSame(['model'], array_keys($row->changes['changed']));
        $this->assertSame(['wa_token'], $row->changes['credentials_changed']);
        $this->assertStringNotContainsString('very-secret', json_encode($row->toArray()));
    }
}
