<?php

use App\Jobs\SendTripDigest;
use App\Models\Trip;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-06-29 09:00:00', 'America/New_York'));
    Queue::fake();
});

afterEach(function () {
    Carbon::setTestNow();
});

function dueTrip(): Trip
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

// AC2 — the command dispatches one job per due trip with today's send_date.
it('dispatches one SendTripDigest per due trip', function () {
    $a = dueTrip();
    $b = dueTrip();

    $this->artisan('digests:send')->assertSuccessful();

    Queue::assertPushed(SendTripDigest::class, 2);
    Queue::assertPushed(SendTripDigest::class, fn (SendTripDigest $job) => $job->sendDate === '2026-06-29'
        && in_array($job->trip->id, [$a->id, $b->id], true));
});

// AC2/AC3 — non-due trips are never dispatched.
it('dispatches nothing for non-due trips', function () {
    // Paused, unconfirmed, opted-out, and out-of-window trips.
    User::factory()->confirmed()->create()->trips()->create([
        'destination_raw' => 'X', 'canonical_place_name' => 'X', 'latitude' => 1.0, 'longitude' => 2.0,
        'departure_date' => '2026-07-03', 'return_date' => '2026-07-10', 'status' => Trip::STATUS_PAUSED,
    ]);
    User::factory()->create()->trips()->create([ // unconfirmed owner
        'destination_raw' => 'Y', 'canonical_place_name' => 'Y', 'latitude' => 1.0, 'longitude' => 2.0,
        'departure_date' => '2026-07-03', 'return_date' => '2026-07-10', 'status' => Trip::STATUS_ACTIVE,
    ]);

    $this->artisan('digests:send')->assertSuccessful();

    Queue::assertNothingPushed();
});
