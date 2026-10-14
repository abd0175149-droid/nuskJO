<?php

namespace Tests\Feature\Retention;

use App\Models\AuditLog;
use App\Models\Client;
use App\Models\User;
use App\Models\WaConversation;
use App\Models\WaKnowledgeEntry;
use App\Models\WaMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\Concerns\ConfiguresWhatsApp;
use Tests\TestCase;

/** التصديران (E13.8): بيانات الشخص بقوائم مسموحة وملف 0600، وإعدادات البوت بلا أي سر؛ كلاهما في التدقيق */
class ExportCommandsTest extends TestCase
{
    use ConfiguresWhatsApp;
    use RefreshDatabase;

    private array $files = [];

    protected function tearDown(): void
    {
        File::delete($this->files);
        parent::tearDown();
    }

    private function written(): string
    {
        $path = trim(explode("\n", trim(Artisan::output()))[0]);
        $this->files[] = $path;

        return $path;
    }

    public function test_the_client_export_holds_allowed_fields_only(): void
    {
        $staff = User::factory()->create(['name' => 'الموظفة سلمى']);
        $client = Client::factory()->create(['phone' => '+16505551234', 'id_number' => '9871234567']);
        $conv = WaConversation::factory()->create(['phone' => '16505551234', 'wa_phone' => '16505551234', 'client_id' => $client->id]);
        WaMessage::factory()->inbound()->create(['conversation_id' => $conv->id, 'body' => 'مرحبا', 'wamid' => 'wamid.SECRET', 'payload' => ['x' => 'payload-data']]);
        WaMessage::factory()->fromStaff($staff)->create(['conversation_id' => $conv->id, 'body' => 'أهلاً']);

        $this->assertSame(0, Artisan::call('nusk:export-client', ['--client' => $client->id]));
        $path = $this->written();

        $json = File::get($path);
        $data = json_decode($json, true);
        $this->assertSame(['العميل', 'نُسك'], array_column($data['messages'], 'source'));
        $this->assertSame('••••4567', $data['client']['id_number']);
        foreach (['wamid.SECRET', 'payload-data', 'سلمى', '9871234567'] as $hidden) {
            $this->assertStringNotContainsString($hidden, $json);
        }
        $this->assertSame('0600', substr(sprintf('%o', fileperms($path)), -4));
        $this->assertSame(2, AuditLog::where('action', 'client.data_export')->sole()->changes['counts']['messages']);
    }

    public function test_the_html_export_renders(): void
    {
        $client = Client::factory()->create();
        Artisan::call('nusk:export-client', ['--client' => $client->id, '--format' => 'html']);

        $this->assertStringContainsString('بياناتك لدى نُسك', File::get($this->written()));
    }

    public function test_the_config_export_carries_no_secret(): void
    {
        $this->configureChannel();
        WaKnowledgeEntry::create(['title' => 'الدوام', 'content' => 'من 9 إلى 5', 'is_active' => true, 'sort_order' => 1]);

        $this->assertSame(0, Artisan::call('wa:export-config', ['--with-offers' => true]));
        $json = File::get($this->written());

        foreach (['test-token', 'test-app-secret', 'test-llm-key', self::TEST_VERIFY_TOKEN, (string) config('app.key')] as $secret) {
            $this->assertStringNotContainsString($secret, $json);
        }
        $data = json_decode($json, true);
        $this->assertSame('الدوام', $data['knowledge'][0]['title']);
        $this->assertArrayNotHasKey('wa_token', $data['wa_bot_settings']);
        $this->assertTrue(AuditLog::where('action', 'config.export')->sole()->changes['with_offers']);
    }
}
