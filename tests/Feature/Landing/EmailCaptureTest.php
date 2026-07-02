<?php

use App\Actions\CreateTrip;
use App\Actions\RequestMagicLink;
use App\Mail\MagicLinkMail;
use App\Mail\WelcomeMail;
use App\Models\LoginToken;
use App\Models\Trip;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;

use function Pest\Laravel\actingAs;
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

// Story 9.10 AC1 — an at-cap capture creates nothing, emails a sign-in link, and says so.
it('emails a sign-in link and keeps the pending trip when the address is at its cap', function () {
    config(['tripcast.free_tier.max_active_trips' => 1]);
    $user = User::factory()->create(['email' => 'maya@example.com']); // unconfirmed — the production lockout case
    Trip::factory()->for($user)->create();

    withSession(['pending_trip' => pendingTripSession()])
        ->post('/trip', ['email' => 'maya@example.com'])
        ->assertRedirect()
        ->assertSessionHasErrors(['email' => "You're at your plan's trip limit — we emailed you a sign-in link. Use it to manage your trips, then add this one."]);

    expect($user->trips()->count())->toBe(1)
        ->and(session('pending_trip'))->not->toBeNull()
        ->and(session('magic_link_pending.intent'))->toBe('login')
        ->and(session('magic_link_pending.token'))->toBeString();

    Mail::assertQueued(MagicLinkMail::class, fn (MagicLinkMail $mail) => $mail->hasTo('maya@example.com'));
    Mail::assertQueuedCount(1);
});

// Story 9.10 AC2 — a still-valid same-browser link is re-emailed unchanged, never rotated.
it('reuses a still-valid pending sign-in link at the cap instead of rotating it', function () {
    config(['tripcast.free_tier.max_active_trips' => 1]);
    $user = User::factory()->create(['email' => 'maya@example.com']);
    Trip::factory()->for($user)->create();

    $issued = app(RequestMagicLink::class)->issue('maya@example.com');

    withSession([
        'pending_trip' => pendingTripSession(),
        'magic_link_pending' => ['token' => $issued['token'], 'intent' => 'signup'],
    ])
        ->post('/trip', ['email' => 'maya@example.com'])
        ->assertRedirect()
        ->assertSessionHasErrors('email');

    // Same single token, same hash — reuse preserves the link and its intent.
    expect(LoginToken::count())->toBe(1)
        ->and(LoginToken::first()->token_hash)->toBe(RequestMagicLink::hash($issued['token']))
        ->and(session('magic_link_pending.token'))->toBe($issued['token'])
        ->and(session('magic_link_pending.intent'))->toBe('signup');

    Mail::assertQueued(MagicLinkMail::class, fn (MagicLinkMail $mail) => $mail->hasTo('maya@example.com'));
    Mail::assertQueuedCount(1);
});

// Review P2 — a stale (consumed) stashed token forces a fresh issue that resets intent to login.
it('fresh-issues with login intent when the stashed token is stale at the cap', function () {
    config(['tripcast.free_tier.max_active_trips' => 1]);
    $user = User::factory()->create(['email' => 'maya@example.com']);
    Trip::factory()->for($user)->create();

    $issued = app(RequestMagicLink::class)->issue('maya@example.com');
    LoginToken::query()->update(['consumed_at' => now()]); // stale — no longer reusable

    withSession([
        'pending_trip' => pendingTripSession(),
        'magic_link_pending' => ['token' => $issued['token'], 'intent' => 'signup'],
    ])
        ->post('/trip', ['email' => 'maya@example.com'])
        ->assertRedirect()
        ->assertSessionHasErrors('email');

    expect(session('magic_link_pending.token'))->not->toBe($issued['token'])
        ->and(session('magic_link_pending.intent'))->toBe('login');

    Mail::assertQueued(MagicLinkMail::class, fn (MagicLinkMail $mail) => $mail->hasTo('maya@example.com'));
});

// Review P2 — a malformed stash never crashes the at-cap path; it fresh-issues as login.
it('survives a malformed magic_link_pending stash at the cap', function () {
    config(['tripcast.free_tier.max_active_trips' => 1]);
    $user = User::factory()->create(['email' => 'maya@example.com']);
    Trip::factory()->for($user)->create();

    withSession([
        'pending_trip' => pendingTripSession(),
        'magic_link_pending' => 'not-an-array',
    ])
        ->post('/trip', ['email' => 'maya@example.com'])
        ->assertRedirect()
        ->assertSessionHasErrors('email');

    expect(session('magic_link_pending.intent'))->toBe('login');

    Mail::assertQueued(MagicLinkMail::class);
    Mail::assertQueuedCount(1);
});

