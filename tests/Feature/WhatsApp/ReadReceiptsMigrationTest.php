<?php

namespace Tests\Feature\WhatsApp;

use App\Models\WaBotSetting;
use App\Models\WaConversation;
use App\Services\WhatsApp\ReadReceipts;
use App\Services\WhatsApp\Settings\SettingsWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** هجرة إشعار القراءة (E35): عمودان إضافيان محروسان، والافتراضي off، وإعادة التشغيل لا تفعل شيئاً */
class ReadReceiptsMigrationTest extends TestCase
{
    use RefreshDatabase;

    private function migration(): object
    {
        return require database_path('migrations/2026_10_14_000035_wa_read_receipts.php');
    }

    protected function tearDown(): void
    {
        ReadReceipts::flush();
        SettingsWriter::flushColumns();
        parent::tearDown();
    }

    public function test_it_adds_both_columns_with_off_as_the_default(): void
    {
        $this->assertTrue(Schema::hasColumn('wa_bot_settings', 'send_read_receipts'));
        $this->assertTrue(Schema::hasColumn('wa_conversations', 'read_receipt_upto_id'));

        DB::table('wa_bot_settings')->insert(['created_at' => now(), 'updated_at' => now()]);
        $this->assertSame('off', DB::table('wa_bot_settings')->value('send_read_receipts'));
        $this->assertNull(WaConversation::factory()->create()->fresh()->read_receipt_upto_id);
        $this->assertSame('off', ReadReceipts::configured(WaBotSetting::current()));
    }

    public function test_running_it_again_changes_nothing_and_down_removes_only_its_columns(): void
    {
        $conv = WaConversation::factory()->create(['read_receipt_upto_id' => 7]);
        $before = (array) DB::table('wa_conversations')->find($conv->id);

        $this->migration()->up();
        $this->assertSame($before, (array) DB::table('wa_conversations')->find($conv->id));

        $this->migration()->down();
        $this->assertFalse(Schema::hasColumn('wa_conversations', 'read_receipt_upto_id'));
        $this->assertFalse(Schema::hasColumn('wa_bot_settings', 'send_read_receipts'));
        $this->assertTrue(Schema::hasColumn('wa_conversations', 'bot_answered_in_id'));

        // قبل الهجرة: لا شيء يُرسل مهما كان العلم
        ReadReceipts::flush();
        config(['features.env.read_receipts' => true]);
        $this->assertFalse(ReadReceipts::available());
        $this->assertSame('off', ReadReceipts::mode());

        $this->migration()->up();
        ReadReceipts::flush();
        $this->assertTrue(ReadReceipts::available());
    }
}
