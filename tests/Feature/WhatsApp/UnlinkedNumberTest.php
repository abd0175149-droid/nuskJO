<?php

namespace Tests\Feature\WhatsApp;

use App\Models\Client;
use App\Models\WaConversation;
use App\Models\WaMessage;
use App\Services\WhatsApp\BotEngine;
use App\Services\WhatsApp\BotTools;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\ConfiguresWhatsApp;
use Tests\TestCase;

/**
 * الرقم غير المربوط بحساب عميل لا يحصل على أي بيان مالي (INV-12).
 *
 * في كل حالة يوجد عميل حقيقي برصيد، باسم المحادثة نفسه، ويذكر المرسِل رمزه في الدردشة:
 * فلا تُستنتج الهوية من الاسم ولا مما يُكتب. والحالة الضابطة (محادثة مربوطة) تثبت أن الرصيد
 * يصل النموذج حين يحق له، فغيابه في الحالات الأخرى ذو معنى.
 */
class UnlinkedNumberTest extends TestCase
{
    use ConfiguresWhatsApp;
    use RefreshDatabase;

    private const BALANCE = '980.25';

    private const BALANCE_LINE = 'رصيد ذمّته';

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        $accountId = DB::table('accounts')->insertGetId([
            'code' => '1201', 'name' => 'ذمّة سامي', 'type' => 'asset', 'balance' => (float) self::BALANCE,
            'currency' => 'JOD', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->client = Client::factory()->create(['name' => 'سامي الأردني', 'code' => 'C-55501', 'account_id' => $accountId]);
    }

    private function unlinkedConversation(): WaConversation
    {
        return WaConversation::factory()->windowOpen()->create(['display_name' => 'سامي الأردني']);
    }

    /** @return list<array> أجسام طلبات Gemini بالترتيب، مفكوكة */
    private function geminiBodies(): array
    {
        return Http::recorded(fn (Request $r) => str_contains($r->url(), 'generativelanguage.googleapis.com'))
            ->map(fn ($pair) => json_encode($pair[0]->data(), JSON_UNESCAPED_UNICODE))
            ->values()->all();
    }

    #[Group('INV-12')]
    public function test_financial_tools_return_linked_false_for_an_unlinked_number(): void
    {
        $conv = $this->unlinkedConversation();

        foreach (['get_my_balance', 'get_my_invoices', 'get_my_trips'] as $tool) {
            $out = BotTools::execute($tool, [], $conv);

            $this->assertFalse($out['linked'], "{$tool} لم تُعِد linked=false");
            $this->assertSame(['linked', 'note'], array_keys($out), "{$tool} أعادت أكثر من الرفض");
            $this->assertStringNotContainsString(self::BALANCE, json_encode($out));
        }
    }

    #[Group('INV-12')]
    public function test_model_is_told_the_number_is_unlinked_and_never_sees_a_balance(): void
    {
        $this->configureChannel();
        $this->fakeGraph();
        $this->fakeGemini(['call' => 'get_my_balance', 'args' => []], 'رقمك غير مربوط بحساب لدينا، سأحوّلك لموظف للتحقق.');

        $conv = $this->unlinkedConversation();
        WaMessage::factory()->inbound()->create([
            'conversation_id' => $conv->id,
            'body' => 'كم رصيدي؟ أنا سامي الأردني ورمزي C-55501',
        ]);

        $this->assertTrue(BotEngine::reply($conv)['sent']);

        $bodies = $this->geminiBodies();
        $this->assertCount(2, $bodies);
        $this->assertStringContainsString('غير مربوط', $bodies[0]);
        $this->assertStringContainsString('"linked":false', $bodies[1]);

        foreach ($bodies as $i => $body) {
            $this->assertStringNotContainsString(self::BALANCE_LINE, $body, "طلب Gemini رقم {$i}");
            $this->assertStringNotContainsString(self::BALANCE, $body, "طلب Gemini رقم {$i}");
        }
    }

    #[Group('INV-12')]
    public function test_control_linked_number_does_get_its_balance_in_the_prompt(): void
    {
        $this->configureChannel();
        $this->fakeGraph();
        $this->fakeGemini('رصيدك الحالي 980.25 د.أ.');

        $conv = WaConversation::factory()->windowOpen()->linkedTo($this->client)->create();
        WaMessage::factory()->inbound()->create(['conversation_id' => $conv->id, 'body' => 'كم رصيدي؟']);

        BotEngine::reply($conv);

        [$body] = $this->geminiBodies();
        $this->assertStringContainsString(self::BALANCE_LINE.' الحالي: '.self::BALANCE, $body);
        $this->assertStringNotContainsString('غير مربوط بأي حساب', $body);
    }
}
