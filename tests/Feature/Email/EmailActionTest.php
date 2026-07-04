<?php

use App\Digest\CadencePredicate;
use App\Models\Trip;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-06-29 09:00:00', 'America/New_York'));
    $this->today = Carbon::now('America/New_York');
});

afterEach(function () {
    Carbon::setTestNow();
});

function emailUser(): User
{
    return User::factory()->confirmed()->create();
}

// Absolute link, relative signature — mirrors DigestMail. The email-action routes
// validate signed:relative (see routes/web.php), so an absolute signature 403s here
// exactly as it would in production after MailerSend rewrites the link's scheme.
function signedEmailAction(string $name, array $parameters): string
{
    return url(URL::signedRoute($name, $parameters, absolute: false));
}

function emailTrip(User $user): Trip
{
    return $user->trips()->create([
        'destination_raw' => 'Edinburgh',
        'canonical_place_name' => 'Edinburgh, United Kingdom',
        'latitude' => 55.9533,
        'longitude' => -3.1883,
        'departure_date' => '2026-07-03',
        'return_date' => '2026-07-10',
        'status' => Trip::STATUS_ACTIVE,
    ]);
}

// AC1 — the signed GET only confirms; it mutates nothing.
it('renders the end-trip confirm page on a signed GET without mutating', function () {
    $trip = emailTrip(emailUser());

    $this->get(signedEmailAction('email.trip.end', ['trip' => $trip->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('email/EndTripConfirm')->where('place', 'Edinburgh'));

    expect($trip->fresh()->status)->toBe(Trip::STATUS_ACTIVE);
});

// AC1 — an unsigned / tampered link is rejected.
it('rejects an unsigned end-trip GET and POST', function () {
    $trip = emailTrip(emailUser());

    $this->get("/email/trip/{$trip->id}/end")->assertForbidden();
    $this->post("/email/trip/{$trip->id}/end")->assertForbidden();

    expect($trip->fresh()->status)->toBe(Trip::STATUS_ACTIVE);
});

// AC2 — the POST completes the one trip via the transition method; it leaves cadence.
it('completes the trip on a signed POST and drops it from cadence', function () {
    $user = emailUser();
    $trip = emailTrip($user);
    $other = emailTrip($user); // a second trip must be untouched

    $cadence = app(CadencePredicate::class);
    expect($cadence->isDue($trip, $this->today))->toBeTrue();

    $this->post(signedEmailAction('email.trip.end.post', ['trip' => $trip->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('email/EndTripResult'));

    expect($trip->fresh()->status)->toBe(Trip::STATUS_COMPLETED)
        ->and($cadence->isDue($trip->fresh(), $this->today))->toBeFalse()
        ->and($other->fresh()->status)->toBe(Trip::STATUS_ACTIVE); // end-trip is single-trip
});

// AC2 — a re-POST is idempotent.
it('is idempotent on a repeated end-trip POST', function () {
    $trip = emailTrip(emailUser());
    $url = signedEmailAction('email.trip.end.post', ['trip' => $trip->id]);

    $this->post($url)->assertOk();
    $this->post($url)->assertOk();

    expect($trip->fresh()->status)->toBe(Trip::STATUS_COMPLETED);
});

// AC3 — unsubscribe is account-level: every trip drops from cadence, statuses unchanged.
it('opts the account out on a signed unsubscribe POST, excluding all trips', function () {
    $user = emailUser();
    $a = emailTrip($user);
    $b = emailTrip($user);

    $this->post(signedEmailAction('email.unsubscribe.post', ['user' => $user->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('email/UnsubscribeResult'));

    $cadence = app(CadencePredicate::class);
    expect($user->fresh()->email_opted_out)->toBeTrue()
        ->and($cadence->dueOn($this->today)->pluck('id'))->not->toContain($a->id)
        ->and($cadence->dueOn($this->today)->pluck('id'))->not->toContain($b->id)
        ->and($a->fresh()->status)->toBe(Trip::STATUS_ACTIVE) // unsubscribe ≠ end-trip
        ->and($b->fresh()->status)->toBe(Trip::STATUS_ACTIVE);
});

// AC3 — the GET confirm page mutates nothing.
it('renders the unsubscribe confirm page on a signed GET without mutating', function () {
    $user = emailUser();

    $this->get(signedEmailAction('email.unsubscribe', ['user' => $user->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('email/UnsubscribeConfirm'));

    expect($user->fresh()->email_opted_out)->toBeFalse();
});

// AC4 — the List-Unsubscribe-Post one-click target: signed POST → opt-out, idempotent.
it('opts out on the signed one-click POST and is idempotent', function () {
    $user = emailUser();
    $url = signedEmailAction('email.unsubscribe.one_click', ['user' => $user->id]);

    $this->post($url)->assertOk();
    expect($user->fresh()->email_opted_out)->toBeTrue();

    $this->post($url)->assertOk(); // idempotent
    expect($user->fresh()->email_opted_out)->toBeTrue();
});

// AC4 — the one-click target still requires a valid signature.
it('rejects an unsigned one-click POST', function () {
    $user = emailUser();

    $this->post("/email/user/{$user->id}/unsubscribe/one-click")->assertForbidden();

    expect($user->fresh()->email_opted_out)->toBeFalse();
});
