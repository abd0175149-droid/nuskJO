<?php

namespace Tests\Feature\WhatsApp;

use App\Models\AuditLog;
use App\Models\Client;
use App\Models\WaConversation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * أداتا الهجرة (E11.2): فحص المخطط يعيد 1 عند نقص ولا يطبع رقماً، والتعبئة تملأ الفارغ وحده من الحمولة
 * (المعرّف حين جاء مع رقم المحادثة نفسه)، والتجربة لا تكتب، وتقرير مراجعة الروابط بلا أرقام.
 */
class IdentityBackfillTest extends TestCase
{
    use RefreshDatabase;

    private const PHONE = '16505551234';

    private const BSUID = 'US.13491208655302741918';

    protected function setUp(): void
    {
        parent::setUp();
        File::deleteDirectory(storage_path('app/reports'));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(storage_path('app/reports'));
        parent::tearDown();
    }

    /** صف رسالة قديمة كما كُتب قبل E11: بلا الأعمدة الجديدة */
    private function legacyMessage(WaConversation $conv, array $payload, array $attrs = []): int
    {
        return DB::table('wa_messages')->insertGetId($attrs + [
            'conversation_id' => $conv->id, 'wamid' => 'wamid.L'.uniqid(), 'direction' => 'in', 'source' => 'customer',
            'msg_type' => 'text', 'body' => 'مرحبا', 'payload' => json_encode($payload), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function legacyConversation(array $attrs = []): WaConversation
    {
        $conv = WaConversation::factory()->create($attrs + ['phone' => self::PHONE, 'wa_phone' => self::PHONE]);
        DB::table('wa_conversations')->where('id', $conv->id)->update(['wa_profile_name' => null, 'client_link' => null]);

        return $conv->fresh();
    }

    public function test_verify_schema_passes_on_a_migrated_database_and_prints_no_identities(): void
    {
        $this->legacyConversation(['wa_user_id' => self::BSUID]);

        $code = Artisan::call('whatsapp:verify-schema', ['--json' => true]);
        $out = Artisan::output();

        $this->assertSame(0, $code, $out);
        $report = json_decode($out, true);
        $this->assertSame([1, 'ok', []], [$report['tables']['wa_conversations']['rows'], $report['integrity_check'], $report['gaps']]);
        $this->assertContains('wa_messages_send_key_unique', $report['tables']['wa_messages']['indexes']);
        $this->assertTrue($report['nullable']['wa_conversations.phone']);
        $this->assertStringNotContainsString(self::PHONE, $out);
        $this->assertStringNotContainsString(self::BSUID, $out);
        $this->assertCount(1, File::glob(storage_path('app/reports/wa-schema-*.json')));
    }

    public function test_verify_schema_fails_when_an_expected_index_is_missing(): void
    {
        Schema::table('wa_messages', fn ($t) => $t->dropUnique('wa_messages_send_key_unique'));

        $this->assertSame(1, Artisan::call('whatsapp:verify-schema'));
        $this->assertStringContainsString('wa_messages_send_key_unique', Artisan::output());
    }

    public function test_dry_run_writes_nothing(): void
    {
        $conv = $this->legacyConversation();
        $id = $this->legacyMessage($conv, ['from' => self::PHONE, 'from_user_id' => self::BSUID, 'context' => ['id' => 'wamid.Q']]);
        $before = [DB::table('wa_conversations')->get()->toArray(), DB::table('wa_messages')->get()->toArray()];

        $this->assertSame(0, Artisan::call('whatsapp:backfill-identity', ['--dry-run' => true]));

        $this->assertEquals($before, [DB::table('wa_conversations')->get()->toArray(), DB::table('wa_messages')->get()->toArray()]);
        $this->assertNull(DB::table('wa_messages')->where('id', $id)->value('body_norm'));
        $this->assertSame(0, AuditLog::count());
        $this->assertFalse(File::isDirectory(storage_path('app/reports')));
    }

    public function test_the_id_is_taken_only_when_it_came_with_the_same_number(): void
    {
        $a = $this->legacyConversation();
        $this->legacyMessage($a, ['from' => '+1 650 555 1234', 'from_user_id' => self::BSUID]);
        // المعرّف وحده، أو مع رقم آخر: لا يُعتمد
        $b = $this->legacyConversation(['phone' => '15550783881', 'wa_phone' => '15550783881']);
        $this->legacyMessage($b, ['from_user_id' => 'US.999']);
        $this->legacyMessage($b, ['from' => '16505553333', 'from_user_id' => 'US.888']);
        // معرّف محجوز لمحادثة أخرى: تعارض لا يُطبَّق
        $taken = WaConversation::factory()->create(['phone' => null, 'wa_phone' => null, 'wa_user_id' => 'US.777']);
        $c = $this->legacyConversation(['phone' => '16505553333', 'wa_phone' => '16505553333']);
        $this->legacyMessage($c, ['from' => '16505553333', 'from_user_id' => 'US.777']);

        $this->assertSame(0, Artisan::call('whatsapp:backfill-identity'));

        $this->assertSame([self::BSUID, null, null, 'US.777'], [$a->fresh()->wa_user_id, $b->fresh()->wa_user_id, $c->fresh()->wa_user_id, $taken->fresh()->wa_user_id]);
        $row = AuditLog::where('action', 'wa.backfill_identity')->sole();
        $this->assertSame([1, 1], [$row->changes['user_ids'], $row->changes['user_id_conflicts']]);
    }

    public function test_names_links_and_message_columns_are_filled_once(): void
    {
        $client = Client::factory()->create(['phone' => '+16505551234']);
        $linked = $this->legacyConversation(['client_id' => $client->id, 'display_name' => 'اسم العميل']);
        $stranger = $this->legacyConversation(['phone' => '15550783881', 'wa_phone' => '15550783881', 'display_name' => 'لقب واتساب']);
        $referral = ['source_type' => 'ad', 'headline' => 'عمرة'];
        $img = $this->legacyMessage($stranger, ['from' => '15550783881', 'context' => ['id' => 'wamid.Q'], 'referral' => $referral,
            'image' => ['id' => 'media.9', 'mime_type' => 'image/jpeg', 'sha256' => 'aa']], ['msg_type' => 'image', 'body' => '📷 صورة: العُمرة']);
        $out = DB::table('wa_messages')->insertGetId(['conversation_id' => $linked->id, 'direction' => 'out', 'source' => 'staff', 'msg_type' => 'text',
            'body' => 'أهلاً', 'payload' => json_encode(['context' => ['id' => 'x']]), 'created_at' => now(), 'updated_at' => now()]);

        $this->assertSame(0, Artisan::call('whatsapp:backfill-identity'));

        $this->assertSame([null, 'auto'], [$linked->fresh()->wa_profile_name, $linked->fresh()->client_link]);
        $this->assertSame(['لقب واتساب', null, 'عمرة'], [$stranger->fresh()->wa_profile_name, $stranger->fresh()->client_link, $stranger->fresh()->referral['headline']]);
        $m = DB::table('wa_messages')->find($img);
        $this->assertSame(['wamid.Q', 'media.9', 'image/jpeg', 'aa', '📷 صوره: العمره', null],
            [$m->reply_to_wamid, $m->media_id, $m->media_mime, $m->media_sha256, $m->body_norm, $m->phone_number_id]);
        $this->assertSame('عمرة', json_decode($m->referral, true)['headline']);
        $this->assertNull(DB::table('wa_messages')->find($out)->reply_to_wamid, 'الصادرة لا تُقرأ حمولتها');

        // إعادة التشغيل لا تغيّر شيئاً
        DB::table('wa_conversations')->where('id', $stranger->id)->update(['wa_profile_name' => 'عدّله موظف']);
        $this->assertSame(0, Artisan::call('whatsapp:backfill-identity'));
        $this->assertSame('عدّله موظف', $stranger->fresh()->wa_profile_name);
        $this->assertSame(0, AuditLog::where('action', 'wa.backfill_identity')->latest('id')->first()->changes['messages']);
    }

    public function test_old_links_that_the_strict_matcher_disagrees_with_are_reported_without_numbers(): void
    {
        $right = Client::factory()->create(['phone' => '+16505551234']);
        $other = Client::factory()->create(['phone' => '+15550783881', 'code' => 'C-OTHER']);
        $tailOnly = Client::factory()->create(['phone' => '+966505553333', 'code' => 'C-TAIL']);
        $ok = $this->legacyConversation(['client_id' => $right->id]);
        $wrong = $this->legacyConversation(['phone' => '15550783881', 'wa_phone' => '15550783881', 'client_id' => $right->id]);
        $tail = $this->legacyConversation(['phone' => '971505553333', 'wa_phone' => '971505553333', 'client_id' => $tailOnly->id]);
        $manual = $this->legacyConversation(['phone' => '16505553333', 'wa_phone' => '16505553333', 'client_id' => $other->id]);
        DB::table('wa_conversations')->where('id', $manual->id)->update(['client_link' => 'manual']);

        $this->assertSame(0, Artisan::call('whatsapp:backfill-identity'));

        $csv = File::get(storage_path('app/reports/wa-link-review-'.now()->toDateString().'.csv'));
        $lines = array_values(array_filter(explode("\n", ltrim($csv, "\xEF\xBB\xBF"))));
        $this->assertSame(['conv_id,client_code,reason', "{$wrong->id},{$right->code},different_client", "{$tail->id},C-TAIL,no_strict_match"], $lines);
        $this->assertStringNotContainsString('5553333', $csv);
        $this->assertSame([$right->id, $tailOnly->id], [$wrong->fresh()->client_id, $tail->fresh()->client_id], 'لا يُفك ربط آلياً');
        $this->assertSame('auto', $ok->fresh()->client_link);
    }
}
