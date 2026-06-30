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

// Story 3.2 — firstSendDate: the dated "first forecast" authority (today is 2026-06-29).
it('firstSendDate returns the window open when departure is beyond the horizon', function () {
    $trip = makeTrip(['departure_date' => '2026-07-14', 'return_date' => '2026-07-21']);

    // window opens 2026-07-07 (departure − 7), which is after today.
    expect(predicate()->firstSendDate($trip, nowEt())->toDateString())->toBe('2026-07-07');
});

it('firstSendDate returns today when the window is already open', function () {
    $trip = makeTrip(['departure_date' => '2026-07-02', 'return_date' => '2026-07-09']);

    // window opened 2026-06-25 (before today) → first send is today.
    expect(predicate()->firstSendDate($trip, nowEt())->toDateString())->toBe('2026-06-29');
});

// Spec B — nextSendDate: the next calendar date a trip's digest will send, on the
// 09:00 ET send boundary (today is 2026-06-29; beforeEach pins now to 12:00 ET).
it('nextSendDate returns tomorrow when in window and now is past the 9am send', function () {
    // Default trip is in window; pinned now (12:00) is after 09:00, so today's
    // send has passed — the next is tomorrow.
    expect(predicate()->nextSendDate(makeTrip(), nowEt())->toDateString())->toBe('2026-06-30');
});

it('nextSendDate returns today when in window and now is before the 9am send', function () {
    Carbon::setTestNow(Carbon::parse('2026-06-29 08:00:00', 'America/New_York'));

    expect(predicate()->nextSendDate(makeTrip(), nowEt())->toDateString())->toBe('2026-06-29');
});

it('nextSendDate returns the window open when departure is beyond the horizon', function () {
    $trip = makeTrip(['departure_date' => '2026-09-01', 'return_date' => '2026-09-08']);

    // window opens 2026-08-25 (departure − 7), far after today.
    expect(predicate()->nextSendDate($trip, nowEt())->toDateString())->toBe('2026-08-25');
});

it('nextSendDate is null once the trip is past its return date', function () {
    $trip = makeTrip(['departure_date' => '2026-06-20', 'return_date' => '2026-06-28']);

    expect(predicate()->nextSendDate($trip, nowEt()))->toBeNull();
});

it('nextSendDate is null when paused', function () {
    expect(predicate()->nextSendDate(makeTrip(['status' => Trip::STATUS_PAUSED]), nowEt()))->toBeNull();
});

it('nextSendDate is null when completed', function () {
    expect(predicate()->nextSendDate(makeTrip(['status' => Trip::STATUS_COMPLETED]), nowEt()))->toBeNull();
});

it('nextSendDate is null when soft-deleted', function () {
    $trip = makeTrip();
    $trip->delete();

    expect(predicate()->nextSendDate($trip, nowEt()))->toBeNull();
});

it('nextSendDate is null when the owner is unconfirmed', function () {
    expect(predicate()->nextSendDate(makeTrip(confirmed: false), nowEt()))->toBeNull();
});

it('nextSendDate is null when the owner has opted out', function () {
    expect(predicate()->nextSendDate(makeTrip(optedOut: true), nowEt()))->toBeNull();
});