// Review P3 — case variants reuse the same link and land in the same lowercased throttle bucket.
it('reuses the stashed link and shared bucket for a mixed-case email at the cap', function () {
    config(['tripcast.free_tier.max_active_trips' => 1]);
    $user = User::factory()->create(['email' => 'maya@example.com']);
    Trip::factory()->for($user)->create();

    $issued = app(RequestMagicLink::class)->issue('maya@example.com');

    withSession([
        'pending_trip' => pendingTripSession(),
        'magic_link_pending' => ['token' => $issued['token'], 'intent' => 'signup'],
    ])
        ->post('/trip', ['email' => 'MAYA@Example.com'])
        ->assertRedirect()
        ->assertSessionHasErrors('email');

    expect(LoginToken::count())->toBe(1)
        ->and(LoginToken::first()->token_hash)->toBe(RequestMagicLink::hash($issued['token']))
        ->and(RateLimiter::attempts('magic-link:maya@example.com'))->toBeGreaterThan(0);

    Mail::assertQueued(MagicLinkMail::class, fn (MagicLinkMail $mail) => $mail->hasTo('maya@example.com'));
});

// Review P3 — organic at-cap submits consume the shared bucket; the boundary attempt still
// sends and the next is blocked with nothing queued.
it('consumes the shared magic-link bucket on each at-cap submit until blocked', function () {
    config(['tripcast.free_tier.max_active_trips' => 1, 'tripcast.magic_link.throttle.max_attempts' => 2]);
    $user = User::factory()->create(['email' => 'maya@example.com']);
    Trip::factory()->for($user)->create();

    withSession(['pending_trip' => pendingTripSession()]);

    foreach (range(1, 2) as $attempt) {
        post('/trip', ['email' => 'maya@example.com'])->assertSessionHasErrors('email');
    }

    Mail::assertQueuedCount(2);

    post('/trip', ['email' => 'maya@example.com'])
        ->assertRedirect()
        ->assertInvalid(['email' => 'Too many requests.']);

    expect(session('pending_trip'))->not->toBeNull();

    Mail::assertQueuedCount(2);
});

// Story 9.10 AC3 — an exhausted magic-link throttle blocks the send: no email, no trip.
it('shows the throttle error and sends nothing when the magic-link bucket is exhausted at the cap', function () {
    config(['tripcast.free_tier.max_active_trips' => 1]);
    $user = User::factory()->create(['email' => 'maya@example.com']);
    Trip::factory()->for($user)->create();

    $maxAttempts = (int) config('tripcast.magic_link.throttle.max_attempts');

    foreach (range(1, $maxAttempts) as $i) {
        RateLimiter::hit('magic-link:maya@example.com', 600);
    }

    withSession(['pending_trip' => pendingTripSession()])
        ->post('/trip', ['email' => 'maya@example.com'])
        ->assertRedirect()
        ->assertSessionHasErrors('email');

    expect(session('errors')->first('email'))->toStartWith('Too many requests.')
        ->and($user->trips()->count())->toBe(1)
        ->and(session('pending_trip'))->not->toBeNull()
        ->and(session('magic_link_pending'))->toBeNull();

    Mail::assertNothingQueued();
});

// Review D1 — a signed-in owner resubmitting their own email is an authenticated add:
// trip created, no magic link, no interstitial — straight to the dated success screen.
it('adds the trip directly for a signed-in owner without another magic link', function () {
    $user = User::factory()->confirmed()->create(['email' => 'maya@example.com']);

    $response = actingAs($user)
        ->withSession(['pending_trip' => pendingTripSession()])
        ->post('/trip', ['email' => 'MAYA@example.com']); // case-insensitive ownership match

    $trip = Trip::first();

    $response->assertRedirect(route('trips.added', $trip));

    expect($trip->user_id)->toBe($user->id)
        ->and(session('pending_trip'))->toBeNull()
        ->and(session('magic_link_pending'))->toBeNull();

    Mail::assertNotQueued(MagicLinkMail::class);
    // The confirmed owner's welcome sends, matching the dashboard add path.
    Mail::assertQueued(WelcomeMail::class);
});

// Review D1 — a signed-in owner at the cap gets the actionable default message, no link.
it('shows the default limit message with no link for a signed-in owner at the cap', function () {
    config(['tripcast.free_tier.max_active_trips' => 1]);
    $user = User::factory()->confirmed()->create(['email' => 'maya@example.com']);
    Trip::factory()->for($user)->create();

    actingAs($user)
        ->withSession(['pending_trip' => pendingTripSession()])
        ->post('/trip', ['email' => 'maya@example.com'])
        ->assertRedirect()
        ->assertSessionHasErrors(['email' => "You're at your plan's trip limit. Pause or remove one to add another."]);

    expect($user->trips()->count())->toBe(1)
        ->and(session('pending_trip'))->not->toBeNull();

    Mail::assertNothingQueued();
});

// Review D1 — a mismatched email keeps the guest flow: the submitted address owns the
// trip and still proves itself via the emailed link.
it('keeps the guest flow when a signed-in user submits a different email', function () {
    $user = User::factory()->confirmed()->create(['email' => 'maya@example.com']);

    actingAs($user)
        ->withSession(['pending_trip' => pendingTripSession()])
        ->post('/trip', ['email' => 'friend@example.com'])
        ->assertRedirect(route('login.sent'));

    expect(Trip::first()->user->email)->toBe('friend@example.com');

    Mail::assertQueued(MagicLinkMail::class, fn (MagicLinkMail $mail) => $mail->hasTo('friend@example.com'));
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
