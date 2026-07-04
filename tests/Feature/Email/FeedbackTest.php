<?php

use App\Models\Feedback;
use App\Models\Trip;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia as Assert;

function feedbackTrip(): Trip
{
    return User::factory()->confirmed()->create()->trips()->create([
        'destination_raw' => 'Edinburgh',
        'canonical_place_name' => 'Edinburgh, United Kingdom',
        'latitude' => 55.9533,
        'longitude' => -3.1883,
        'departure_date' => '2026-07-03',
        'return_date' => '2026-07-10',
        'status' => Trip::STATUS_ACTIVE,
    ]);
}

// Mirror production (DigestMail): absolute link, relative signature. The routes
// validate signed:relative, so an absolute signature would be rejected here too.
function feedbackUrl(Trip $trip, string $reaction, string $sendDate = '2026-06-29'): string
{
    return url(URL::signedRoute('email.trip.feedback', [
        'trip' => $trip->id,
        'reaction' => $reaction,
        'send_date' => $sendDate,
    ], absolute: false));
}

// Regression — MailerSend click-tracking wraps every body link and, on click,
// 302-redirects to the destination with the scheme rewritten (observed: the
// https link comes back as http). An absolute signature covers the scheme, so
// the rewrite invalidated it → 403. Relative signatures ignore scheme + host,
// which MailerSend leaves intact; path + query (send_date, signature) survive.
it('accepts a feedback link after the email tracker rewrites its scheme', function () {
    $trip = feedbackTrip();

    $signed = feedbackUrl($trip, 'helped');
    $rewritten = str_starts_with($signed, 'https://')
        ? 'http://'.substr($signed, strlen('https://'))
        : 'https://'.substr($signed, strlen('http://'));

    $this->get($rewritten)->assertOk();

    $this->post($rewritten)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('email/FeedbackResult'));

    expect(Feedback::where('trip_id', $trip->id)->where('send_date', '2026-06-29')->exists())->toBeTrue();
});

// AC1 — the signed GET only confirms; it writes nothing.
it('renders the feedback confirm page on a signed GET without writing', function () {
    $trip = feedbackTrip();

    $this->get(feedbackUrl($trip, 'helped'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('email/FeedbackConfirm')
            ->where('reaction', 'helped')
            ->where('reactionLabel', 'helpful')
            ->where('place', 'Edinburgh'));

    expect(Feedback::count())->toBe(0);
});

// AC1 — an unsigned / tampered link is rejected.
it('rejects an unsigned feedback GET and POST', function () {
    $trip = feedbackTrip();

    $this->get("/email/trip/{$trip->id}/feedback/helped?send_date=2026-06-29")->assertForbidden();
    $this->post("/email/trip/{$trip->id}/feedback/helped?send_date=2026-06-29")->assertForbidden();

    expect(Feedback::count())->toBe(0);
});

// AC2/AC3 — the POST records the reaction and shows the calm confirmation.
it('records a feedback row on a signed POST and confirms calmly', function () {
    $trip = feedbackTrip();

    $this->post(feedbackUrl($trip, 'helped'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('email/FeedbackResult'));

    $row = Feedback::where('trip_id', $trip->id)->where('send_date', '2026-06-29')->first();
    expect($row)->not->toBeNull()
        ->and($row->reaction)->toBe(Feedback::REACTION_HELPED);
});

// AC2 — re-tap is last-reaction-wins: one row, reaction flipped.
it('upserts last-reaction-wins on a re-tap', function () {
    $trip = feedbackTrip();

    $this->post(feedbackUrl($trip, 'helped'))->assertOk();
    $this->post(feedbackUrl($trip, 'not_helpful'))->assertOk();
    $this->post(feedbackUrl($trip, 'not_helpful'))->assertOk(); // same reaction again

    expect(Feedback::where('trip_id', $trip->id)->count())->toBe(1)
        ->and(Feedback::first()->reaction)->toBe(Feedback::REACTION_NOT_HELPFUL);
});

// AC1 — an out-of-range reaction is not routable.
it('rejects an unknown reaction value', function () {
    $trip = feedbackTrip();

    // Even with a (would-be) valid signature the route constraint refuses it → 404.
    $url = URL::signedRoute('email.trip.feedback', ['trip' => $trip->id, 'reaction' => 'maybe', 'send_date' => '2026-06-29']);
    $this->get($url)->assertNotFound();
});

// AC2 — the unique (trip_id, send_date) index is enforced at the DB.
it('enforces a unique trip_id and send_date', function () {
    $trip = feedbackTrip();
    $trip->feedback()->create(['send_date' => '2026-06-29', 'reaction' => 'helped']);
    $trip->feedback()->create(['send_date' => '2026-06-29', 'reaction' => 'not_helpful']);
})->throws(UniqueConstraintViolationException::class);
