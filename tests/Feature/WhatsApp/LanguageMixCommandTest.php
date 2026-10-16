<?php

namespace Tests\Feature\WhatsApp;

use App\Models\WaConversation;
use App\Models\WaMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * whatsapp:language-mix (E29.1): قياس للقراءة فقط قبل تفعيل الترجمة. أعداد ونسب وتوزيع كتابة، بلا نص ولا رقم،
 * ومحادثات أرقام الإدارة والوارد الأقدم من النافذة خارج العدّ.
 */
class LanguageMixCommandTest extends TestCase
{
    use RefreshDatabase;

    private function conversation(string $body, array $attrs = [], int $daysAgo = 1): WaConversation
    {
        $conv = WaConversation::factory()->create($attrs);
        WaMessage::factory()->inbound()->create(['conversation_id' => $conv->id, 'body' => $body, 'created_at' => now()->subDays($daysAgo)]);

        return $conv;
    }

    public function test_it_counts_scripts_and_latin_handoffs_without_printing_any_text(): void
    {
        $this->conversation('مرحبا أريد عمرة لشخصين');
        $handed = $this->conversation('Hello, I need a visa for my family, call 0791234567', ['bot_enabled' => false]);
        $this->conversation('Hi there, any umrah offers?');
        $this->conversation('مجھے عمرہ کا ویزا چاہیے');
        $this->conversation('मुझे वीज़ा चाहिए');
        $this->conversation('Old message in English', [], 200);
        $admin = $this->conversation('Report please');
        DB::table('wa_conversations')->where('id', $admin->id)->update(['is_admin_chat' => true]);
        $before = [DB::table('wa_messages')->count(), DB::table('wa_conversations')->count(), DB::table('cache')->count()];

        $this->assertSame(0, Artisan::call('whatsapp:language-mix', ['--json' => true]));
        $report = json_decode(trim(Artisan::output()), true);

        $this->assertSame(5, $report['conversations']);
        $this->assertSame(2, $report['latin']);
        $this->assertSame(40.0, (float) $report['latin_percent']);
        $this->assertSame(1, $report['latin_handed_to_staff']);
        $this->assertSame(['arabic' => 1, 'perso_urdu' => 1, 'latin' => 2, 'devanagari' => 1, 'bengali' => 0, 'other' => 0, 'none' => 0], $report['scripts']);

        Artisan::call('whatsapp:language-mix', ['--days' => 365]);
        $out = Artisan::output();
        $this->assertStringContainsString('6', $out);
        foreach (['visa', 'Hello', '0791234567', 'عمرة', (string) $handed->phone] as $private) {
            $this->assertStringNotContainsString($private, $out);
        }
        $this->assertSame($before, [DB::table('wa_messages')->count(), DB::table('wa_conversations')->count(), DB::table('cache')->count()], 'للقراءة فقط');
    }
}
