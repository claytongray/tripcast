<?php

use App\Actions\CreateTrip;
use App\Actions\TripLimitReachedException;
use App\Models\Trip;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-06-30 09:00', 'America/New_York'));
    Mail::fake();
});

afterEach(function () {
    Carbon::setTestNow();
});

function capTripDetails(array $overrides = []): array
{
    return array_merge([
        'destination' => 'Edinburgh',
        'canonical_place_name' => 'Edinburgh, United Kingdom',
        'latitude' => 55.9533,
        'longitude' => -3.1883,
        'departure_date' => '2026-07-14',
        'return_date' => '2026-07-21',
    ], $overrides);
}

it('refuses an add beyond the active-trip cap and creates nothing', function () {
    $user = User::factory()->confirmed()->create();
    Trip::factory()->count(3)->for($user)->create(); // 3 active = at the default cap

    expect(fn () => app(CreateTrip::class)->handle($user->email, capTripDetails()))
        ->toThrow(TripLimitReachedException::class);

    expect($user->trips()->count())->toBe(3);
    Mail::assertNothingQueued();
});

it('does not count paused, completed, or soft-deleted trips toward the cap', function () {
    config(['tripcast.free_tier.max_active_trips' => 1]);
    $user = User::factory()->confirmed()->create();

    Trip::factory()->for($user)->paused()->create();
    Trip::factory()->for($user)->completed()->create();
    Trip::factory()->for($user)->create()->delete(); // soft-deleted

    // None occupy a slot, so a first active add still succeeds.
    $trip = app(CreateTrip::class)->handle($user->email, capTripDetails());

    expect($trip->status)->toBe(Trip::STATUS_ACTIVE)
        ->and($user->trips()->where('status', Trip::STATUS_ACTIVE)->count())->toBe(1);
});

it('honors a configurable limit', function () {
    config(['tripcast.free_tier.max_active_trips' => 1]);
    $user = User::factory()->confirmed()->create();
    Trip::factory()->for($user)->create(); // 1 active = at cap

    expect(fn () => app(CreateTrip::class)->handle($user->email, capTripDetails()))
        ->toThrow(TripLimitReachedException::class);
});

it('always lets a brand-new user create their first trip', function () {
    $trip = app(CreateTrip::class)->handle('newcomer@example.com', capTripDetails());

    expect($trip->status)->toBe(Trip::STATUS_ACTIVE)
        ->and(User::where('email', 'newcomer@example.com')->exists())->toBeTrue();
});

it('refuses an over-cap dashboard add with a calm error and no welcome', function () {
    $user = User::factory()->confirmed()->create();
    Trip::factory()->count(3)->for($user)->create();

    $this->actingAs($user)
        ->post(route('trips.store'), [
            'destination' => 'Edinburgh',
            'departure_date' => '2026-07-14',
            'return_date' => '2026-07-21',
        ])
        ->assertRedirect()
        ->assertSessionHasErrors('destination');

    expect($user->trips()->count())->toBe(3);
    Mail::assertNothingQueued();
});
