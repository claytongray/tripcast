<?php

namespace App\Http\Controllers;

use App\Models\Feedback;
use App\Models\Trip;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * Login-free email footer actions (FR-5, AD-5, AD-6, AD-13). Every route is a
 * Laravel **signed** URL scoped to the trip/user id. A signed GET only renders a
 * confirmation page; the state change happens on the POST from that page —
 * because mail clients (Gmail/Outlook/Apple) prefetch and link-scan GETs and
 * would otherwise auto-fire the action. The `List-Unsubscribe-Post` one-click
 * POST is the sole direct POST (from the mail client): signed, CSRF-exempt,
 * idempotent.
 */
class EmailAction extends Controller
{
    /**
     * GET — "End this trip" confirmation page (mutates nothing).
     */
    public function confirmEnd(Request $request, Trip $trip): InertiaResponse
    {
        return Inertia::render('email/EndTripConfirm', [
            'place' => $this->placeShort($trip),
            'postUrl' => $request->fullUrl(), // same signed URL; the POST reuses the signature
        ]);
    }

    /**
     * POST — complete the one trip via the single transition method (AD-5).
     * Idempotent: an already-completed trip stays completed.
     */
    public function end(Trip $trip): InertiaResponse
    {
        $trip->complete();

        return Inertia::render('email/EndTripResult', [
            'place' => $this->placeShort($trip),
        ]);
    }

    /**
     * GET — "Unsubscribe" confirmation page (mutates nothing).
     */
    public function confirmUnsubscribe(Request $request, User $user): InertiaResponse
    {
        return Inertia::render('email/UnsubscribeConfirm', [
            'postUrl' => $request->fullUrl(),
        ]);
    }

    /**
     * POST — set the account-level opt-out (AD-13), excluding all the user's
     * trips from cadence (AD-11). Idempotent.
     */
    public function unsubscribe(User $user): InertiaResponse
    {
        $user->optOut();

        return Inertia::render('email/UnsubscribeResult');
    }

    /**
     * POST — the `List-Unsubscribe-Post: List-Unsubscribe=One-Click` target hit
     * directly by the mail client (no human, no confirmation, no CSRF token).
     * Signed + CSRF-exempt + idempotent; returns a bare 200 (RFC 8058).
     */
    public function unsubscribeOneClick(User $user): Response
    {
        $user->optOut();

        return response('You have been unsubscribed.', 200)
            ->header('Content-Type', 'text/plain');
    }

    /**
     * GET — feedback confirmation page (mutates nothing). The reaction is a
     * route segment (constrained to helped|not_helpful); send_date rides the
     * signed query string.
     */
    public function confirmFeedback(Request $request, Trip $trip, string $reaction): InertiaResponse
    {
        return Inertia::render('email/FeedbackConfirm', [
            'place' => $this->placeShort($trip),
            'reaction' => $reaction,
            'reactionLabel' => $reaction === Feedback::REACTION_HELPED ? 'helpful' : 'not helpful',
            'postUrl' => $request->fullUrl(), // same signed URL; the POST reuses the signature
        ]);
    }

    /**
     * POST — record the reaction against (trip, send_date), last-reaction-wins
     * (AD-9). Idempotent: the unique index makes a re-tap an update, never a
     * duplicate. send_date comes from the signed query string (tamper-proof).
     */
    public function feedback(Request $request, Trip $trip, string $reaction): InertiaResponse
    {
        $validated = $request->validate([
            'send_date' => ['required', 'date_format:Y-m-d'],
        ]);

        Feedback::updateOrCreate(
            ['trip_id' => $trip->id, 'send_date' => $validated['send_date']],
            ['reaction' => $reaction],
        );

        return Inertia::render('email/FeedbackResult');
    }

    /**
     * City portion of the canonical name (text before the first comma) — the
     * same short-place convention used across the digest + welcome mail.
     */
    private function placeShort(Trip $trip): string
    {
        return Str::of($trip->canonical_place_name)->before(',')->trim()->value();
    }
}
