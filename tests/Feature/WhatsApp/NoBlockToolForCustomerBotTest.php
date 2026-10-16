<?php

namespace Tests\Feature\WhatsApp;

use App\Models\WaBotSetting;
use App\Models\WaConversation;
use App\Services\WhatsApp\BotTools;
use Tests\TestCase;

/**
 * بوت العملاء لا يملك أي أداة حظر أبداً (E31.7)، أياً كان tools_config: الحظر قرار الشركة من الصندوق أو المساعد الإداري.
 */
class NoBlockToolForCustomerBotTest extends TestCase
{
    public function test_no_customer_bot_tool_can_block_under_any_tools_config(): void
    {
        $this->assertSame([], array_filter(BotTools::names(), fn ($n) => str_contains($n, 'block')));

        $configs = [[], array_fill_keys(BotTools::names(), true), array_fill_keys(BotTools::names(), false)];
        foreach (BotTools::names() as $name) {
            $configs[] = [$name => false];
            $configs[] = array_fill_keys(BotTools::names(), false) + [$name => true];
        }
        foreach ($configs as $config) {
            $decls = BotTools::declarations(new WaBotSetting(['tools_config' => $config]), new WaConversation);
            $json = json_encode($decls, JSON_UNESCAPED_UNICODE);
            $this->assertStringNotContainsString('block', $json);
            $this->assertStringNotContainsString('حظر', $json);
        }
    }
}
