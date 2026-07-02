<?php

use App\Models\User;
use App\Services\Geocoding\FakeGeocoder;
use App\Services\Geocoding\Geocoder;
use Illuminate\Support\Carbon;

use function Pest\Laravel\from;
use function Pest\Laravel\get;
use function Pest\Laravel\post;

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-06-29 12:00:00', 'America/New_York'));
    // Deterministic, network-free geocoding.
    app()->bind(Geocoder::class, FakeGeocoder::class);
});

afterEach(function () {
    Carbon::setTestNow();
});

function tripSetup(array $overrides = []): array
{
    return array_merge([
        'destination' => 'Edinburgh',
        'departure_date' => '2026-07-10',
        'return_date' => '2026-07-17',
    ], $overrides);
}

// AC1 — a valid submission geocodes and merges canonical place + coords into the session.
it('geocodes a valid submission and merges the canonical place into the session', function () {
    post('/', tripSetup())
        ->assertRedirect(route('trip.detail'))
        ->assertSessionHas('pending_trip', fn ($t) => $t['destination'] === 'Edinburgh'
            && $t['canonical_place_name'] === 'Edinburgh, United Kingdom'
            && $t['latitude'] === 55.9533
            && $t['longitude'] === -3.1883);

    expect(User::count())->toBe(0);
});

// AC2 — ambiguous name resolves to a single most-likely locale (no picker).
it('resolves an ambiguous name to the most-likely locale', function () {
    post('/', tripSetup(['destination' => 'Paris']))
        ->assertSessionHas('pending_trip', fn ($t) => $t['canonical_place_name'] === 'Paris, France');
});

// AC3 — geocoding failure surfaces inline; no coords stored, nothing created.
it('surfaces an inline error and stores no coordinates when geocoding fails', function () {
    from('/')
        ->post('/', tripSetup(['destination' => 'Unfindable Place']))
        ->assertRedirect('/')
        ->assertSessionHasErrors([
            'destination' => "We couldn't find that place. Try a city and country — like 'Edinburgh, UK'.",
        ]);

    expect(session('pending_trip'))->toBeNull();
    expect(User::count())->toBe(0);
});

// Story 9.4 (FR-22) — a selected suggestion's place id resolves exactly.
it('resolves via the place id when a suggestion was selected', function () {
    post('/', tripSetup(['destination' => 'Edinburgh, United Kingdom', 'place_id' => 'fake-edinburgh', 'session_token' => 'session-1']))
        ->assertRedirect(route('trip.detail'))
        ->assertSessionHas('pending_trip', fn ($t) => $t['canonical_place_name'] === 'Edinburgh, United Kingdom'
            && $t['latitude'] === 55.9533);
});

// Story 9.4 (FR-22) — a stale/bogus place id falls back to text geocoding.
it('falls back to text geocoding when the place id does not resolve', function () {
    post('/', tripSetup(['destination' => 'Paris', 'place_id' => 'bogus-id', 'session_token' => 'session-1']))
        ->assertRedirect(route('trip.detail'))
        ->assertSessionHas('pending_trip', fn ($t) => $t['canonical_place_name'] === 'Paris, France');
});

// AC1 — the trip-detail confirm renders the canonical name back.
it('renders the trip-detail confirm with the canonical place name', function () {
    post('/', tripSetup());

    get(route('trip.detail'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('TripDetail')
            ->where('pendingTrip.canonical_place_name', 'Edinburgh, United Kingdom'));
});
