<?php

namespace App\Http\Controllers;

use App\Digest\CadencePredicate;
use App\Models\Trip;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The authenticated trip dashboard (FR-12). Thin controller: load the owner's
 * trips and project them into the view-model the page renders. No weather, no
 * analytics (UX-DR8/UX-DR9).
 *
 * Grouping is status-driven, not date-derived: `completed` is the past group;
 * `active`/`paused` are upcoming. Completion is owned by the separate
 * CompleteExpiredTrips sweep (AD-5), so the dashboard reflects status only.
 */
class DashboardController extends Controller
{
    /**
     * List the authenticated user's trips, grouped into upcoming and past.
     */
    public function index(Request $request, CadencePredicate $cadence): Response
    {
        $today = Carbon::now('America/New_York');

        // SoftDeletes scope excludes deleted trips automatically.
        $trips = $request->user()->trips()
            ->orderBy('departure_date')
            ->get();

        $cards = $trips->map(fn (Trip $trip): array => [
            'id' => $trip->id,
            'destination' => $trip->canonical_place_name !== ''
                ? $trip->canonical_place_name
                : $trip->destination_raw,
            'departure_date' => $trip->departure_date->toDateString(),
            'return_date' => $trip->return_date->toDateString(),
            'status' => $trip->status,
            // The single countdown authority (AD-11), on the send clock (AD-7).
            'days_until_departure' => $cadence->daysUntilDeparture($trip, $today),
        ]);

        return Inertia::render('Dashboard', [
            'upcomingTrips' => $cards
                ->whereIn('status', [Trip::STATUS_ACTIVE, Trip::STATUS_PAUSED])
                ->values()
                ->all(),
            'pastTrips' => $cards
                ->where('status', Trip::STATUS_COMPLETED)
                ->values()
                ->all(),
        ]);
    }
}
