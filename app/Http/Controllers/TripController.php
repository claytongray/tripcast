<?php

namespace App\Http\Controllers;

use App\Models\InvalidTripTransitionException;
use App\Models\Trip;
use App\Policies\TripPolicy;
use Illuminate\Http\RedirectResponse;

/**
 * Trip status management from the dashboard (FR-12). Every status change routes
 * through the single state-transition method on {@see Trip} (AD-5) — this
 * controller never writes `status` directly. Ownership is enforced by
 * {@see TripPolicy}; non-owners get 403.
 */
class TripController extends Controller
{
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

        return back()->with('status', "We're watching again.");
    }

    /**
     * Soft-delete a trip (AD-5): it leaves cadence and the dashboard, but its
     * email_logs/feedback survive (AD-9 audit trail). Never a hard delete.
     */
    public function destroy(Trip $trip): RedirectResponse
    {
        $this->authorize('delete', $trip);

        $trip->delete();

        return back()->with('status', "We've stopped watching that trip.");
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
