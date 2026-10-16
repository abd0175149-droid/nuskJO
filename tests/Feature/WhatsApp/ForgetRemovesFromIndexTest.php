<?php

namespace Tests\Feature\WhatsApp;

use App\Models\BackupRun;
use App\Models\WaBotSetting;
use App\Models\WaConversation;
use App\Models\WaMessage;
use App\Services\WhatsApp\Search\FtsSchema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\Concerns\BuildsWhatsAppStaff;
use Tests\Concerns\ConfiguresWhatsApp;
use Tests\TestCase;

/**
 * الحذف النهائي يشمل فهرس البحث (E30.9 مع E13): بعد whatsapp:forget لا نتيجة بحث لكلمة كانت في الرسائل المحذوفة،
 * ولا أثر لها في بايتات wa_messages_fts_data: بـ 'secure-delete' حيث يصح، وإلا بإعادة كتابة الفهرس بعد الحذف.
 */
class ForgetRemovesFromIndexTest extends TestCase
{
    use BuildsWhatsAppStaff;
    use ConfiguresWhatsApp;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['retention.erasure_ledger' => storage_path('framework/testing/erasure-ledger-e30.jsonl'),
            'retention.shrink_ledger' => storage_path('framework/testing/shrink-e30.json'), 'retention.batch_sleep_ms' => 0,
            'features.env.message_search' => true]);
        File::delete([config('retention.erasure_ledger'), config('retention.shrink_ledger')]);
        $this->configureChannel();
        $this->fakeGraph();
    }

    protected function tearDown(): void
    {
        File::delete([config('retention.erasure_ledger'), config('retention.shrink_ledger')]);
        parent::tearDown();
    }

    /** مواضع بايتات النص في كتل الفهرس (ثلاثية trigram من الكلمة) */
    private function traces(string $needle): int
    {
        return (int) DB::table('wa_messages_fts_data')->whereRaw('instr(block, CAST(? AS BLOB)) > 0', [$needle])->count();
    }

    public function test_forget_leaves_no_search_hit_and_no_bytes_in_the_index(): void
    {
        if (!FtsSchema::available()) {
            $this->markTestSkipped('بلا فهرس بحث في SQLite '.FtsSchema::sqliteVersion());
        }
        $manager = $this->staffWith('manager', ['whatsapp.view', 'whatsapp.view_all']);
        $gone = WaConversation::factory()->windowOpen()->create(['phone' => '16505551234', 'wa_phone' => '16505551234']);
        $kept = WaConversation::factory()->windowOpen()->create();
        WaMessage::factory()->create(['conversation_id' => $gone->id, 'body' => 'رقم جوازي qzxjvk وموعدي غداً']);
        WaMessage::factory()->create(['conversation_id' => $kept->id, 'body' => 'رسالة باقية عن الموعد']);
        $this->assertGreaterThan(0, $this->traces('qzx'));
        $this->assertSame(1, $this->actingAs($manager)->getJson('/api/whatsapp/search?q=qzxjvk')->json('total'));

        Artisan::call('whatsapp:forget', ['--conversation' => $gone->id]);
        preg_match('/--token=([A-Z0-9]{6})/', Artisan::output(), $m);
        $this->assertSame(0, Artisan::call('whatsapp:forget', ['--conversation' => $gone->id, '--token' => $m[1]]));

        $this->assertSame(0, $this->actingAs($manager)->getJson('/api/whatsapp/search?q=qzxjvk')->json('total'));
        $this->assertSame(0, $this->traces('qzx'), 'النص المحذوف باقٍ في الفهرس (secure_delete='.(FtsSchema::secureDeleteOn() ? 'on' : 'off').')');
        $this->assertSame(1, $this->actingAs($manager)->getJson('/api/whatsapp/search?q=الموعد')->json('total'));
        $this->assertTrue(FtsSchema::integrityOk());
    }

    public function test_retention_pruning_leaves_no_trace_in_the_index(): void
    {
        if (!FtsSchema::available()) {
            $this->markTestSkipped('بلا فهرس بحث');
        }
        config(['retention.conversations_days' => 365]);
        WaBotSetting::current()->forceFill(['feature_flags' => ['retention_purge' => true]])->save();
        BackupRun::create(['reason' => 'scheduled', 'status' => 'ok', 'finished_at' => now()->subHours(2)]);
        foreach (['qzvkxa', 'jxwqvz'] as $word) {
            $conv = WaConversation::factory()->create(['last_message_at' => now()->subDays(400), 'last_inbound_at' => now()->subDays(400)]);
            WaMessage::factory()->create(['conversation_id' => $conv->id, 'body' => "قديم {$word}", 'created_at' => now()->subDays(400)]);
        }
        $this->assertGreaterThan(0, $this->traces('qzv') + $this->traces('jxw'));

        $this->assertSame(0, Artisan::call('whatsapp:prune'));

        $this->assertSame(0, WaMessage::count());
        $this->assertSame(0, $this->traces('qzv') + $this->traces('jxw'));
        $this->assertTrue(FtsSchema::integrityOk());
    }

    public function test_without_secure_delete_a_bulk_delete_rewrites_the_index(): void
    {
        if (!FtsSchema::available()) {
            $this->markTestSkipped('بلا فهرس بحث');
        }
        DB::statement("INSERT INTO wa_messages_fts(wa_messages_fts, rank) VALUES ('secure-delete', 0)");
        $c = WaConversation::factory()->create();
        WaMessage::factory()->create(['conversation_id' => $c->id, 'body' => 'كلمة wqvxzy فريدة']);
        DB::table('wa_messages')->where('conversation_id', $c->id)->delete();
        $this->assertGreaterThan(0, $this->traces('wqv'), 'بلا secure-delete يبقى المحذوف حتى الدمج');

        $this->assertSame('optimized', FtsSchema::afterBulkDelete());
        $this->assertSame(0, $this->traces('wqv'));
    }
}
