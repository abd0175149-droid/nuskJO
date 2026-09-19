<?php

namespace App\Services\Flights;

use App\Services\Flights\Contracts\FlightProvider;

class FlightSearch
{
    public static function provider(?string $driver = null): FlightProvider
    {
        $driver = $driver ?: config('flights.driver', 'demo');

        return match ($driver) {
            'travelfusion' => new TravelfusionProvider(),
            default => new DemoProvider(),
        };
    }

    /** هل الاتصال الحقيقي مُهيَّأ؟ */
    public static function isConfigured(): bool
    {
        return !empty(config('flights.travelfusion.login_id'));
    }
}
