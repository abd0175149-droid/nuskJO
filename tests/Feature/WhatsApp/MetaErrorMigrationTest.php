<?php

namespace Tests\Feature\WhatsApp;

use App\Models\WaConversation;
use App\Models\WaMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * هجرة تصنيف الأخطاء (E18): إضافية ومحروسة، وتعبئتها متساوية الأثر: رقم ميتا نصاً في error_code يصير meta_code،
 * ثم الرمز الداخلي والفئة من الجدول. لا تخمين من نص الخطأ، ولا يتغير عدد الصفوف ولا أكبر معرّف.
 */
class MetaErrorMigrationTest extends TestCase
{
    use RefreshDatabase;

    private function migration(): object
    {
        return require database_path('migrations/2026_10_14_000018_wa_meta_error_classification.php');
    }

    private function failed(array $attrs): int
    {
        $conv = WaConversation::factory()->create();

        return WaMessage::factory()->outbound()->create(['conversation_id' => $conv->id, 'status' => 'failed'] + $attrs)->id;
    }

    public function test_columns_index_and_settings_column_exist(): void
    {
        $this->assertTrue(Schema::hasColumns('wa_messages', ['meta_code', 'fbtrace_id', 'error_code', 'error_category']));
        $this->assertTrue(Schema::hasIndex('wa_messages', 'wa_messages_dir_status_created_index'));
        $this->assertTrue(Schema::hasColumn('wa_bot_settings', 'wa_last_error'));
    }

    public function test_backfill_is_idempotent_and_touches_only_numeric_codes(): void
    {
        $numeric = $this->failed(['error_code' => '131047', 'meta_code' => null, 'error_message' => 'Re-engagement message']);
        $known = $this->failed(['meta_code' => 190, 'error_code' => 'TOKEN_INVALID']);
        $policy = $this->failed(['error_code' => 'POLICY_SECRET', 'status' => 'blocked']);
        $fromHistory = $this->failed(['payload' => ['statusHistory' => [['status' => 'sent'], ['status' => 'failed', 'code' => 131026]]]]);
        $guessless = $this->failed(['error_message' => '[131026] تعذّر التسليم']);
        $count = WaMessage::count();
        $max = WaMessage::max('id');

        $this->migration()->up();
        $first = DB::table('wa_messages')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
        $this->migration()->up();

        $this->assertSame($first, DB::table('wa_messages')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(), 'التشغيل الثاني لا يغيّر شيئاً');
        $this->assertSame([$count, $max], [WaMessage::count(), WaMessage::max('id')]);

        $this->assertSame([131047, 'WINDOW_EXPIRED', 'window'], $this->codes($numeric));
        $this->assertSame([190, 'TOKEN_INVALID', 'auth'], $this->codes($known));
        $this->assertSame([null, 'POLICY_SECRET', null], $this->codes($policy));
        $this->assertSame([131026, 'RECIPIENT_UNREACHABLE', 'caller'], $this->codes($fromHistory));
        $this->assertSame([null, null, null], $this->codes($guessless), 'لا تخمين من نص الخطأ');
    }

    public function test_down_keeps_columns_owned_by_earlier_epics(): void
    {
        $this->migration()->down();

        $this->assertFalse(Schema::hasColumn('wa_messages', 'error_category'));
        $this->assertFalse(Schema::hasColumn('wa_bot_settings', 'wa_last_error'));
        $this->assertTrue(Schema::hasColumns('wa_messages', ['meta_code', 'fbtrace_id', 'error_code']));

        $this->migration()->up();
        $this->assertTrue(Schema::hasColumn('wa_messages', 'error_category'));
    }

    private function codes(int $id): array
    {
        $row = DB::table('wa_messages')->find($id);

        return [$row->meta_code === null ? null : (int) $row->meta_code, $row->error_code, $row->error_category];
    }
}
