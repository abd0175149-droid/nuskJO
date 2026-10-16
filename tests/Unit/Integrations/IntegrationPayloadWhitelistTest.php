<?php

namespace Tests\Unit\Integrations;

use App\Services\Integrations\IntegrationEvents;
use PHPUnit\Framework\TestCase;

/**
 * الحمولة البيضاء (E40.3): كل حدث بحقوله المعلنة وحدها، ولا اسم حقل يشبه تكلفة أو ربحاً أو وكيلاً أو جوازاً أو هاتفاً
 * أو نصاً من المحادثة، والمبلغ لا يخرج إلا مع include_amounts.
 */
class IntegrationPayloadWhitelistTest extends TestCase
{
    private const FORBIDDEN = '/cost|profit|agent|margin|passport|phone|body|details|note|seats|caption|transcript|customer|name$/i';

    public function test_every_event_is_declared_and_no_field_name_is_forbidden(): void
    {
        $this->assertSame(IntegrationEvents::ALLOWED, array_keys(IntegrationEvents::FIELDS));
        foreach (IntegrationEvents::FIELDS as $event => $fields) {
            $this->assertArrayHasKey($event, IntegrationEvents::LABELS);
            foreach ($fields as $field) {
                $this->assertDoesNotMatchRegularExpression(self::FORBIDDEN, $field, "{$event}.{$field}");
            }
        }
    }

    public function test_undeclared_fields_are_dropped_even_when_passed(): void
    {
        $data = IntegrationEvents::whitelist('quote_request.created', [
            'request_number' => 'QR-1', 'type' => 'flight', 'details' => 'نص العميل', 'phone' => '16505551234',
            'customer_name' => 'اسم', 'total_cost_jod' => 10,
        ], false);

        $this->assertSame(['request_number' => 'QR-1', 'type' => 'flight'], $data);
    }

    public function test_the_amount_leaves_only_with_include_amounts(): void
    {
        $data = ['invoice_number' => 'INV-1', 'total_sell_jod' => 120.5, 'profit_jod' => 20];

        $this->assertSame(['invoice_number' => 'INV-1'], IntegrationEvents::whitelist('invoice.approved', $data, false));
        $this->assertSame(['invoice_number' => 'INV-1', 'total_sell_jod' => 120.5], IntegrationEvents::whitelist('invoice.approved', $data, true));
        $this->assertNotContains('total_sell_jod', IntegrationEvents::fieldsFor('invoice.approved', false));
    }

    public function test_the_envelope_is_stable(): void
    {
        $at = new \DateTimeImmutable('2026-10-16T10:00:00+03:00');
        $envelope = IntegrationEvents::envelope('ping', 'ping_1', ['message' => 'hi'], $at);

        $this->assertSame(['type', 'id', 'timestamp', 'schema', 'data'], array_keys($envelope));
        $this->assertSame('2026-10-16T10:00:00+03:00', $envelope['timestamp']);
        $this->assertSame(1, $envelope['schema']);
        $this->assertSame('{"type":"ping","id":"ping_1","timestamp":"2026-10-16T10:00:00+03:00","schema":1,"data":{}}',
            json_encode(IntegrationEvents::envelope('ping', 'ping_1', [], $at), JSON_UNESCAPED_SLASHES));
    }

    public function test_the_catalog_lists_subscribable_events_with_their_exact_fields(): void
    {
        $catalog = IntegrationEvents::catalog();

        $this->assertSame(IntegrationEvents::SUBSCRIBABLE, array_column($catalog, 'key'));
        $invoice = collect($catalog)->firstWhere('key', 'invoice.approved');
        $this->assertSame(['total_sell_jod'], $invoice['amount_fields']);
    }
}
