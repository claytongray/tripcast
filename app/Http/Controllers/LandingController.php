<?php

namespace App\Http\Controllers;

use App\Http\Requests\TripSetupRequest;
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
     * Validate the trip-setup form and stash it in the session (no DB writes).
     */
    public function store(TripSetupRequest $request): RedirectResponse
    {
        $request->session()->put('pending_trip', $request->validated());

        return redirect()->route('trip.detail');
    }

    /**
     * Placeholder next step. Story 1.3 replaces this with the geocoding
     * confirm step ("Finding that place…"). Without a pending trip in the
     * session, send the visitor back to the landing form.
     */
    public function tripDetail(Request $request): Response|RedirectResponse
    {
        if (! $request->session()->has('pending_trip')) {
            return redirect()->route('home');
        }

        return Inertia::render('TripDetailPlaceholder', [
            'pendingTrip' => $request->session()->get('pending_trip'),
        ]);
    }
}
