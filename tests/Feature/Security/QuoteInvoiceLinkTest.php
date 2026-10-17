<?php

namespace Tests\Feature\Security;

use App\Models\Invoice;
use App\Models\Offer;
use App\Models\QuoteRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** طلب التسعير يُربط بفاتورة واحدة: فاتورة ثانية للطلب نفسه تُرفض ولا يُحفظ منها شيء. */
class QuoteInvoiceLinkTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_quote_request_cannot_be_invoiced_twice(): void
    {
        $sales = User::factory()->withPermissions(['invoices.view', 'invoices.create'])->create();
        $offer = Offer::factory()->ready()->create()->load('groups.options');
        $group = $offer->groups->first();
        $quote = QuoteRequest::factory()->create(['offer_id' => $offer->id, 'status' => 'priced']);

        $payload = [
            'mode' => 'offer',
            'client_name' => 'عميل تجريبي',
            'client_phone' => '16505551234',
            'trip_date' => $offer->trip_date?->toDateString() ?? now()->addMonth()->toDateString(),
            'offer_id' => $offer->id,
            'lines' => [['pax' => 2, 'room' => 'double', 'sel' => [$group->id => $group->options->first()->id]]],
            'quote_request_id' => $quote->id,
        ];

        $this->actingAs($sales)->post('/invoices', $payload)->assertSessionHasNoErrors();
        $first = Invoice::sole();
        $this->assertSame($first->id, $quote->fresh()->invoice_id);

        $this->actingAs($sales)->post('/invoices', $payload)->assertSessionHasErrors('quote_request_id');
        $this->assertSame(1, Invoice::count(), 'الفاتورة الثانية لم تُحفظ');
        $this->assertSame($first->id, $quote->fresh()->invoice_id);
    }
}
