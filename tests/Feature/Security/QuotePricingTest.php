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
        $this->staff = User::factory()->withPermissions(['quotes.view', 'quotes.price'])->create();
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
}
