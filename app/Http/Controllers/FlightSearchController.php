<?php

namespace App\Http\Controllers;

use App\Services\Flights\FlightQuery;
use App\Services\Flights\FlightSearch;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * أداة تجريبية لمقارنة أسعار مزوّد بيانات الرحلات (Travelfusion) بالموقع الرسمي.
 * مقصورة على المدير العام — ليست مرتبطة بالمحاسبة ولا بالبوت.
 */
class FlightSearchController extends Controller
{
    public function index()
    {
        abort_unless(auth()->user()->isAdmin(), 403);

        return Inertia::render('Flights/Search', [
            'title' => 'تجربة مزوّد أسعار الرحلات',
            'driver' => config('flights.driver'),
            'configured' => FlightSearch::isConfigured(),
            'endpoint' => config('flights.travelfusion.endpoint'),
            'suppliers' => config('flights.travelfusion.suppliers'),
        ]);
    }

    public function search(Request $request)
    {
        abort_unless(auth()->user()->isAdmin(), 403);

        $validated = $request->validate([
            'from' => 'required|string|size:3',
            'to' => 'required|string|size:3',
            'depart_date' => 'required|date',
            'return_date' => 'nullable|date|after_or_equal:depart_date',
            'adults' => 'required|integer|min:1|max:9',
            'children' => 'nullable|integer|min:0|max:8',
            'infants' => 'nullable|integer|min:0|max:4',
            'cabin' => 'nullable|in:economy,business',
            'driver' => 'nullable|in:demo,travelfusion',
        ]);

        $query = FlightQuery::fromArray($validated);
        $result = FlightSearch::provider($validated['driver'] ?? null)->search($query);

        return response()->json($result + [
            'query' => [
                'from' => $query->from,
                'to' => $query->to,
                'depart_date' => $query->departDate,
                'return_date' => $query->returnDate,
                'pax' => $query->pax(),
            ],
        ]);
    }
}
