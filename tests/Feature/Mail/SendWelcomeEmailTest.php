<?php

use App\Actions\SendWelcomeEmail;
use App\Jobs\SendTripDigest;
use App\Mail\WelcomeMail;
use App\Models\Trip;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-06-29 09:05:00', 'America/New_York'));
});

afterEach(function () {
    Carbon::setTestNow();
});

function welcomeTrip(string $departure, string $return): Trip
{
    return User::factory()->confirmed()->create()->trips()->create([
        'destination_raw' => 'Edinburgh',
        'canonical_place_name' => 'Edinburgh, United Kingdom',
        'latitude' => 55.9533, 'longitude' => -3.1883,
        'departure_date' => $departure, 'return_date' => $return,
        'status' => Trip::STATUS_ACTIVE,
    ]);
}

it('dispatches a welcome-mode tripcast for an in-window trip', function () {
    Bus::fake();
    Mail::fake();
    // horizon 7; today 2026-06-29 ET → window opens 2026-06-26 for a 2026-07-03 departure.
    $trip = welcomeTrip('2026-07-03', '2026-07-10');

    app(SendWelcomeEmail::class)->handle($trip);

    Bus::assertDispatched(SendTripDigest::class, fn (SendTripDigest $j) => $j->trip->is($trip)
        && $j->sendDate === '2026-06-29'
        && $j->welcome === true);
    Mail::assertNotQueued(WelcomeMail::class);
});

it('queues the heads-up welcome for an out-of-window trip', function () {
    Bus::fake();
    Mail::fake();
    // Departure far out: window opens 2026-08-24, today is 2026-06-29 → out of window.
    $trip = welcomeTrip('2026-08-31', '2026-09-07');

    app(SendWelcomeEmail::class)->handle($trip);

    Mail::assertQueued(WelcomeMail::class, fn (WelcomeMail $m) => $m->trip->is($trip));
    Bus::assertNotDispatched(SendTripDigest::class);
});

it('sends nothing when the owner has opted out', function () {
    Bus::fake();
    Mail::fake();
    $trip = welcomeTrip('2026-07-03', '2026-07-10');
    $trip->user->update(['email_opted_out' => true]);

    app(SendWelcomeEmail::class)->handle($trip);

    Bus::assertNotDispatched(SendTripDigest::class);
    Mail::assertNothingQueued();
});
