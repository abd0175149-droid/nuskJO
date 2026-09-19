<?php

namespace App\Services\Flights;

use App\Services\Flights\Contracts\FlightProvider;
use Illuminate\Support\Facades\Http;

/**
 * اتصال حقيقي بـ Travelfusion Direct Connect XML API.
 *
 * التدفّق الرسمي: StartRouting  →  RoutingId  →  CheckRouting (استطلاع متكرر حتى تكتمل النتائج).
 * كل طلب يحمل XmlLoginId و LoginId معاً حسب توثيقهم.
 *
 * ملاحظة مهمة: توثيق شكل *الاستجابة* محجوب خلف حساب، لذلك المحلّل أدناه «أفضل جهد»
 * ونُعيد دائماً الاستجابة الخام كاملة لتُعاير عليها عند أول رد حقيقي.
 */
class TravelfusionProvider implements FlightProvider
{
    public function search(FlightQuery $q): array
    {
        $t0 = microtime(true);
        $cfg = config('flights.travelfusion');

        if (empty($cfg['login_id'])) {
            return $this->fail('لم يُضبط TF_LOGIN_ID — أضف بيانات حساب Travelfusion في البيئة أولاً.', $t0);
        }

        try {
            // ── 1) بدء البحث ───────────────────────────────────────────
            $startXml = $this->startRoutingXml($q, $cfg);
            $res = $this->post($startXml, $cfg);
            if (!$res['ok']) {
                return $this->fail($res['error'], $t0, $res['body']);
            }

            $routingId = $this->firstValue($res['body'], 'RoutingId');
            if (!$routingId) {
                return $this->fail('لم يُعَد RoutingId — راجع الاستجابة الخام أدناه.', $t0, $res['body']);
            }

            // ── 2) استطلاع النتائج ─────────────────────────────────────
            $deadline = microtime(true) + max(5, (int) $cfg['poll_seconds']);
            $last = $res['body'];
            $complete = false;

            while (microtime(true) < $deadline) {
                usleep(2_000_000); // ثانيتان بين الاستطلاعات
                $poll = $this->post($this->checkRoutingXml($routingId, $cfg), $cfg);
                if (!$poll['ok']) {
                    return $this->fail($poll['error'], $t0, $poll['body']);
                }
                $last = $poll['body'];

                $status = $this->firstValue($last, 'Complete') ?? $this->firstValue($last, 'Status');
                if ($status !== null && preg_match('/^(true|complete|completed|1)$/i', trim($status))) {
                    $complete = true;
                    break;
                }
            }

            $offers = $this->parseOffers($last);

            return [
                'ok' => true,
                'provider' => 'travelfusion',
                'live' => true,
                'offers' => $offers,
                'raw' => $last,
                'error' => $complete ? null : 'انتهت مدة الاستطلاع قبل اكتمال النتائج — المعروض هو ما وصل حتى الآن.',
                'ms' => (int) ((microtime(true) - $t0) * 1000),
            ];
        } catch (\Throwable $e) {
            return $this->fail('خطأ اتصال: ' . $e->getMessage(), $t0);
        }
    }

    // ==================== بناء الطلبات ====================

    private function startRoutingXml(FlightQuery $q, array $cfg): string
    {
        $login = e($cfg['login_id']);
        $xmlLogin = e($cfg['xml_login_id'] ?: $cfg['login_id']);

        // قائمة الناقلات (اختيارية) — لقصر البحث على flynas مثلاً
        $suppliers = '';
        if (!empty($cfg['suppliers'])) {
            $rows = '';
            foreach ($cfg['suppliers'] as $s) {
                $rows .= '<Supplier>' . e($s) . '</Supplier>';
            }
            $suppliers = "<SupplierList>{$rows}</SupplierList>";
        }

        // المسافرون: بالغ 30 سنة · طفل 8 · رضيع 1
        $travellers = '';
        for ($i = 0; $i < $q->adults; $i++)   { $travellers .= '<Traveller><Age>30</Age></Traveller>'; }
        for ($i = 0; $i < $q->children; $i++) { $travellers .= '<Traveller><Age>8</Age></Traveller>'; }
        for ($i = 0; $i < $q->infants; $i++)  { $travellers .= '<Traveller><Age>1</Age></Traveller>'; }

        $returnBlock = $q->isRoundTrip()
            ? "<ReturnDates><DateOfSearch>{$q->returnDate}-00:01</DateOfSearch></ReturnDates>"
            : '';

        return <<<XML
<CommandList>
  <StartRouting>
    <XmlLoginId>{$xmlLogin}</XmlLoginId>
    <LoginId>{$login}</LoginId>
    <Mode>Plane</Mode>
    {$suppliers}
    <Origin>
      <Descriptor>{$q->from}</Descriptor>
      <Type>airportcode</Type>
      <Radius>0</Radius>
    </Origin>
    <Destination>
      <Descriptor>{$q->to}</Descriptor>
      <Type>airportcode</Type>
      <Radius>0</Radius>
    </Destination>
    <OutwardDates>
      <DateOfSearch>{$q->departDate}-00:01</DateOfSearch>
    </OutwardDates>
    {$returnBlock}
    <MaxChanges>{$cfg['max_changes']}</MaxChanges>
    <MaxHops>{$cfg['max_hops']}</MaxHops>
    <Timeout>{$cfg['search_timeout']}</Timeout>
    <TravellerList>{$travellers}</TravellerList>
    <IncrementalResults>false</IncrementalResults>
  </StartRouting>
</CommandList>
XML;
    }

