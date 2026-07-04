<?php

namespace App\Http\Controllers;

use App\Actions\CreateTrip;
use App\Actions\RequestMagicLink;
use App\Actions\TripLimitReachedException;
use App\Http\Controllers\Concerns\ThrottlesMagicLink;
use App\Http\Requests\EmailCaptureRequest;
use App\Http\Requests\TripSetupRequest;
use App\Services\Analytics\KeyEvent;
use App\Services\Geocoding\Geocoder;
use App\Services\Geocoding\GeocodeResult;
use App\Services\Geocoding\GeocodingFailedException;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
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
    use ThrottlesMagicLink;

    /**
     * Show the landing hero, seeded with any in-progress trip so "Edit
     * destination" returns the visitor to a pre-filled form (FR-1, UX-DR3).
     */
    public function show(Request $request): Response|RedirectResponse
    {
        // A returning, logged-in user belongs on their trip dashboard, not the
        // new-user setup form. Guests fall through to the landing hero.
        if ($request->user() !== null) {
            return redirect()->route('dashboard');
        }

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
            $place = $this->resolveDestination($geocoder, $validated);
        } catch (GeocodingFailedException) {
            return back()->withInput()->withErrors([
                'destination' => "We couldn't find that place. Try a city and country — like 'Edinburgh, UK'.",
            ]);
        }

        // place_id/session_token ride along in the spread — harmless; the
        // pending-trip readers pick explicit keys (AD-10).
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

        // A signed-in visitor submitting their own email (e.g. the FR-30
        // recovery path: sign in from the at-cap error, free a slot, retry the
        // kept pending trip) is an authenticated add, not a signup — no magic
        // link, no interstitial. A mismatched email keeps the guest flow: the
        // submitted address owns the trip and still proves itself via the link.
        $ownsAccount = $request->user() !== null
            && Str::lower(trim($email)) === Str::lower(trim($request->user()->email));

        // DB-only atomic create — no external calls inside (AD-10).
        try {
            $trip = $createTrip->handle($email, $pending);
        } catch (TripLimitReachedException $e) {
            // A signed-in owner at the cap can reach pause/remove on their
            // dashboard, so the default message is actionable as-is — no link.
            if ($ownsAccount) {
                return back()->withErrors(['email' => $e->getMessage()]);
            }

            // Free-tier cap (FR-30, AD-15): the address already holds an at-cap
            // account, possibly one the visitor can't get into (unconfirmed
            // accounts hold slots). Email a sign-in link so "manage your trips"
            // is actionable, and keep the pending trip for a retry after they
            // free a slot. The shared magic-link buckets guard the send; a
            // throttle throw surfaces its own "Too many requests" email error.
            $this->ensureNotThrottled($request, $email);

            $magicLinkPending = $request->session()->get('magic_link_pending');
            $pendingToken = is_array($magicLinkPending) ? ($magicLinkPending['token'] ?? null) : null;

            // Reuse a still-valid same-browser link (never rotate a link that
            // may still arrive); preserve its intent so the /login resend keeps
            // the right copy. A fresh issue on this surface is a login.
            $result = $requestMagicLink->resendOrIssue($email, $pendingToken);
            $intent = ($result['reused'] && is_array($magicLinkPending)) ? ($magicLinkPending['intent'] ?? 'login') : 'login';

            $request->session()->put('magic_link_pending', ['token' => $result['token'], 'intent' => $intent]);

            return back()->withErrors([
                'email' => "You're at your plan's trip limit — we emailed you a sign-in link. Use it to manage your trips, then add this one.",
            ]);
        } catch (QueryException) {
            return back()->withErrors([
                'email' => 'Something went wrong saving your trip. Please try again.',
            ]);
        }

        // Clear the session as soon as the durable trip is committed — before the
        // best-effort, throttled magic-link send — so a failure there can't leave a
        // live session that re-creates the trip on resubmit.
        $request->session()->forget('pending_trip');

        // Trip committed — fire the conversion for both the signed-in owner
        // (lands on trips.added) and the guest signup (lands on login.sent).
        KeyEvent::flash(KeyEvent::TRIP_CREATED, ['source' => 'landing']);

        // A signed-in owner is already authenticated: land on the dated success
        // screen (the same one a just-confirmed signup gets), skipping the link.
        if ($ownsAccount) {
            return redirect()->route('trips.added', $trip);
        }

        // After commit: send the magic link (auth, always sent). The one-time
        // Welcome Email is queued inside CreateTrip (FR-9), honoring opt-out.
        $result = $requestMagicLink->handle($email);

        // Stash the link so a resend from the interstitial reuses this activation
        // link (a delayed first email isn't invalidated) and keeps the signup copy.
        $request->session()->put('magic_link_pending', ['token' => $result['token'], 'intent' => 'signup']);

        return redirect()->route('login.sent')->with([
            'magic_email' => $result['user']->email,
            'magic_ttl' => $result['ttl_minutes'],
            'magic_intent' => 'signup',
        ]);
    }

    /**
     * Resolve the destination once (AD-8): an autocomplete place id resolves
     * exactly when present (FR-22), falling back to free-text geocoding when
     * it is absent or stale — still one resolution per creation.
     *
     * @param  array<string, mixed>  $validated
     *
     * @throws GeocodingFailedException when neither path resolves.
     */
    private function resolveDestination(Geocoder $geocoder, array $validated): GeocodeResult
    {
        $placeId = $validated['place_id'] ?? null;

        if (is_string($placeId) && $placeId !== '') {
            try {
                return $geocoder->resolvePlace($placeId, $validated['session_token'] ?? null);
            } catch (GeocodingFailedException) {
                // Stale/foreign id — fall through to the text path.
            }
        }

        return $geocoder->geocode($validated['destination']);
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
