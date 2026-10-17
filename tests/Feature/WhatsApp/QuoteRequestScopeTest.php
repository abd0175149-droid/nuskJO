<?php

namespace Tests\Feature\WhatsApp;

use App\Models\Offer;
use App\Models\QuoteRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\BuildsWhatsAppStaff;
use Tests\Concerns\ConfiguresWhatsApp;
use Tests\TestCase;

/**
 * طلبات التسعير بنطاق المحادثات حين يُفعَّل services.whatsapp.fence_quotes:
 * الموكَل، أو ما سعّره الموظف، أو ما محادثته في نطاقه. المفتاح مطفأ: الكل كما اليوم.
 */
#[Group('INV-17')]
class QuoteRequestScopeTest extends TestCase
{
    use BuildsWhatsAppStaff;
    use ConfiguresWhatsApp;
    use RefreshDatabase;

    private User $sales;

    /** @var array<string, QuoteRequest> */
    private array $quotes;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureChannel();
        $this->fakeGraph();

        $this->sales = $this->staffWith('umrah-sales', ['quotes.view', 'quotes.price', 'invoices.view', 'invoices.create', 'whatsapp.view', 'whatsapp.reply'], ['umrah']);
        $other = User::factory()->create();
        $this->quotes = [
            'topic' => QuoteRequest::factory()->forConversation($this->conversationTagged(['umrah']))->create(),
            'assigned' => QuoteRequest::factory()->create(['assigned_to' => $this->sales->id]),
            'quoted' => QuoteRequest::factory()->create(['quoted_by' => $this->sales->id, 'status' => 'priced', 'quoted_price_jod' => 100]),
            'hidden' => QuoteRequest::factory()->forConversation($this->conversationTagged(['visa']))->create(),
            'hidden_priced' => QuoteRequest::factory()->create(['quoted_by' => $other->id, 'status' => 'priced', 'quoted_price_jod' => 90]),
        ];
    }

    private function fenceOn(): void
    {
        config(['services.whatsapp.fence_quotes' => true]);
    }

    private function listed(): array
    {
        $ids = [];
        $this->actingAs($this->sales)->get('/quote-requests?status=all')->assertOk()
            ->assertInertia(function ($page) use (&$ids) {
                $ids = collect($page->toArray()['props']['requests']['data'])->pluck('id')->all();
            });

        return $ids;
    }

    private function ids(string ...$keys): array
    {
        return array_map(fn ($k) => $this->quotes[$k]->id, $keys);
    }

    public function test_switch_off_lists_everything_as_today(): void
    {
        $this->assertEqualsCanonicalizing(array_map(fn ($q) => $q->id, $this->quotes), $this->listed());
    }

    public function test_switch_on_lists_and_counts_only_quotes_in_scope(): void
    {
        $this->fenceOn();

        $this->assertEqualsCanonicalizing($this->ids('topic', 'assigned', 'quoted'), $this->listed());
        $this->actingAs($this->sales)->get('/quote-requests')->assertInertia(fn ($page) => $page
            ->where('counts.new', 2)->where('counts.priced', 1));
    }

    public function test_view_all_sees_everything_with_the_switch_on(): void
    {
        $this->fenceOn();
        $manager = $this->staffWith('manager', ['quotes.view', 'whatsapp.view', 'whatsapp.view_all']);

        $this->actingAs($manager)->get('/quote-requests?status=all')->assertInertia(fn ($page) => $page
            ->has('requests.data', count($this->quotes)));
    }

    public function test_pricing_or_closing_a_quote_outside_scope_is_not_found(): void
    {
        $this->fenceOn();
        $hidden = $this->quotes['hidden'];

        $this->actingAs($this->sales)->post("/quote-requests/{$hidden->id}/price", ['quoted_price_jod' => 300, 'send_to_client' => true])->assertNotFound();
        $this->actingAs($this->sales)->post("/quote-requests/{$hidden->id}/status", ['status' => 'lost'])->assertNotFound();

        $hidden->refresh();
        $this->assertNull($hidden->quoted_price_jod);
        $this->assertSame('new', $hidden->status);
        Http::assertNothingSent();
    }

    public function test_invoice_prefill_ignores_a_quote_outside_scope(): void
    {
        $this->fenceOn();
        $offer = Offer::factory()->ready()->create();
        $this->quotes['hidden']->update(['offer_id' => $offer->id]);
        $this->quotes['topic']->update(['offer_id' => $offer->id]);

        $this->actingAs($this->sales)->get('/invoices?quote='.$this->quotes['hidden']->id)->assertInertia(fn ($page) => $page->where('prefill', null));
        $this->actingAs($this->sales)->get('/invoices?quote='.$this->quotes['topic']->id)->assertInertia(fn ($page) => $page->whereNot('prefill', null));
    }

    public function test_an_invoice_cannot_be_linked_to_a_quote_outside_scope(): void
    {
        $this->fenceOn();
        $offer = Offer::factory()->ready()->create()->load('groups.options');
        $group = $offer->groups->first();
        $payload = fn (QuoteRequest $quote) => [
            'mode' => 'offer',
            'client_name' => 'عميل تجريبي',
            'client_phone' => '16505551234',
            'trip_date' => $offer->trip_date?->toDateString() ?? now()->addMonth()->toDateString(),
            'offer_id' => $offer->id,
            'lines' => [['pax' => 2, 'room' => 'double', 'sel' => [$group->id => $group->options->first()->id]]],
            'quote_request_id' => $quote->id,
        ];

        $this->actingAs($this->sales)->post('/invoices', $payload($this->quotes['hidden']))->assertSessionHasErrors('quote_request_id');
        $this->assertNull($this->quotes['hidden']->fresh()->invoice_id);

        $this->actingAs($this->sales)->post('/invoices', $payload($this->quotes['topic']))->assertSessionHasNoErrors();
        $this->assertNotNull($this->quotes['topic']->fresh()->invoice_id);
    }
}
