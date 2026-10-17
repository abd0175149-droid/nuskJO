<?php

namespace Tests\Feature\WhatsApp;

use App\Models\WaConversation;
use App\Models\WaMessage;
use App\Models\WaOptout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * هجرة E21 (2026_10_14_000021): عمودان nullable بإضافة محروسة. تشغيلها مرتين لا يفشل ولا يغيّر صفاً، والتراجع
 * يحذف العمودين الجديدين وحدهما.
 */
class DeliveryStatusMigrationTest extends TestCase
{
    use RefreshDatabase;

    private function migration(): object
    {
        return require database_path('migrations/2026_10_14_000021_wa_delivery_status_and_events.php');
    }

    public function test_it_is_idempotent_and_rolls_back_only_its_two_columns(): void
    {
        $conv = WaConversation::factory()->create();
        $row = WaMessage::factory()->outbound()->create(['conversation_id' => $conv->id, 'status_at' => now()]);
        WaOptout::create(['phone' => '16505551234', 'reason' => 'طلب العميل', 'source' => 'text', 'created_at' => now()]);
        $messageColumns = Schema::getColumnListing('wa_messages');
        $optoutColumns = Schema::getColumnListing('wa_optouts');

        $this->migration()->up();
        $this->migration()->up();
        $this->assertSame($messageColumns, Schema::getColumnListing('wa_messages'));
        $this->assertNotNull($row->fresh()->status_at, 'لا يمس صفاً قائماً');

        $this->migration()->down();
        $this->assertSame(array_values(array_diff($messageColumns, ['status_at'])), Schema::getColumnListing('wa_messages'));
        $this->assertSame(array_values(array_diff($optoutColumns, ['source'])), Schema::getColumnListing('wa_optouts'));
        $this->assertSame(1, WaMessage::count());
        $this->assertSame(1, WaOptout::count());

        $this->migration()->up();
        $this->assertTrue(Schema::hasColumn('wa_messages', 'status_at'));
        $this->assertTrue(Schema::hasColumn('wa_optouts', 'source'));
    }
}
