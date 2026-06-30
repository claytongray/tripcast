<?php

use App\Mail\WelcomeMail;
use App\Models\Trip;
use App\Models\User;
use App\Services\Geocoding\FakeGeocoder;
use App\Services\Geocoding\Geocoder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-06-30 09:00', 'America/New_York'));
    Mail::fake();
    app()->bind(Geocoder::class, FakeGeocoder::class);
});

afterEach(function () {
    Carbon::setTestNow();
});

function validTripPayload(array $overrides = []): array
{
    return array_merge([
        'destination' => 'Edinburgh',
        'departure_date' => '2026-07-14',
        'return_date' => '2026-07-21',
    ], $overrides);
}

it('adds an active trip through CreateTrip and lands on the dated success screen', function () {
    $user = User::factory()->confirmed()->create();

    $response = $this->actingAs($user)->post(route('trips.store'), validTripPayload());

    $trip = $user->trips()->firstOrFail();
    expect($trip->status)->toBe(Trip::STATUS_ACTIVE)
        ->and($trip->canonical_place_name)->toBe('Edinburgh, United Kingdom');

    $response->assertRedirect(route('trips.added', $trip));

    // Welcome fires at creation for the already-confirmed owner (AD-6/FR-9).
    Mail::assertQueued(WelcomeMail::class, fn (WelcomeMail $mail) => $mail->hasTo($user->email));

    // Success screen: first forecast = departure − horizon (7d) = 2026-07-07.
    $this->actingAs($user)->get(route('trips.added', $trip))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('TripAdded')
            ->where('destination', 'Edinburgh, United Kingdom')
            ->where('firstForecastDate', '2026-07-07'));
});

it('does not create a trip when geocoding fails', function () {
    $user = User::factory()->confirmed()->create();

    $this->actingAs($user)
        ->post(route('trips.store'), validTripPayload(['destination' => 'unfindable place']))
        ->assertRedirect()
        ->assertSessionHasErrors('destination');

    expect($user->trips()->count())->toBe(0);
    Mail::assertNothingQueued();
});

it('rejects a past departure date', function () {
    $user = User::factory()->confirmed()->create();

    $this->actingAs($user)
        ->post(route('trips.store'), validTripPayload(['departure_date' => '2026-06-01', 'return_date' => '2026-06-05']))
        ->assertSessionHasErrors('departure_date');

    expect($user->trips()->count())->toBe(0);
});

it('rejects a return before departure', function () {
    $user = User::factory()->confirmed()->create();

    $this->actingAs($user)
        ->post(route('trips.store'), validTripPayload(['departure_date' => '2026-07-14', 'return_date' => '2026-07-10']))
        ->assertSessionHasErrors('return_date');

    expect($user->trips()->count())->toBe(0);
});

it('redirects guests to login', function () {
    $this->post(route('trips.store'), validTripPayload())->assertRedirect(route('login'));
});

it('forbids viewing another users success screen', function () {
    $owner = User::factory()->confirmed()->create();
    $trip = Trip::factory()->for($owner)->create();

    $this->actingAs(User::factory()->confirmed()->create())
        ->get(route('trips.added', $trip))
        ->assertForbidden();
});
