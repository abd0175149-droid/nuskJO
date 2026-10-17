<?php

namespace Tests\Unit\Arch;

use App\Services\QuoteRequestFactory;
use App\Services\WhatsApp\FlowResponseHandler;
use Tests\TestCase;

/**
 * نماذج Flow الثابتة (E36، resources/whatsapp/flows): لا حقل جواز أو هوية أو رقم وطني في أي مفتاح أو اسم حقل أو تسمية أو خيار
 * (سياسة واتساب للأعمال)، ولا نقطة نهاية. نص المساعدة («لا تكتب رقم جواز أو هوية») تحذير للعميل لا حقل.
 */
class FlowJsonNoIdentityFieldsTest extends TestCase
{
    private const FORBIDDEN = '/passport|national|id_number|جواز|هوية|رقم وطني/iu';

    /** المفاتيح التي تحمل اسماً أو تسمية يراها العميل أو يقرؤها الخادم */
    private const LABEL_KEYS = ['name', 'label', 'title', 'id', 'text', 'description'];

    /** @return array<string, array> */
    private function flows(): array
    {
        $out = [];
        foreach (glob(resource_path('whatsapp/flows/*.json')) as $path) {
            $out[basename($path)] = json_decode((string) file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
        }

        return $out;
    }

    /** @return list<string> */
    private function offenders(array $node, string $path = '$'): array
    {
        $out = [];
        foreach ($node as $key => $value) {
            if (is_string($key) && preg_match(self::FORBIDDEN, $key)) {
                $out[] = "{$path}.{$key}";
            }
            if (is_string($value) && in_array($key, self::LABEL_KEYS, true) && preg_match(self::FORBIDDEN, $value)) {
                $out[] = "{$path}.{$key}={$value}";
            }
            if (is_array($value)) {
                $out = [...$out, ...$this->offenders($value, "{$path}.{$key}")];
            }
        }

        return $out;
    }

    public function test_the_quote_flow_exists_and_is_static(): void
    {
        $flows = $this->flows();
        $this->assertArrayHasKey('quote_request_v1.json', $flows);

        foreach ($flows as $name => $flow) {
            $json = json_encode($flow);
            $this->assertArrayNotHasKey('data_api_version', $flow, $name);
            $this->assertArrayNotHasKey('routing_model', $flow, $name);
            $this->assertStringNotContainsString('data_exchange', $json, $name);
            $this->assertCount(1, $flow['screens'], $name);
            $this->assertTrue($flow['screens'][0]['terminal'], $name);
        }

        $screen = $flows['quote_request_v1.json']['screens'][0];
        $this->assertSame('QUOTE', $screen['id']);
        $children = collect($screen['layout']['children']);
        $this->assertSame(['type', 'route_from', 'route_to', 'depart_date', 'return_date', 'adults', 'children', 'infants', 'details'],
            $children->pluck('name')->filter()->values()->all());
        $footer = $children->firstWhere('type', 'Footer');
        $this->assertSame('complete', $footer['on-click-action']['name']);
        $this->assertSame(FlowResponseHandler::FIELDS, array_keys($footer['on-click-action']['payload']));
        $this->assertSame(QuoteRequestFactory::TYPES, array_column($children->firstWhere('name', 'type')['data-source'], 'id'));
    }

    public function test_no_identity_field_in_any_flow(): void
    {
        foreach ($this->flows() as $name => $flow) {
            $this->assertSame([], $this->offenders($flow), $name);
        }
        $this->assertNotSame([], $this->offenders(['children' => [['name' => 'passport_no', 'label' => 'رقم الجواز']]]), 'الحارس يلتقط ما وُضع لأجله');
    }
}
