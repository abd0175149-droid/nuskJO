<?php

namespace Tests\Feature\Retention;

use App\Models\AuditLog;
use App\Models\BackupRun;
use App\Models\Client;
use App\Models\QuoteRequest;
use App\Models\User;
use App\Models\WaAdminNumber;
use App\Models\WaBotSetting;
use App\Models\WaConversation;
use App\Models\WaMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * whatsapp:prune (E13.6): معطّل بالمدة 0 أو العلم أو بلا نسخة حديثة؛ يقيس من آخر تواصل؛ ويستثني المرتبط
 * بفاتورة والرصيد المفتوح و«تدخّل» ومحادثات الإدارة؛ و--dry-run لا يكتب؛ ويحترم حد الدفعات.
 */
class PruneCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['retention.conversations_days' => 365, 'retention.batch_sleep_ms' => 0, 'retention.shrink_ledger' => storage_path('framework/testing/shrink.json')]);
        File::delete(config('retention.shrink_ledger'));
        WaBotSetting::current()->forceFill(['feature_flags' => ['retention_purge' => true]])->save();
        BackupRun::create(['reason' => 'scheduled', 'status' => 'ok', 'finished_at' => now()->subHours(2)]);
    }

    private function old(array $attrs = [], int $days = 400, int $messages = 2): WaConversation
    {
        $conv = WaConversation::factory()->create($attrs + ['last_message_at' => now()->subDays($days), 'last_inbound_at' => now()->subDays($days)]);
        WaMessage::factory()->count($messages)->inbound()->create(['conversation_id' => $conv->id, 'created_at' => now()->subDays($days)]);

        return $conv;
    }

    public function test_it_is_off_without_a_period_the_flag_or_a_fresh_backup(): void
    {
        $conv = $this->old();

        config(['retention.conversations_days' => 0]);
        Artisan::call('whatsapp:prune');
        $this->assertStringContainsString('معطّل', Artisan::output());

        config(['retention.conversations_days' => 365]);
        WaBotSetting::current()->forceFill(['feature_flags' => null])->save();
        Artisan::call('whatsapp:prune');
        $this->assertStringContainsString('retention_purge', Artisan::output());

        WaBotSetting::current()->forceFill(['feature_flags' => ['retention_purge' => true]])->save();
        BackupRun::query()->delete();
        Artisan::call('whatsapp:prune');
        $this->assertStringContainsString('نسخة', Artisan::output());

        $this->assertNotNull($conv->fresh());
        $this->assertSame(2, WaMessage::count());
    }

    public function test_it_measures_from_the_last_contact_and_skips_protected_conversations(): void
    {
        $gone = $this->old();
        $recent = WaConversation::factory()->create(['last_message_at' => now()->subDays(10), 'last_inbound_at' => now()->subDays(10)]);
        WaMessage::factory()->inbound()->create(['conversation_id' => $recent->id, 'created_at' => now()->subDays(500)]);
        $attention = $this->old(['needs_attention' => true]);
        $invoiced = $this->old();
        $q = QuoteRequest::create(['request_number' => 'Q-1', 'conversation_id' => $invoiced->id, 'phone' => '', 'type' => 'flight', 'pax_adults' => 1, 'status' => 'won']);
        DB::table('invoices')->insert(['invoice_number' => 'INV-1', 'client_id' => Client::factory()->create()->id, 'quote_request_id' => $q->id, 'exchange_rate_snapshot' => 0.189,
            'invoice_date' => now()->toDateString(), 'trip_date' => now()->toDateString(), 'status' => 'approved', 'total_sell_jod' => 1, 'created_by' => User::factory()->create()->id,
            'created_at' => now(), 'updated_at' => now()]);
        $account = DB::table('accounts')->insertGetId(['code' => '1299', 'name' => 'ذمة', 'type' => 'asset', 'balance' => 50, 'currency' => 'JOD', 'created_at' => now(), 'updated_at' => now()]);
        $owing = $this->old(['client_id' => Client::factory()->create(['account_id' => $account])->id]);
        $admin = $this->old();
        WaAdminNumber::factory()->create(['phone' => $admin->phone]);

        $this->assertSame(0, Artisan::call('whatsapp:prune'));

        $this->assertNull($gone->fresh(), 'بلا طلبات: يُحذف الصف نهائياً');
        foreach ([$recent, $attention, $invoiced, $owing, $admin] as $kept) {
            $this->assertSame('open', $kept->fresh()->status ?? 'open');
            $this->assertGreaterThan(0, WaMessage::where('conversation_id', $kept->id)->count());
        }
        $row = AuditLog::where('action', 'retention.pruned')->sole();
        $this->assertSame([1, 2, 1, 1, 1, 1], [$row->changes['conversations'], $row->changes['messages'], $row->changes['skipped']['invoiced'],
            $row->changes['skipped']['balance'], $row->changes['skipped']['attention'], $row->changes['skipped']['admin']]);
    }

    public function test_a_conversation_with_a_loose_quote_stays_as_a_pruned_witness_without_it(): void
    {
        $conv = $this->old();
        QuoteRequest::create(['request_number' => 'Q-2', 'conversation_id' => $conv->id, 'phone' => '16505551234', 'type' => 'flight', 'pax_adults' => 1, 'status' => 'lost']);

        Artisan::call('whatsapp:prune');

        $this->assertNull($conv->fresh(), 'الطلب غير المرتبط بفاتورة حُذف معها');
        $this->assertSame(0, QuoteRequest::count());
    }

    public function test_dry_run_writes_nothing_and_max_batches_limits_the_run(): void
    {
        $this->old();
        $this->old();

        Artisan::call('whatsapp:prune', ['--dry-run' => true, '--json' => true]);
        $this->assertSame(2, json_decode(Artisan::output(), true)['conversations']);
        $this->assertSame(4, WaMessage::count());
        $this->assertSame(0, AuditLog::count());

        config(['retention.batch_size' => 1]);
        Artisan::call('whatsapp:prune', ['--max-batches' => 1, '--json' => true]);
        $this->assertSame(1, json_decode(Artisan::output(), true)['conversations'], 'الحد يُفحص قبل كل محادثة');
        $this->assertSame(2, WaMessage::count());
    }
}
