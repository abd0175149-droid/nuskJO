<?php

namespace Tests\Unit\WhatsApp;

use App\Models\WaConversation;
use App\Models\WaMessage;
use App\Services\WhatsApp\ReplyRun;
use App\Services\WhatsApp\SendKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** مفاتيح دفتر الإرسال (E19): حتمية، وقصيرة، وبلا بيانات شخصية، ونوع الإرسال جزء منها */
class SendKeyTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        ReplyRun::clear();
        parent::tearDown();
    }

    public function test_reply_keys_are_deterministic_and_differ_by_kind(): void
    {
        $conv = WaConversation::factory()->create();

        $this->assertSame("reply:{$conv->id}:42:text", SendKey::reply($conv, 42, 'text'));
        $this->assertSame(SendKey::reply($conv, 42, 'text'), SendKey::reply($conv, 42, 'text'));
        $this->assertNotSame(SendKey::reply($conv, 42, 'text'), SendKey::reply($conv, 42, 'card:7'));
        $this->assertNotSame(SendKey::reply($conv, 42, 'text'), SendKey::reply($conv, 43, 'text'));
        $this->assertNotSame(SendKey::reply($conv, 42, 'text'), SendKey::admin($conv, 42, 'text'));
    }

    public function test_upto_comes_from_the_running_reply_then_the_newest_inbound(): void
    {
        $conv = WaConversation::factory()->create();
        $in = WaMessage::factory()->create(['conversation_id' => $conv->id, 'direction' => 'in']);
        WaMessage::factory()->outbound()->create(['conversation_id' => $conv->id]);

        $this->assertSame($in->id, SendKey::upto($conv));

        $run = new ReplyRun('t', 0);
        $run->uptoId = 5;
        ReplyRun::bind($run);
        $this->assertSame(5, SendKey::upto($conv));
    }

    public function test_keys_stay_short_and_carry_no_phone_or_text(): void
    {
        $long = SendKey::optout('wamid.HBgLMTY1MDU1NTEyMzQVAgASGBQzQUY1RjlFODI1ODFFRkU4MUI4QwA=');
        $this->assertLessThanOrEqual(SendKey::MAX, strlen($long));
        $this->assertStringStartsWith('optout:', $long);
        $this->assertDoesNotMatchRegularExpression('/\d{7,}/', $long);

        foreach ([SendKey::quote(12, '350.000', 'شامل الفندق'), SendKey::staff('3f1c2b9e-5d4a-4c8b-9e7f-1a2b3c4d5e6f')] as $key) {
            $this->assertLessThanOrEqual(SendKey::MAX, strlen($key));
            $this->assertDoesNotMatchRegularExpression('/[ء-ي]/u', $key, 'لا نص عربي في المفتاح');
        }
    }

    public function test_quote_key_repeats_inside_ten_minutes_and_changes_with_price_or_resend(): void
    {
        $t = 1_760_000_400;
        $a = SendKey::quote(12, '350', 'شامل', now: $t);

        $this->assertSame($a, SendKey::quote(12, '350', 'شامل', now: $t + 120));
        $this->assertNotSame($a, SendKey::quote(12, '360', 'شامل', now: $t));
        $this->assertNotSame($a, SendKey::quote(12, '350', 'شامل', now: $t + 601));
        $this->assertNotSame($a, SendKey::quote(12, '350', 'شامل', 'abcd1234', $t));
    }

    public function test_staff_key_needs_a_uuid(): void
    {
        $this->assertSame('staff:3f1c2b9e-5d4a-4c8b-9e7f-1a2b3c4d5e6f', SendKey::staff('3F1C2B9E-5D4A-4C8B-9E7F-1A2B3C4D5E6F'));
        $this->assertNull(SendKey::staff('not-a-uuid'));
        $this->assertNull(SendKey::staff("3f1c2b9e-5d4a-4c8b-9e7f-1a2b3c4d5e6f'; drop"));
    }
}
