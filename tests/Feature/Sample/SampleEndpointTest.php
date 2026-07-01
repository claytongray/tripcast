<?php

use App\Mail\SampleDigestMail;
use App\Models\LoginToken;
use App\Models\SampleRequest;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;

use function Pest\Laravel\from;
use function Pest\Laravel\get;
use function Pest\Laravel\post;

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-06-30 09:00', 'America/New_York'));
    RateLimiter::clear('magic-link:sampler@example.com');
    RateLimiter::clear('magic-link-ip:127.0.0.1');
});

afterEach(fn () => Carbon::setTestNow());

it('queues a sample, creates the user, and records one request row', function () {
    Mail::fake();

    post(route('sample.store'), ['email' => 'sampler@example.com'])
        ->assertRedirect()
        ->assertSessionHas('sample_sent', 'sampler@example.com');

    Mail::assertQueued(SampleDigestMail::class);
    expect(User::where('email', 'sampler@example.com')->exists())->toBeTrue()
        ->and(SampleRequest::where('email', 'sampler@example.com')->count())->toBe(1);
});

it('writes a second row for a repeat request', function () {
    Mail::fake();

    post(route('sample.store'), ['email' => 'sampler@example.com']);
    post(route('sample.store'), ['email' => 'sampler@example.com']);

    expect(SampleRequest::where('email', 'sampler@example.com')->count())->toBe(2);
});

it('rejects an invalid email and records nothing', function () {
    Mail::fake();

    post(route('sample.store'), ['email' => 'not-an-email'])
        ->assertSessionHasErrors('email');

    Mail::assertNothingQueued();
    expect(SampleRequest::count())->toBe(0);
});

it('throttles after the configured per-email attempts', function () {
    Mail::fake();
    config(['tripcast.magic_link.throttle.max_attempts' => 2]);

    post(route('sample.store'), ['email' => 'sampler@example.com']);
    post(route('sample.store'), ['email' => 'sampler@example.com']);
    post(route('sample.store'), ['email' => 'sampler@example.com'])
        ->assertSessionHasErrors('email');

    expect(SampleRequest::where('email', 'sampler@example.com')->count())->toBe(2);
});

it('issues a magic link with the 48h sample TTL after a sample request', function () {
    Mail::fake();

    $now = Carbon::parse('2026-07-01 12:00:00');
    Carbon::setTestNow($now);

    $expectedTtl = (int) config('tripcast.sample.magic_link_ttl_minutes');

    post(route('sample.store'), ['email' => 'sampler@example.com'])
        ->assertRedirect();

    $user = User::where('email', 'sampler@example.com')->firstOrFail();
    $token = LoginToken::where('user_id', $user->id)->latest('id')->firstOrFail();

    expect((int) $now->diffInMinutes($token->expires_at))->toBe($expectedTtl);
});

// --- Shared-throttle invariant: sample and login share the same rate-limiter buckets ---

/**
 * Exhaust the per-email bucket via /sample, then prove the login endpoint is also
 * blocked for that address. Defends the invariant that /sample cannot be used as an
 * unthrottled side-channel for issuing login links.
 */
it('exhausting the per-email limit via sample also blocks the login endpoint for that address', function () {
    Mail::fake();
    config(['tripcast.magic_link.throttle.max_attempts' => 2]);

    post(route('sample.store'), ['email' => 'sampler@example.com']);
    post(route('sample.store'), ['email' => 'sampler@example.com']);

    // The per-email bucket is now full; the login endpoint must refuse the same address.
    from(route('login'))
        ->post(route('login.store'), ['email' => 'sampler@example.com'])
        ->assertSessionHasErrors('email');
});

/**
 * Reverse direction: exhaust the per-email bucket via the login endpoint, then prove
 * /sample is also blocked. Same bucket → same cap, no matter which endpoint filled it.
 */
it('exhausting the per-email limit via login also blocks the sample endpoint for that address', function () {
    Mail::fake();
    config(['tripcast.magic_link.throttle.max_attempts' => 2]);

    post(route('login.store'), ['email' => 'sampler@example.com']);
    post(route('login.store'), ['email' => 'sampler@example.com']);

    post(route('sample.store'), ['email' => 'sampler@example.com'])
        ->assertSessionHasErrors('email');
});

/**
 * Per-IP cap fires on /sample across distinct email addresses, proving the cap is not
 * email-scoped and cannot be bypassed by rotating addresses from one IP.
 */
it('fires the per-IP cap via sample when the same IP rotates through different emails', function () {
    Mail::fake();
    config(['tripcast.magic_link.throttle.ip_max_attempts' => 2]);

    post(route('sample.store'), ['email' => 'ip-a@example.com']);
    post(route('sample.store'), ['email' => 'ip-b@example.com']);

    // Third request from same IP (127.0.0.1) with a fresh email must be throttled.
    post(route('sample.store'), ['email' => 'ip-c@example.com'])
        ->assertSessionHasErrors('email');
});

// --- Sample activation path: emailed CTA → consume → dashboard ---

/**
 * The sample digest's "Get started" URL drives the full magic-link consume flow: a
 * GET shows the confirm screen (token not yet consumed), the POST logs in and lands
 * the user on the dashboard. Verifies the end-to-end wiring from SampleDigestMail
 * through to an authenticated dashboard redirect.
 */
it('the sample get-started link logs in the user and lands them on the dashboard', function () {
    Mail::fake();

    post(route('sample.store'), ['email' => 'sample-consume@example.com']);

    /** @var SampleDigestMail $mail */
    $mail = Mail::queued(SampleDigestMail::class)->first();

    // Extract the raw token from the emailed URL (e.g. http://localhost/auth/magic/{token}).
    $token = basename(parse_url($mail->getStartedUrl, PHP_URL_PATH));

    // GET only shows the confirm screen — token must remain unconsumed.
    get(route('magic.consume', ['token' => $token]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('auth/MagicLinkConfirm'));

    $this->assertGuest();

    // POST consumes the token and redirects to the dashboard.
    post(route('magic.consume.store', ['token' => $token]))
        ->assertRedirect(route('dashboard'));

    $this->assertAuthenticated();
});
