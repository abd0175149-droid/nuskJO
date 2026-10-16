<?php

namespace Tests\Feature\Retention;

use App\Models\BackupRun;
use App\Models\Client;
use App\Models\WaBotSetting;
use App\Models\WaConversation;
use App\Models\WaLabel;
use App\Models\WaMessage;
use App\Models\WaThreadPin;
use App\Services\WhatsApp\ContactIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\Concerns\ConfiguresWhatsApp;
use Tests\TestCase;

/**
 * التصنيفات والتثبيت داخل الخيط في دورة حياة البيانات (E29.11): forget والتقليم يحذفان صفوف المحادثة من الجدولين
 * ويُبقيان قائمة التصنيفات العامة، والترجمات المخزّنة تذهب مع الرسائل، والمعاينة تعدّها، ونسخة الاطّلاع تسرد أسماء
 * التصنيفات والملاحظة المثبتة، والدمج ينقلهما إلى المحادثة الباقية.
 */
class ForgetIncludesLabelsAndPinsTest extends TestCase
{
    use ConfiguresWhatsApp;
    use RefreshDatabase;

    private const PHONE = '16505551234';

    private array $files = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['retention.erasure_ledger' => storage_path('framework/testing/erasure-ledger.jsonl'),
            'retention.shrink_ledger' => storage_path('framework/testing/shrink.json'), 'retention.batch_sleep_ms' => 0]);
        File::delete([config('retention.erasure_ledger'), config('retention.shrink_ledger')]);
        $this->configureChannel();
    }

    protected function tearDown(): void
    {
        File::delete([...$this->files, config('retention.erasure_ledger'), config('retention.shrink_ledger')]);
        parent::tearDown();
    }

    /** محادثة بتصنيف وملاحظة مثبتة ورسالة مثبتة وترجمة مخزّنة */
    private function decorated(WaConversation $conv, WaLabel $label, string $note = 'يفضّل غرفة ثلاثية'): WaMessage
    {
        DB::table('wa_conversation_labels')->insert(['conversation_id' => $conv->id, 'label_id' => $label->id, 'created_at' => now()]);
        $msg = WaMessage::factory()->inbound()->create(['conversation_id' => $conv->id, 'body' => 'Hello',
            'payload' => ['_translation' => ['ar' => ['text' => 'مرحباً', 'src' => 'en']]]]);
        WaThreadPin::create(['conversation_id' => $conv->id, 'kind' => 'note', 'note' => $note]);
        WaThreadPin::create(['conversation_id' => $conv->id, 'kind' => 'message', 'message_id' => $msg->id]);

        return $msg;
    }

    public function test_forget_deletes_the_labels_and_pins_of_the_number_and_keeps_the_list(): void
    {
        $this->fakeGraph();
        $label = WaLabel::create(['name' => 'ينتظر جواز', 'color' => 'amber']);
        $conv = WaConversation::factory()->windowOpen()->create(['phone' => self::PHONE, 'wa_phone' => self::PHONE]);
        $other = WaConversation::factory()->windowOpen()->create();
        $this->decorated($conv, $label);
        $this->decorated($other, $label, 'ملاحظة محادثة أخرى');

        Artisan::call('whatsapp:forget', ['--conversation' => $conv->id, '--json' => true]);
        $preview = json_decode(trim(Artisan::output()), true);
        $this->assertSame(1, $preview['counts']['labels']);
        $this->assertSame(2, $preview['counts']['thread_pins']);
        $this->assertSame(1, DB::table('wa_conversation_labels')->where('conversation_id', $conv->id)->count(), 'المعاينة لا تحذف');

        $this->assertSame(0, Artisan::call('whatsapp:forget', ['--conversation' => $conv->id, '--token' => $preview['token']]));

        $this->assertSame(0, DB::table('wa_conversation_labels')->where('conversation_id', $conv->id)->count());
        $this->assertSame(0, WaThreadPin::where('conversation_id', $conv->id)->count());
        $this->assertSame(0, WaMessage::where('conversation_id', $conv->id)->count(), 'والترجمات المخزّنة معها');
        $this->assertNotNull($label->fresh(), 'القائمة العامة لا تُمس');
        $this->assertSame(1, DB::table('wa_conversation_labels')->where('conversation_id', $other->id)->count());
        $this->assertSame(2, WaThreadPin::where('conversation_id', $other->id)->count());
    }

    public function test_prune_deletes_them_with_the_pruned_conversation(): void
    {
        config(['retention.conversations_days' => 365, 'retention.batch_sleep_ms' => 0]);
        WaBotSetting::current()->forceFill(['feature_flags' => ['retention_purge' => true]])->save();
        BackupRun::create(['reason' => 'scheduled', 'status' => 'ok', 'finished_at' => now()->subHours(2)]);
        $label = WaLabel::create(['name' => 'VIP', 'color' => 'gold']);
        $old = WaConversation::factory()->create(['last_message_at' => now()->subDays(400), 'last_inbound_at' => now()->subDays(400)]);
        $this->decorated($old, $label);

        Artisan::call('whatsapp:prune');

        $this->assertSame(0, DB::table('wa_conversation_labels')->where('conversation_id', $old->id)->count());
        $this->assertSame(0, WaThreadPin::where('conversation_id', $old->id)->count());
        $this->assertNotNull($label->fresh());
    }

    public function test_the_access_export_lists_label_names_and_the_pinned_note(): void
    {
        $client = Client::factory()->create(['phone' => '+'.self::PHONE]);
        $conv = WaConversation::factory()->create(['phone' => self::PHONE, 'wa_phone' => self::PHONE, 'client_id' => $client->id]);
        $this->decorated($conv, WaLabel::create(['name' => 'دفع عربون', 'color' => 'green', 'created_by' => 7]));

        $this->assertSame(0, Artisan::call('nusk:export-client', ['--client' => $client->id]));
        $this->files[] = $path = trim(explode("\n", trim(Artisan::output()))[0]);
        $data = json_decode(File::get($path), true);

        $this->assertSame([['name' => 'دفع عربون']], $data['labels'], 'الاسم وحده، بلا لون ولا منشئ');
        $this->assertSame('يفضّل غرفة ثلاثية', $data['pinned_notes'][0]['note']);
        $this->assertSame(['note', 'updated_at'], array_keys($data['pinned_notes'][0]));
    }

    public function test_a_merge_moves_labels_and_pins_and_joins_the_two_notes(): void
    {
        $label = WaLabel::create(['name' => 'VIP', 'color' => 'gold']);
        $second = WaLabel::create(['name' => 'دفع عربون', 'color' => 'green']);
        $a = WaConversation::factory()->create(['phone' => null, 'wa_phone' => null, 'wa_user_id' => 'US.1']);
        $b = WaConversation::factory()->create();
        $pinned = $this->decorated($a, $label, 'ملاحظة الأولى');
        $this->decorated($b, $label, 'ملاحظة الثانية');
        DB::table('wa_conversation_labels')->insert(['conversation_id' => $a->id, 'label_id' => $second->id, 'created_at' => now()]);

        ContactIdentity::mergeInto($a, $b);

        $this->assertSame(0, DB::table('wa_conversation_labels')->where('conversation_id', $a->id)->count());
        $this->assertEqualsCanonicalizing([$label->id, $second->id], DB::table('wa_conversation_labels')->where('conversation_id', $b->id)->pluck('label_id')->all());
        $this->assertSame(0, WaThreadPin::where('conversation_id', $a->id)->count());
        $this->assertSame('ملاحظة الثانية — ملاحظة الأولى', WaThreadPin::where('conversation_id', $b->id)->where('kind', 'note')->sole()->note);
        $this->assertTrue(WaThreadPin::where('conversation_id', $b->id)->where('message_id', $pinned->id)->exists(), 'الرسالة المثبتة انتقلت مع رسائلها');
    }
}
