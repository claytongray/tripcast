<?php

use App\Models\User;
use Illuminate\Support\Carbon;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\from;
use function Pest\Laravel\get;
use function Pest\Laravel\post;
use function Pest\Laravel\withSession;

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * Pin "today" to a fixed America/New_York date so date validation is deterministic.
 */
function pinClock(): void
{
    Carbon::setTestNow(Carbon::parse('2026-06-29 12:00:00', 'America/New_York'));
}

function validTrip(array $overrides = []): array
{
    return array_merge([
        'destination' => 'Edinburgh',
        'departure_date' => '2026-07-10',
        'return_date' => '2026-07-17',
    ], $overrides);
}

// AC3 — the landing hero renders for guests.
it('renders the landing page', function () {
    get('/')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Landing'));
});

// A returning, logged-in user sees their trips, not the new-user form.
it('redirects an authenticated user from the landing page to the dashboard', function () {
    actingAs(User::factory()->create());

    get('/')->assertRedirect(route('dashboard'));
});

// Review #4 — "Edit destination" returns to a form seeded from the session.
it('repopulates the landing form from the session', function () {
    withSession(['pending_trip' => [
        'destination' => 'Edinburgh',
        'departure_date' => '2026-07-10',
        'return_date' => '2026-07-17',
        'canonical_place_name' => 'Edinburgh, United Kingdom',
        'latitude' => 55.9533,
        'longitude' => -3.1883,
    ]])
        ->get('/')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Landing')
            ->where('pendingTrip.destination', 'Edinburgh'));
});

// AC2 — a valid submission is stashed in the session; nothing is persisted.
it('stashes a valid submission in the session and creates no records', function () {
    pinClock();

    post('/', validTrip())
        ->assertRedirect(route('trip.detail'))
        ->assertSessionHas('pending_trip', fn ($t) => $t['destination'] === 'Edinburgh'
            && $t['departure_date'] === '2026-07-10'
            && $t['return_date'] === '2026-07-17');

    expect(User::count())->toBe(0);
});

// Temperature preference: the chosen unit is stashed for the create step.
it('stashes the chosen temperature unit', function () {
    pinClock();

    post('/', validTrip(['temperature_unit' => 'celsius']))
        ->assertRedirect(route('trip.detail'))
        ->assertSessionHas('pending_trip', fn ($t) => ($t['temperature_unit'] ?? null) === 'celsius');
});

// AC1 — empty destination shows the locked message.
it('rejects an empty destination with the locked message', function () {
    pinClock();

    from('/')
        ->post('/', validTrip(['destination' => '   ']))
        ->assertRedirect('/')
        ->assertSessionHasErrors(['destination' => 'Where are you headed?']);

    expect(User::count())->toBe(0);
    expect(session('pending_trip'))->toBeNull();
});

// AC1 — return before departure shows the locked message.
it('rejects a return before departure with the locked message', function () {
    pinClock();

    post('/', validTrip(['departure_date' => '2026-07-17', 'return_date' => '2026-07-10']))
        ->assertSessionHasErrors(['return_date' => 'Return is before departure — check the dates.']);
});

// AC1 — a past departure (America/New_York frame) shows the locked message.
it('rejects a past departure in the America/New_York frame', function () {
    pinClock(); // today = 2026-06-29 in America/New_York

    post('/', validTrip(['departure_date' => '2026-06-28']))
        ->assertSessionHasErrors(['departure_date' => "That date's already passed — pick a future trip."]);
});

// AC1 — today is acceptable as a departure (boundary: not in the past).
it('accepts a departure of today', function () {
    pinClock();

    post('/', validTrip(['departure_date' => '2026-06-29', 'return_date' => '2026-06-29']))
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('trip.detail'));
});

// AC1 — other entries survive a validation error (Laravel flashes old input).
it('preserves other entries on a validation error', function () {
    pinClock();

    from('/')
        ->post('/', validTrip(['destination' => '']))
        ->assertSessionHasErrors('destination')
        ->assertSessionHas('_old_input.departure_date', '2026-07-10')
        ->assertSessionHas('_old_input.return_date', '2026-07-17');
});

// AC2 — the placeholder next step redirects home when there is no pending trip.
it('redirects the trip-detail placeholder to home without a pending trip', function () {
    get(route('trip.detail'))->assertRedirect(route('home'));
});
