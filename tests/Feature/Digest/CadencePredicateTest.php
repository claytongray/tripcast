<?php

use App\Digest\CadencePredicate;
use App\Models\Trip;
use App\Models\User;
use Illuminate\Support\Carbon;

beforeEach(function () {
    // Pin "today" to a fixed America/New_York date (AD-7).
    Carbon::setTestNow(Carbon::parse('2026-06-29 12:00:00', 'America/New_York'));
});

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * @param  array<string, mixed>  $tripOverrides
 */
function makeTrip(array $tripOverrides = [], bool $confirmed = true, bool $optedOut = false): Trip
{
    $factory = User::factory();
    $factory = $confirmed ? $factory->confirmed() : $factory;
    $factory = $optedOut ? $factory->optedOut() : $factory;
    $user = $factory->create();

    return $user->trips()->create(array_merge([
        'destination_raw' => 'Edinburgh',
        'canonical_place_name' => 'Edinburgh, United Kingdom',
        'latitude' => 55.9533,
        'longitude' => -3.1883,
        'departure_date' => '2026-07-03', // window opens 2026-06-26 (≤ today)
        'return_date' => '2026-07-10',     // ≥ today
        'status' => Trip::STATUS_ACTIVE,
    ], $tripOverrides));
}

function nowEt(): Carbon
{
    return Carbon::now('America/New_York');
}

function predicate(): CadencePredicate
{
    return new CadencePredicate;
}

// AC1 — the happy path: active, confirmed, not opted out, in window → due.
it('is due for an active, confirmed, in-window trip', function () {
    expect(predicate()->isDue(makeTrip(), nowEt()))->toBeTrue();
});

// AC1/AC3 — status, confirmation, and opt-out exclusions.
it('is not due when paused', function () {
    expect(predicate()->isDue(makeTrip(['status' => Trip::STATUS_PAUSED]), nowEt()))->toBeFalse();
});

it('is not due when completed', function () {
    expect(predicate()->isDue(makeTrip(['status' => Trip::STATUS_COMPLETED]), nowEt()))->toBeFalse();
});

it('is not due when soft-deleted', function () {
    $trip = makeTrip();
    $trip->delete();
    expect(predicate()->isDue($trip, nowEt()))->toBeFalse();
});

it('is not due when the owner is unconfirmed', function () {
    expect(predicate()->isDue(makeTrip(confirmed: false), nowEt()))->toBeFalse();
});

it('is not due when the owner is opted out', function () {
    expect(predicate()->isDue(makeTrip(optedOut: true), nowEt()))->toBeFalse();
});

// AC1 — window boundaries (today = 2026-06-29).
it('is due on the exact window-open boundary (departure − 7)', function () {
    // departure 2026-07-06 → window opens 2026-06-29 = today
    expect(predicate()->isDue(makeTrip(['departure_date' => '2026-07-06', 'return_date' => '2026-07-13']), nowEt()))->toBeTrue();
});

it('is due on the exact return-date boundary', function () {
    expect(predicate()->isDue(makeTrip(['departure_date' => '2026-06-22', 'return_date' => '2026-06-29']), nowEt()))->toBeTrue();
});

it('is not due the day after return', function () {
    expect(predicate()->isDue(makeTrip(['departure_date' => '2026-06-21', 'return_date' => '2026-06-28']), nowEt()))->toBeFalse();
});

it('is not due more than 7 days before departure', function () {
    // departure 2026-07-07 → window opens 2026-06-30 > today
    expect(predicate()->isDue(makeTrip(['departure_date' => '2026-07-07', 'return_date' => '2026-07-14']), nowEt()))->toBeFalse();
});

// AC2 — the dueOn() selector returns exactly the due trips and agrees with isDue.
it('selects exactly the due trips via dueOn', function () {
    $due1 = makeTrip();
    $due2 = makeTrip(['departure_date' => '2026-07-06', 'return_date' => '2026-07-13']); // boundary due
    makeTrip(['status' => Trip::STATUS_PAUSED]);                 // not due
    makeTrip(confirmed: false);                                  // not due
    makeTrip(optedOut: true);                                    // not due
    makeTrip(['departure_date' => '2026-07-07', 'return_date' => '2026-07-14']); // out of window
    $deleted = makeTrip();
    $deleted->delete();                                          // not due

    $ids = predicate()->dueOn(nowEt())->pluck('id')->sort()->values()->all();

    expect($ids)->toBe([$due1->id, $due2->id]);
});

// The send window opens `forecast.horizon_days` before departure — bumping the
// horizon (a better API) widens it, for both isDue and the dueOn selector.
it('opens the send window by the configured forecast horizon', function () {
    config(['tripcast.forecast.horizon_days' => 14]);

    // departure 2026-07-12 → window opens 2026-06-28 (≤ today) only with a 14-day horizon.
    $trip = makeTrip(['departure_date' => '2026-07-12', 'return_date' => '2026-07-19']);

    expect(predicate()->isDue($trip, nowEt()))->toBeTrue()
        ->and(predicate()->dueOn(nowEt())->pluck('id')->all())->toContain($trip->id);
});
