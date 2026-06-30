<?php

use App\Models\InvalidTripTransitionException;
use App\Models\Trip;
use App\Models\User;

function transitionTrip(string $status = Trip::STATUS_ACTIVE): Trip
{
    return User::factory()->confirmed()->create()->trips()->create([
        'destination_raw' => 'Edinburgh',
        'canonical_place_name' => 'Edinburgh, United Kingdom',
        'latitude' => 55.9533,
        'longitude' => -3.1883,
        'departure_date' => '2026-07-03',
        'return_date' => '2026-07-10',
        'status' => $status,
    ]);
}

it('completes an active trip', function () {
    $trip = transitionTrip(Trip::STATUS_ACTIVE);

    $trip->complete();

    expect($trip->fresh()->status)->toBe(Trip::STATUS_COMPLETED);
});

it('completes a paused trip', function () {
    $trip = transitionTrip(Trip::STATUS_PAUSED);

    $trip->complete();

    expect($trip->fresh()->status)->toBe(Trip::STATUS_COMPLETED);
});

it('is idempotent — completing a completed trip stays completed', function () {
    $trip = transitionTrip(Trip::STATUS_COMPLETED);

    $trip->complete();

    expect($trip->fresh()->status)->toBe(Trip::STATUS_COMPLETED);
});

it('allows active ⇄ paused (dashboard reuse)', function () {
    $trip = transitionTrip(Trip::STATUS_ACTIVE);

    $trip->transitionTo(Trip::STATUS_PAUSED);
    expect($trip->fresh()->status)->toBe(Trip::STATUS_PAUSED);

    $trip->transitionTo(Trip::STATUS_ACTIVE);
    expect($trip->fresh()->status)->toBe(Trip::STATUS_ACTIVE);
});

it('treats completed as terminal — no transition leaves it', function (string $target) {
    $trip = transitionTrip(Trip::STATUS_COMPLETED);

    expect(fn () => $trip->transitionTo($target))
        ->toThrow(InvalidTripTransitionException::class);
})->with([Trip::STATUS_ACTIVE, Trip::STATUS_PAUSED]);

it('rejects an unknown target status', function () {
    $trip = transitionTrip(Trip::STATUS_ACTIVE);

    expect(fn () => $trip->transitionTo('archived'))
        ->toThrow(InvalidTripTransitionException::class);
});
