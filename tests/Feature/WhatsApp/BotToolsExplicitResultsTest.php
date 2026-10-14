<?php

namespace Tests\Feature\WhatsApp;

use App\Models\Client;
use App\Models\WaAdminNumber;
use App\Models\WaConversation;
use App\Models\WaMessage;
use App\Services\WhatsApp\AdminTools;
use App\Services\WhatsApp\BotEngine;
use App\Services\WhatsApp\BotTools;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\ConfiguresWhatsApp;
use Tests\TestCase;

/**
 * نتائج أدوات صريحة (E12.7): غياب الحساب «غير متاح» لا «مسدّدة»، والرصيد السالب دائن، والمتبقي المجهول null.
 */
class BotToolsExplicitResultsTest extends TestCase
{
    use ConfiguresWhatsApp;
    use RefreshDatabase;

    private function account(float $balance): int
    {
        return DB::table('accounts')->insertGetId(['code' => '12'.random_int(100, 999), 'name' => 'ذمة', 'type' => 'asset', 'balance' => $balance,
            'currency' => 'JOD', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function conv(?int $accountId): WaConversation
    {
        $client = Client::factory()->create(['phone' => '+16505551234', 'account_id' => $accountId]);

        return WaConversation::factory()->windowOpen()->create(['phone' => '16505551234', 'wa_phone' => '16505551234', 'client_id' => $client->id]);
    }

    public function test_a_client_without_an_account_gets_unavailable_not_settled(): void
    {
        $out = BotTools::execute('get_my_balance', [], $this->conv(null));

        $this->assertFalse($out['ok']);
        $this->assertArrayNotHasKey('balance_jod', $out);
        $this->assertStringNotContainsString('مسدّدة ولا', $out['note']);
        $this->assertStringContainsString('غير متاح', $out['note']);
    }

    public function test_zero_is_settled_and_negative_is_a_credit(): void
    {
        $zero = BotTools::execute('get_my_balance', [], $this->conv($this->account(0)));
        $this->assertStringContainsString('مسدّدة', $zero['note']);

        WaConversation::query()->delete();
        $credit = BotTools::execute('get_my_balance', [], $this->conv($this->account(-50)));
        $this->assertSame(-50.0, $credit['balance_jod']);
        $this->assertStringContainsString('رصيد دائن', $credit['note']);
        $this->assertStringNotContainsString('مسدّدة', $credit['note']);
    }

    /** الرصيد لا يُذكر في البطاقة أصلاً (E15.6)، وget_my_balance تقول «غير متاح» (الاختبار أعلاه) */
    public function test_the_customer_card_carries_no_balance_and_the_failed_tool_rule(): void
    {
        $this->configureChannel();
        $this->fakeGraph();
        $this->fakeGemini('أهلاً');
        $conv = $this->conv(null);
        WaMessage::factory()->inbound()->create(['conversation_id' => $conv->id, 'body' => 'كم رصيدي؟']);

        BotEngine::reply($conv);

        $prompt = Http::recorded()->filter(fn ($p) => str_contains($p[0]->url(), 'generativelanguage'))
            ->map(fn ($p) => json_encode($p[0]->data(), JSON_UNESCAPED_UNICODE))->implode('');
        $this->assertStringNotContainsString('رصيد ذمّته', $prompt);
        $this->assertStringContainsString('ok=false', $prompt, 'قاعدة النظام عن نتيجة الأداة الفاشلة');
    }

    public function test_the_admin_client_report_has_no_zero_for_a_missing_account(): void
    {
        $conv = $this->conv(null);
        $admin = WaAdminNumber::factory()->withPermissions(['report.client'])->create();

        $out = AdminTools::execute('find_client', ['query' => Client::sole()->name], $conv, $admin);

        $this->assertSame([null, 'no_account'], [$out['clients'][0]['balance_jod'], $out['clients'][0]['balance_status']]);
    }
}
