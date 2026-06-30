<?php

use App\Actions\CreateTrip;
use App\Mail\MagicLinkMail;
use App\Mail\WelcomeMail;
use App\Models\Trip;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;

use function Pest\Laravel\post;
use function Pest\Laravel\withSession;

beforeEach(function () {
    Mail::fake();
    Carbon::setTestNow(Carbon::parse('2026-06-29 12:00:00', 'America/New_York'));
});

afterEach(function () {
    Carbon::setTestNow();
});

function pendingTripSession(array $overrides = []): array
{
    return array_merge([
        'destination' => 'Edinburgh',
        'departure_date' => '2026-07-10',
        'return_date' => '2026-07-17',
        'canonical_place_name' => 'Edinburgh, United Kingdom',
        'latitude' => 55.9533,
        'longitude' => -3.1883,
    ], $overrides);
}

// AC1/AC2 — atomic create + magic link + interstitial.
it('creates the account and trip atomically, sends the link, shows the interstitial', function () {
    withSession(['pending_trip' => pendingTripSession()])
        ->post('/trip', ['email' => 'maya@example.com'])
        ->assertRedirect(route('login.sent'));

    expect(User::count())->toBe(1)
        ->and(Trip::count())->toBe(1);

    $user = User::first();
    $trip = Trip::first();

    expect($user->email)->toBe('maya@example.com')
        ->and($trip->user_id)->toBe($user->id)
        ->and($trip->canonical_place_name)->toBe('Edinburgh, United Kingdom')
        ->and($trip->latitude)->toBe(55.9533)
        ->and($trip->longitude)->toBe(-3.1883)
        ->and($trip->status)->toBe('active');

    Mail::assertQueued(MagicLinkMail::class);
    // The welcome is held until the email is confirmed (no mail to an unconfirmed address).
    Mail::assertNotQueued(WelcomeMail::class);
    expect(session('pending_trip'))->toBeNull();
});

// Temperature preference: defaults to Fahrenheit when none was chosen.
it('defaults a new account to Fahrenheit', function () {
    withSession(['pending_trip' => pendingTripSession()])
        ->post('/trip', ['email' => 'maya@example.com'])
        ->assertRedirect(route('login.sent'));

    expect(User::first()->temperature_unit)->toBe(User::UNIT_FAHRENHEIT);
});

// Temperature preference: the form's chosen unit persists on the new account.
it('persists the chosen Celsius preference on the new account', function () {
    withSession(['pending_trip' => pendingTripSession(['temperature_unit' => 'celsius'])])
        ->post('/trip', ['email' => 'maya@example.com'])
        ->assertRedirect(route('login.sent'));

    expect(User::first()->temperature_unit)->toBe(User::UNIT_CELSIUS);
});

// AC1 — create-or-match by CI email, no duplicate user.
it('matches an existing user case-insensitively without duplicating', function () {
    $existing = User::factory()->create(['email' => 'maya@example.com']);

    withSession(['pending_trip' => pendingTripSession()])
        ->post('/trip', ['email' => 'MAYA@Example.com']);

    expect(User::count())->toBe(1)
        ->and(Trip::where('user_id', $existing->id)->count())->toBe(1);
});

// AC3 — no pending trip → no creation, back to landing.
it('redirects home and creates nothing without a pending trip', function () {
    post('/trip', ['email' => 'maya@example.com'])
        ->assertRedirect(route('home'));

    expect(User::count())->toBe(0)
        ->and(Trip::count())->toBe(0);
});

// AC1 — invalid email rejected, nothing created.
it('rejects an invalid email and creates nothing', function () {
    withSession(['pending_trip' => pendingTripSession()])
        ->post('/trip', ['email' => 'not-an-email'])
        ->assertSessionHasErrors('email');

    expect(User::count())->toBe(0)
        ->and(Trip::count())->toBe(0);
});

// Review #2 — the session is cleared on commit, so a resubmit can't duplicate the trip.
it('clears the session on commit and does not duplicate on resubmit', function () {
    withSession(['pending_trip' => pendingTripSession()])
        ->post('/trip', ['email' => 'maya@example.com'])
        ->assertRedirect(route('login.sent'));

    expect(Trip::count())->toBe(1);

    // A resubmit (no pending trip left) is bounced home, creating nothing more.
    post('/trip', ['email' => 'maya@example.com'])->assertRedirect(route('home'));

    expect(Trip::count())->toBe(1);
});

// Review #9 — a pending trip whose departure has passed is refused, nothing created.
it('refuses a stale pending trip whose departure has passed', function () {
    withSession(['pending_trip' => pendingTripSession([
        'departure_date' => '2026-06-20', // before pinned today 2026-06-29
        'return_date' => '2026-06-27',
    ])])
        ->post('/trip', ['email' => 'maya@example.com'])
        ->assertRedirect(route('home'));

    expect(User::count())->toBe(0)
        ->and(Trip::count())->toBe(0);
});

// AC1 — the transaction is atomic: a failed trip insert rolls back the user upsert.
it('rolls back the user upsert when the trip insert fails', function () {
    expect(fn () => app(CreateTrip::class)->handle('maya@example.com', [
        'destination' => 'Edinburgh',
        'departure_date' => '2026-07-10',
        'return_date' => '2026-07-17',
        'canonical_place_name' => str_repeat('x', 300), // exceeds varchar(255) → DB error
        'latitude' => 55.9533,
        'longitude' => -3.1883,
    ]))->toThrow(QueryException::class);

    expect(User::count())->toBe(0);
});
