<?php

namespace App\Http\Controllers;

use App\Actions\CreateTrip;
use App\Actions\RequestMagicLink;
use App\Http\Requests\EmailCaptureRequest;
use App\Http\Requests\TripSetupRequest;
use App\Services\Geocoding\Geocoder;
use App\Services\Geocoding\GeocodingFailedException;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The public landing hero + inline trip-setup form (FR-1).
 *
 * Thin controller: validate and stash trip details in the server session
 * (AD-10) for the later geocoding/email steps. Nothing is persisted here —
 * the account + trip are created atomically in Story 1.4.
 */
class LandingController extends Controller
{
    /**
     * Show the landing hero, seeded with any in-progress trip so "Edit
     * destination" returns the visitor to a pre-filled form (FR-1, UX-DR3).
     */
    public function show(Request $request): Response
    {
        return Inertia::render('Landing', [
            'pendingTrip' => $request->session()->get('pending_trip'),
        ]);
    }

    /**
     * Validate, geocode the destination once (AD-8, outside any DB transaction),
     * stash the resolved trip in the session (AD-10), and advance. No DB writes.
     */
    public function store(TripSetupRequest $request, Geocoder $geocoder): RedirectResponse
    {
        $validated = $request->validated();

        try {
            $place = $geocoder->geocode($validated['destination']);
        } catch (GeocodingFailedException) {
            return back()->withInput()->withErrors([
                'destination' => "We couldn't find that place. Try a city and country — like 'Edinburgh, UK'.",
            ]);
        }

        $request->session()->put('pending_trip', [
            ...$validated,
            'canonical_place_name' => $place->canonicalPlaceName,
            'latitude' => $place->latitude,
            'longitude' => $place->longitude,
        ]);

        return redirect()->route('trip.detail');
    }

    /**
     * The trip-detail passive-confirm step: show the resolved Canonical Place
     * Name back for confirmation (AD-8). Without a complete pending trip in the
     * session, send the visitor back to the landing form. (Email capture is Story 1.4.)
     */
    public function tripDetail(Request $request): Response|RedirectResponse
    {
        $pending = $request->session()->get('pending_trip');

        if (! $this->pendingTripIsComplete($pending)) {
            return redirect()->route('home');
        }

        return Inertia::render('TripDetail', [
            'pendingTrip' => $pending,
            'forecastStartLabel' => $this->forecastStartLabel($pending['departure_date']),
        ]);
    }

    /**
     * Email capture: atomically create the account + trip (AD-10), send the
     * magic link (Story 1.1), and show the check-your-email interstitial.
     */
    public function createTrip(
        EmailCaptureRequest $request,
        CreateTrip $createTrip,
        RequestMagicLink $requestMagicLink,
    ): RedirectResponse {
        $pending = $request->session()->get('pending_trip');

        // No orphan trips: a complete, geocoded pending trip must exist (AD-8, AD-10).
        if (! $this->pendingTripIsComplete($pending)) {
            return redirect()->route('home');
        }

        // The session may have sat long enough for the departure to pass (AD-7).
        if ($this->departureHasPassed($pending['departure_date'])) {
            $request->session()->forget('pending_trip');

            return redirect()->route('home');
        }

        $email = $request->validated()['email'];

        // DB-only atomic create — no external calls inside (AD-10).
        try {
            $createTrip->handle($email, $pending);
        } catch (QueryException) {
            return back()->withErrors([
                'email' => 'Something went wrong saving your trip. Please try again.',
            ]);
        }

        // Clear the session as soon as the durable trip is committed — before the
        // best-effort, throttled magic-link send — so a failure there can't leave a
        // live session that re-creates the trip on resubmit.
        $request->session()->forget('pending_trip');

        // After commit: send the magic link (auth, always sent). The one-time
        // Welcome Email is queued inside CreateTrip (FR-9), honoring opt-out.
        $result = $requestMagicLink->handle($email);

        return redirect()->route('login.sent')->with([
            'magic_email' => $result['user']->email,
            'magic_ttl' => $result['ttl_minutes'],
        ]);
    }

    /**
     * Whether the session holds a fully resolved (geocoded) pending trip.
     *
     * @param  mixed  $pending
     */
    private function pendingTripIsComplete($pending): bool
    {
        return is_array($pending) && isset(
            $pending['destination'],
            $pending['departure_date'],
            $pending['return_date'],
            $pending['canonical_place_name'],
            $pending['latitude'],
            $pending['longitude'],
        );
    }

    /**
     * Whether a naive departure date is before today on the send clock (AD-7).
     */
    private function departureHasPassed(string $departureDate): bool
    {
        return Carbon::parse($departureDate, 'America/New_York')->startOfDay()
            ->lessThan(Carbon::now('America/New_York')->startOfDay());
    }

    /**
     * Human label for when the daily digest begins — the Forecast Window opens
     * up to 7 days before departure (AD-11), measured on the send clock (AD-7).
     */
    private function forecastStartLabel(string $departureDate): string
    {
        $departure = Carbon::parse($departureDate, 'America/New_York')->startOfDay();
        $today = Carbon::now('America/New_York')->startOfDay();
        $window = min(7, max(0, (int) $today->diffInDays($departure, false)));

        return match (true) {
            $window <= 0 => 'the morning you leave',
            $window === 1 => 'the day before you go',
            default => "{$window} days before you go",
        };
    }
}
