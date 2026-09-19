<?php

namespace App\Services\Flights;

use App\Services\Flights\Contracts\FlightProvider;

/**
 * مزوّد تجريبي — بيانات مُصطنعة بشكل واقعي لمعاينة الصفحة قبل تفعيل حساب Travelfusion.
 * لا يتصل بأي جهة خارجية. الأسعار هنا ليست حقيقية ويجب ألّا تُقدَّم لعميل.
 */
class DemoProvider implements FlightProvider
{
    public function search(FlightQuery $q): array
    {
        $t0 = microtime(true);

        // توليد ثابت لنفس الاستعلام (بذرة من المسار والتاريخ) ليكون القياس قابلاً للتكرار
        $seed = crc32($q->from . $q->to . $q->departDate);
        mt_srand($seed);

        $carriers = [
            ['flynas', 'XY', [1.00, 1.00]],
            ['flyadeal', 'F3', [1.06, 1.12]],
            ['Royal Jordanian', 'RJ', [1.35, 1.60]],
            ['Saudia', 'SV', [1.42, 1.70]],
        ];

        $base = 78 + (mt_rand(0, 34));            // سعر أساس للفرد بالدينار
        $paxMult = max(1, $q->adults + $q->children * 0.75 + $q->infants * 0.10);
        $trip = $q->isRoundTrip() ? 1.85 : 1.0;

        $offers = [];
        foreach ($carriers as $i => [$name, $code, $range]) {
            $variants = $i === 0 ? 2 : 1;          // للناقل الأول رحلتان (صباحية/مسائية)
            for ($v = 0; $v < $variants; $v++) {
                $mult = $range[0] + (mt_rand(0, 100) / 100) * ($range[1] - $range[0]);
                $depH = [7, 14, 20, 11][($i + $v) % 4];
                $dur  = $q->from === 'AMM' ? 150 + mt_rand(0, 25) : 155 + mt_rand(0, 30);
                $stops = $i >= 2 && $v === 0 && mt_rand(0, 1) ? 1 : 0;
                if ($stops) { $dur += 95; }

                $depart = sprintf('%s %02d:%02d', $q->departDate, $depH, [0, 15, 35, 50][mt_rand(0, 3)]);
                $arrive = date('Y-m-d H:i', strtotime($depart) + $dur * 60);

                $offers[] = [
                    'carrier'   => $name,
                    'carrier_code' => $code,
                    'flight_no' => $code . ' ' . (300 + mt_rand(1, 89)),
                    'depart'    => $depart,
                    'arrive'    => $arrive,
                    'duration'  => $dur,
                    'stops'     => $stops,
                    'price'     => round($base * $mult * $paxMult * $trip, 2),
                    'currency'  => 'JOD',
                    'fare_type' => $stops ? 'Standard' : ($i === 0 ? 'Light' : 'Standard'),
                    'baggage'   => $i === 0 && !$stops ? 'بلا وزن مشمول' : '20 كغ',
                ];
            }
        }

        usort($offers, fn ($a, $b) => $a['price'] <=> $b['price']);
        mt_srand();

        return [
            'ok' => true,
            'provider' => 'demo',
            'live' => false,
            'offers' => $offers,
            'raw' => null,
            'error' => null,
            'ms' => (int) ((microtime(true) - $t0) * 1000),
        ];
    }
}
