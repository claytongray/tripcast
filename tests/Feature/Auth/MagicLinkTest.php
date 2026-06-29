<?php

use App\Actions\RequestMagicLink;
use App\Mail\MagicLinkMail;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

use function Pest\Laravel\from;
use function Pest\Laravel\get;
use function Pest\Laravel\post;

beforeEach(function () {
    Mail::fake();
    RateLimiter::clear('magic-link:maya@example.com');
});

/**
 * Issue a usable magic link for a token-holder. Returns [user, rawToken].
 *
 * @return array{0: User, 1: string}
 */
function issueToken(?User $user = null, ?Closure $attributes = null): array
{
    $user ??= User::factory()->create();
    $raw = Str::random(64);

    $user->loginTokens()->create(array_merge([
        'token_hash' => RequestMagicLink::hash($raw),
        'expires_at' => now()->addMinutes(15),
    ], $attributes ? $attributes($user) : []));

    return [$user, $raw];
}

// AC4 — request issues a single-use token, creates the account, sends the mail.
it('issues a single-use token and emails the link', function () {
    post('/login', ['email' => 'maya@example.com'])
        ->assertRedirect(route('login.sent'));

    $user = User::where('email', 'maya@example.com')->first();

    expect($user)->not->toBeNull()
        ->and($user->loginTokens()->whereNull('consumed_at')->count())->toBe(1);

    Mail::assertSent(MagicLinkMail::class);
});

// AC4 — requesting a new link invalidates prior unconsumed tokens for that user.
it('invalidates prior unconsumed tokens on a new request', function () {
    post('/login', ['email' => 'maya@example.com']);
    post('/login', ['email' => 'maya@example.com']);

    $user = User::where('email', 'maya@example.com')->first();

    expect(User::where('email', 'maya@example.com')->count())->toBe(1)
        ->and($user->loginTokens()->whereNull('consumed_at')->count())->toBe(1);
});

// AC4 — the same email in a different case matches one account (CI collation + create-or-match).
it('matches the same account case-insensitively', function () {
    app(RequestMagicLink::class)->handle('Maya@Example.com');
    app(RequestMagicLink::class)->handle('maya@example.com');

    expect(User::where('email', 'MAYA@EXAMPLE.COM')->count())->toBe(1);
});

// AC5 — a valid link logs the user in, consumes the token, lands on the dashboard.
it('logs in and consumes the token on a valid link', function () {
    [$user, $raw] = issueToken();

    get(route('magic.consume', ['token' => $raw]))
        ->assertRedirect(route('dashboard'));

    $this->assertAuthenticatedAs($user);

    expect($user->loginTokens()->first()->consumed_at)->not->toBeNull();
});

// AC6 — a consumed link cannot be reused: calm result page, no login.
it('rejects an already-consumed link', function () {
    [$user, $raw] = issueToken(attributes: fn () => ['consumed_at' => now()]);

    get(route('magic.consume', ['token' => $raw]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('auth/MagicLinkResult'));

    $this->assertGuest();
});

// AC6 — an expired link is a calm result page with the email primed for resend.
it('rejects an expired link and offers resend', function () {
    [$user, $raw] = issueToken(attributes: fn () => ['expires_at' => now()->subMinute()]);

    get(route('magic.consume', ['token' => $raw]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('auth/MagicLinkResult')
            ->where('email', $user->email)
        );

    $this->assertGuest();
});

// AC6 — an unknown token is still calm (no dead end), with no email to prime.
it('handles an unknown token calmly', function () {
    get(route('magic.consume', ['token' => Str::random(64)]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('auth/MagicLinkResult')
            ->where('email', null)
        );

    $this->assertGuest();
});

// AC4 — requests are throttled per email.
it('throttles repeated requests for the same email', function () {
    $max = (int) config('tripcast.magic_link.throttle.max_attempts');

    for ($i = 0; $i < $max; $i++) {
        post('/login', ['email' => 'maya@example.com'])
            ->assertRedirect(route('login.sent'));
    }

    from(route('login'))
        ->post('/login', ['email' => 'maya@example.com'])
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors('email');

    // Throttled attempt issued no additional token beyond the cap.
    $user = User::where('email', 'maya@example.com')->first();
    expect($user->loginTokens()->count())->toBe(1); // priors invalidated each time
});

// FR-4 — logout requires a fresh link afterwards.
it('logs out an authenticated user', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post('/logout')
        ->assertRedirect(route('home'));

    $this->assertGuest();
});

// A token is single-use end to end: consume twice, second is rejected.
it('a token cannot be used twice', function () {
    [$user, $raw] = issueToken();

    get(route('magic.consume', ['token' => $raw]))->assertRedirect(route('dashboard'));

    post('/logout');

    get(route('magic.consume', ['token' => $raw]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('auth/MagicLinkResult'));

    $this->assertGuest();
});
