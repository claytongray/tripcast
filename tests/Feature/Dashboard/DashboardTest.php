<?php

use App\Models\Trip;
use App\Models\User;
use Illuminate\Support\Carbon;

beforeEach(function () {
    // Pin the send clock (AD-7) so days-until-departure is deterministic.
    Carbon::setTestNow(Carbon::parse('2026-06-30 09:00', 'America/New_York'));
});

afterEach(function () {
    Carbon::setTestNow();
});

it('redirects guests to login', function () {
    $this->get(route('dashboard'))->assertRedirect(route('login'));
});

it('lists the owner trips grouped into upcoming and past with no weather or analytics', function () {
    $user = User::factory()->confirmed()->create();

    // Departure 2026-07-10 → 10 days from the pinned 2026-06-30.
    Trip::factory()->for($user)->create([
        'canonical_place_name' => 'Edinburgh, United Kingdom',
        'departure_date' => '2026-07-10',
        'return_date' => '2026-07-17',
    ]);
    Trip::factory()->for($user)->paused()->create();
    Trip::factory()->for($user)->completed()->past()->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Dashboard')
            ->has('upcomingTrips', 2)
            ->has('pastTrips', 1)
            ->where('upcomingTrips.0.destination', 'Edinburgh, United Kingdom')
            ->where('upcomingTrips.0.status', Trip::STATUS_ACTIVE)
            ->where('upcomingTrips.0.days_until_departure', 10)
            ->where('pastTrips.0.status', Trip::STATUS_COMPLETED)
            // No weather/analytics fields leak into the view-model.
            ->missing('upcomingTrips.0.forecast')
            ->missing('upcomingTrips.0.weather'));
});

it('exposes the active count and cap, and flags the at-limit state', function () {
    config(['tripcast.free_tier.max_active_trips' => 2]);
    $user = User::factory()->confirmed()->create();
    Trip::factory()->count(2)->for($user)->create(); // 2 active = at cap
    Trip::factory()->for($user)->paused()->create();  // doesn't occupy a slot

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('maxActiveTrips', 2)
            ->where('activeTripCount', 2));
});

it('shows empty groups for a user with no trips', function () {
    $user = User::factory()->confirmed()->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Dashboard')
            ->has('upcomingTrips', 0)
            ->has('pastTrips', 0));
});

it('never shows another users trips', function () {
    $user = User::factory()->confirmed()->create();
    $other = User::factory()->confirmed()->create();
    Trip::factory()->for($other)->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('upcomingTrips', 0));
});

it('never shows soft-deleted trips', function () {
    $user = User::factory()->confirmed()->create();
    $trip = Trip::factory()->for($user)->create();
    $trip->delete();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('upcomingTrips', 0));
});
