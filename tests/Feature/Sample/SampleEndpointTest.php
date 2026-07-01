<?php

use App\Mail\SampleDigestMail;
use App\Models\LoginToken;
use App\Models\SampleRequest;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;

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
