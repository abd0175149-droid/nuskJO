<?php

namespace Tests\Feature\WhatsApp;

use App\Models\WaBotSetting;
use App\Models\WaConversation;
use App\Models\WaCustomerNote;
use App\Models\WaLabel;
use App\Models\WaMessage;
use App\Models\WaThreadPin;
use App\Services\WhatsApp\BotEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\ConfiguresWhatsApp;
use Tests\TestCase;

/**
 * الملاحظة المثبتة وأسماء التصنيفات وملاحظات الموظفين داخلية (E29): لا تصل موجّه البوت ولا أي طلب إلى مزوّد الذكاء،
 * وبطاقة العميل تقرأ ملاحظات البوت وحدها. وحارس نصي يمنع ذكر الجداول الداخلية في ملفات البوت.
 */
class BotNeverReadsInternalNotesTest extends TestCase
{
    use ConfiguresWhatsApp;
    use RefreshDatabase;

    /** ملفات تبني سياق النموذج أو تنفّذ أدواته */
    private const BOT_FILES = [
        'app/Services/WhatsApp/BotEngine.php', 'app/Services/WhatsApp/BotTools.php', 'app/Services/WhatsApp/AdminTools.php',
        'app/Services/WhatsApp/CustomerNotes.php', 'app/Jobs/ProcessWhatsAppReply.php',
    ];

    private const INTERNAL = ['WaThreadPin', 'wa_thread_pins', 'ThreadPins', 'WaLabel', 'wa_conversation_labels', 'wa_labels', 'LabelService'];

    public function test_pinned_notes_label_names_and_staff_notes_never_reach_the_model(): void
    {
        $this->configureChannel();
        $this->fakeGraph();
        $this->fakeGemini('أهلاً بك');
        config(['features.env.wa_labels' => true, 'features.env.thread_pins' => true, 'features.env.staff_translation' => true]);

        $conv = WaConversation::factory()->windowOpen()->create();
        $label = WaLabel::create(['name' => 'LABEL_SENTINEL', 'color' => 'red']);
        DB::table('wa_conversation_labels')->insert(['conversation_id' => $conv->id, 'label_id' => $label->id, 'created_at' => now()]);
        WaThreadPin::create(['conversation_id' => $conv->id, 'kind' => 'note', 'note' => 'PIN_SENTINEL ملاحظة داخلية']);
        $earlier = WaMessage::factory()->inbound()->create(['conversation_id' => $conv->id, 'body' => 'أريد عمرة']);
        WaThreadPin::create(['conversation_id' => $conv->id, 'kind' => 'message', 'message_id' => $earlier->id]);
        WaCustomerNote::create(['conversation_id' => $conv->id, 'note' => 'STAFF_SENTINEL', 'source' => 'staff', 'created_at' => now()]);
        WaCustomerNote::create(['conversation_id' => $conv->id, 'note' => 'يفضّل الطابق الأرضي BOT_NOTE', 'source' => 'bot', 'created_at' => now()]);
        WaMessage::factory()->inbound()->create(['conversation_id' => $conv->id, 'body' => 'مرحبا']);

        $system = implode("\n", (new \ReflectionMethod(BotEngine::class, 'buildSystem'))->invoke(null, $conv, WaBotSetting::current()));
        foreach (['PIN_SENTINEL', 'LABEL_SENTINEL', 'STAFF_SENTINEL'] as $sentinel) {
            $this->assertStringNotContainsString($sentinel, $system);
        }
        $this->assertStringContainsString('BOT_NOTE', $system, 'ملاحظات البوت نفسها تبقى');

        BotEngine::reply($conv);

        $bodies = Http::recorded(fn (Request $r) => str_contains($r->url(), 'generativelanguage.googleapis.com'))
            ->map(fn ($pair) => json_encode($pair[0]->data(), JSON_UNESCAPED_UNICODE))->values()->all();
        $this->assertNotEmpty($bodies, 'البوت استدعى النموذج فعلاً');
        foreach ($bodies as $body) {
            foreach (['PIN_SENTINEL', 'LABEL_SENTINEL', 'STAFF_SENTINEL'] as $sentinel) {
                $this->assertStringNotContainsString($sentinel, $body);
            }
        }
    }

    public function test_bot_files_never_mention_the_internal_tables(): void
    {
        foreach (self::BOT_FILES as $file) {
            $code = file_get_contents(base_path($file));
            foreach (self::INTERNAL as $name) {
                $this->assertStringNotContainsString($name, $code, "{$file} يذكر {$name}: الملاحظات والتصنيفات الداخلية لا تصل البوت");
            }
        }
    }
}
