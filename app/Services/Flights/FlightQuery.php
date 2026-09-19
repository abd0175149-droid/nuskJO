<?php

namespace App\Services\Flights;

/**
 * استعلام بحث رحلة — كائن قيمة بسيط يمرّ لأي مزوّد.
 */
class FlightQuery
{
    public function __construct(
        public string $from,            // كود المطار: AMM
        public string $to,              // كود المطار: JED
        public string $departDate,      // Y-m-d
        public ?string $returnDate = null,
        public int $adults = 1,
        public int $children = 0,
        public int $infants = 0,
        public string $cabin = 'economy',
    ) {}

    public function isRoundTrip(): bool
    {
        return !empty($this->returnDate);
    }

    public function pax(): int
    {
        return $this->adults + $this->children + $this->infants;
    }

    public static function fromArray(array $d): self
    {
        return new self(
            from: strtoupper(trim($d['from'])),
            to: strtoupper(trim($d['to'])),
            departDate: $d['depart_date'],
            returnDate: $d['return_date'] ?? null,
            adults: (int) ($d['adults'] ?? 1),
            children: (int) ($d['children'] ?? 0),
            infants: (int) ($d['infants'] ?? 0),
            cabin: $d['cabin'] ?? 'economy',
        );
    }
}
