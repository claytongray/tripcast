<?php

namespace App\Http\Controllers;

use App\Actions\CreateTrip;
use App\Actions\TripLimitReachedException;
use App\Digest\CadencePredicate;
use App\Http\Requests\AddTripRequest;
use App\Models\InvalidTripTransitionException;
use App\Models\Trip;
use App\Policies\TripPolicy;
use App\Services\Geocoding\Geocoder;
use App\Services\Geocoding\GeocodingFailedException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Trip management from the dashboard (FR-12): add a trip, and pause/resume/delete.
 * Every status change routes through the single state-transition method on
 * {@see Trip} (AD-5); creation routes through {@see CreateTrip} (AD-10). Ownership
 * is enforced by {@see TripPolicy}; non-owners get 403.
 */
class TripController extends Controller
{
    /**
     * Add a trip from the dashboard (Story 3.2). Geocode once at submit (AD-8),
     * then create through the single decision point (AD-10) for the already-
     * confirmed owner — no email-capture step; the welcome fires at creation
     * (AD-6/FR-9). Lands on the shared dated success screen.
     */
    public function store(AddTripRequest $request, Geocoder $geocoder, CreateTrip $createTrip): RedirectResponse
    {
        $details = $request->tripDetails();

        try {
            $place = $geocoder->geocode($details['destination']);
        } catch (GeocodingFailedException) {
            return back()->withInput()->withErrors([
                'destination' => "We couldn't find that place. Try a city and country — like 'Edinburgh, UK'.",
            ]);
        }

        try {
            $trip = $createTrip->handle($request->user()->email, [
                ...$details,
                'canonical_place_name' => $place->canonicalPlaceName,
                'latitude' => $place->latitude,
                'longitude' => $place->longitude,
            ]);
        } catch (TripLimitReachedException $e) {
            // Free-tier cap (AD-15): calm refusal, no Trip created, no upsell.
            return back()->withInput()->withErrors(['destination' => $e->getMessage()]);
        }

        return redirect()->route('trips.added', $trip);
    }

    /**
     * The shared, dated add-trip success screen (Story 3.2) — also the landing
     * for a new user's first email confirmation (MagicLinkController@consume).
     */
    public function added(Trip $trip, CadencePredicate $cadence): Response
    {
        $this->authorize('view', $trip);

        $today = Carbon::now('America/New_York');
        $firstForecast = $cadence->firstSendDate($trip, $today);

        return Inertia::render('TripAdded', [
            'destination' => $trip->canonical_place_name !== '' ? $trip->canonical_place_name : $trip->destination_raw,
            'firstForecastDate' => $firstForecast->toDateString(),
            // Whole ET calendar days from today to the first send (0 = today, 1 =
            // tomorrow) — computed server-side so "tomorrow" can't drift on the
            // client's timezone (AD-7).
            'firstForecastInDays' => (int) Carbon::parse($today->toDateString())->diffInDays($firstForecast, false),
        ]);
    }

    /**
     * Pause a trip — stops digests until resumed (AD-5).
     */
    public function pause(Trip $trip): RedirectResponse
    {
        $this->authorize('update', $trip);

        $this->transition($trip, Trip::STATUS_PAUSED);

        return back()->with('status', 'Paused — no emails until you resume.');
    }

    /**
     * Resume a paused trip — digests restart on cadence (AD-5).
     */
    public function resume(Trip $trip): RedirectResponse
    {
        $this->authorize('update', $trip);

        $this->transition($trip, Trip::STATUS_ACTIVE);

        return back()->with('status', 'Your tripcast is active again.');
    }

    /**
     * Soft-delete a trip (AD-5): it leaves cadence and the dashboard, but its
     * email_logs/feedback survive (AD-9 audit trail). Never a hard delete.
     */
    public function destroy(Trip $trip): RedirectResponse
    {
        $this->authorize('delete', $trip);

        $trip->delete();

        return back()->with('status', 'That tripcast has ended.');
    }

    /**
     * Apply a status transition, tolerating the terminal-state guard so a crafted
     * request against a completed trip can't 500 (AD-5: completed is terminal).
     */
    private function transition(Trip $trip, string $status): void
    {
        try {
            $trip->transitionTo($status);
        } catch (InvalidTripTransitionException) {
            // No-op: the UI never offers pause/resume on a completed trip; a
            // crafted request is silently ignored rather than erroring.
        }
    }
}