    private function checkRoutingXml(string $routingId, array $cfg): string
    {
        $login = e($cfg['login_id']);
        $xmlLogin = e($cfg['xml_login_id'] ?: $cfg['login_id']);
        $rid = e($routingId);

        return <<<XML
<CommandList>
  <CheckRouting>
    <XmlLoginId>{$xmlLogin}</XmlLoginId>
    <LoginId>{$login}</LoginId>
    <RoutingId>{$rid}</RoutingId>
  </CheckRouting>
</CommandList>
XML;
    }

    private function post(string $xml, array $cfg): array
    {
        $r = Http::withHeaders(['Content-Type' => 'text/xml; charset=utf-8'])
            ->timeout(45)
            ->withBody($xml, 'text/xml')
            ->post(rtrim($cfg['endpoint'], '/'));

        $body = $r->body();

        if (!$r->successful()) {
            return ['ok' => false, 'error' => 'HTTP ' . $r->status(), 'body' => $body];
        }
        // Travelfusion يعيد 200 مع <Error> داخل الجسم عند الفشل المنطقي
        if (stripos($body, '<Error') !== false) {
            $msg = $this->firstValue($body, 'ErrorText') ?? $this->firstValue($body, 'Error') ?? 'خطأ من المزوّد';
            return ['ok' => false, 'error' => trim((string) $msg), 'body' => $body];
        }

        return ['ok' => true, 'error' => null, 'body' => $body];
    }

    // ==================== قراءة الاستجابة ====================

    private function firstValue(?string $xml, string $tag): ?string
    {
        if (!$xml) return null;
        if (preg_match('#<' . preg_quote($tag, '#') . '[^>]*>(.*?)</' . preg_quote($tag, '#') . '>#is', $xml, $m)) {
            return trim(strip_tags($m[1]));
        }
        return null;
    }

    /**
     * محلّل «أفضل جهد»: يبحث عن العُقد المتكررة التي تحمل سعراً ويستخرج ما يجده.
     * تُعاير أسماء العناصر على أول استجابة حقيقية.
     */
    private function parseOffers(?string $xml): array
    {
        if (!$xml) return [];

        $sx = @simplexml_load_string($xml);
        if (!$sx) return [];

        $offers = [];
        $seen = 0;

        $walk = function ($node) use (&$walk, &$offers, &$seen) {
            foreach ($node->children() as $name => $child) {
                $priceNode = null;
                foreach (['TotalPrice', 'Price', 'Amount', 'FareTotal'] as $p) {
                    if (isset($child->$p) && trim((string) $child->$p) !== '') { $priceNode = $p; break; }
                }

                if ($priceNode && $seen < 60) {
                    $seen++;
                    $get = function (array $keys) use ($child) {
                        foreach ($keys as $k) {
                            if (isset($child->$k) && trim((string) $child->$k) !== '') return trim((string) $child->$k);
                        }
                        return null;
                    };
                    $offers[] = [
                        'carrier'   => $get(['Supplier', 'SupplierName', 'Carrier', 'Airline', 'MarketingCarrier']) ?? '—',
                        'carrier_code' => $get(['CarrierCode', 'AirlineCode']) ?? '',
                        'flight_no' => $get(['FlightNumber', 'FlightNo', 'Number']) ?? '—',
                        'depart'    => $get(['DepartDateTime', 'DepartureDateTime', 'Departure', 'DepartTime']) ?? '—',
                        'arrive'    => $get(['ArriveDateTime', 'ArrivalDateTime', 'Arrival', 'ArriveTime']) ?? '—',
                        'duration'  => (int) ($get(['Duration', 'TotalDuration']) ?? 0),
                        'stops'     => (int) ($get(['Stops', 'NumberOfStops', 'Changes']) ?? 0),
                        'price'     => (float) preg_replace('/[^0-9.]/', '', (string) $child->$priceNode),
                        'currency'  => $get(['Currency', 'CurrencyCode']) ?? '',
                        'fare_type' => $get(['FareType', 'FareBasis', 'ClassOfService']) ?? '',
                        'baggage'   => $get(['BaggageAllowance', 'Baggage']) ?? '',
                    ];
                }

                $walk($child);
            }
        };
        $walk($sx);

        usort($offers, fn ($a, $b) => $a['price'] <=> $b['price']);
        return $offers;
    }

    private function fail(string $msg, float $t0, ?string $raw = null): array
    {
        return [
            'ok' => false,
            'provider' => 'travelfusion',
            'live' => true,
            'offers' => [],
            'raw' => $raw,
            'error' => $msg,
            'ms' => (int) ((microtime(true) - $t0) * 1000),
        ];
    }
}
