<?php

namespace Tests\Feature\Security;

use App\Models\QuoteRequest;
use App\Models\User;
use App\Models\WaConversation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\ConfiguresWhatsApp;
use Tests\TestCase;

/** تسعير طلب: موظف واحد في كل مرة، ولا سعر ثانٍ للعميل دون تأكيد. */
class QuotePricingTest extends TestCase
{
    use ConfiguresWhatsApp;
    use RefreshDatabase;

    private User $staff;

    private QuoteRequest $quote;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureChannel();
        $this->fakeGraph();
        $this->staff = User::factory()->withPermissions(['quotes.view', 'quotes.price', 'whatsapp.view', 'whatsapp.view_all'])->create();
        $conv = WaConversation::factory()->windowOpen()->create();
        $this->quote = QuoteRequest::factory()->forConversation($conv)->create(['status' => 'new']);
    }

    private function price(array $extra = [])
    {
        return $this->actingAs($this->staff)->post("/quote-requests/{$this->quote->id}/price", $extra + [
            'quoted_price_jod' => 250, 'send_to_client' => true,
        ]);
    }

    public function test_second_colleague_waits_while_the_quote_is_being_priced(): void
    {
        $lock = Cache::lock("quote:{$this->quote->id}", 30);
        $this->assertTrue($lock->get());

        $this->price()->assertSessionHas('error', 'زميل يسعّر هذا الطلب الآن. انتظر لحظة ثم حدّث الصفحة.');
        Http::assertNothingSent();

        $lock->release();
        $this->price()->assertSessionHas('success');
        $this->assertSame('sent', $this->quote->fresh()->status);
    }

    public function test_resending_a_sent_quote_needs_explicit_confirmation(): void
    {
        $this->quote->update(['status' => 'sent']);

        $this->price()->assertSessionHasErrors('confirm_resend');
        Http::assertNothingSent();

        $this->price(['confirm_resend' => true])->assertSessionHas('success');
        Http::assertSentCount(1);
    }

    public function test_price_for_a_conversation_outside_your_scope_is_saved_but_not_sent(): void
    {
        $outsider = User::factory()->withPermissions(['quotes.view', 'quotes.price', 'whatsapp.view'], 'pricer')->create();

        $this->actingAs($outsider)->post("/quote-requests/{$this->quote->id}/price", ['quoted_price_jod' => 300, 'send_to_client' => true])
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'خارج نطاقك'));

        Http::assertNothingSent();
        $this->assertSame(300.0, (float) $this->quote->fresh()->quoted_price_jod);
    }
}
