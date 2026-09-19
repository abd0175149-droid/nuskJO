<?php

namespace App\Services\Flights\Contracts;

use App\Services\Flights\FlightQuery;

interface FlightProvider
{
    /**
     * يعيد:
     * [
     *   'ok'      => bool,
     *   'provider'=> string,
     *   'live'    => bool,           // true = بيانات حقيقية من مزوّد
     *   'offers'  => array<array{carrier,flight_no,depart,arrive,duration,stops,price,currency,fare_type}>,
     *   'raw'     => string|null,    // الاستجابة الخام كما وصلت (للتشخيص)
     *   'error'   => string|null,
     *   'ms'      => int,            // زمن الاستعلام
     * ]
     */
    public function search(FlightQuery $query): array;
}
