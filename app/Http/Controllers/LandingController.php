<?php

namespace App\Http\Controllers;

use App\Http\Requests\TripSetupRequest;
use App\Services\Geocoding\Geocoder;
use App\Services\Geocoding\GeocodingFailedException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
     * Show the landing hero.
     */
    public function show(): Response
    {
        return Inertia::render('Landing');
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
     * Name back for confirmation (AD-8). Without a pending trip in the session,
     * send the visitor back to the landing form. (Email capture is Story 1.4.)
     */
    public function tripDetail(Request $request): Response|RedirectResponse
    {
        if (! $request->session()->has('pending_trip')) {
            return redirect()->route('home');
        }

        return Inertia::render('TripDetail', [
            'pendingTrip' => $request->session()->get('pending_trip'),
        ]);
    }
}
