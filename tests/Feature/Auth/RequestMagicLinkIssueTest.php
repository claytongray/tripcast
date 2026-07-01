<?php

use App\Actions\RequestMagicLink;
use App\Mail\MagicLinkMail;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;

it('issue() creates the user and a token URL without sending mail', function () {
    Mail::fake();

    $result = app(RequestMagicLink::class)->issue('Sampler@Example.com');

    expect($result['user'])->toBeInstanceOf(User::class)
        ->and($result['user']->email)->toBe('sampler@example.com')
        ->and($result['url'])->toContain('/auth/magic/')
        ->and($result['user']->loginTokens()->count())->toBe(1);

    Mail::assertNothingQueued();
});

it('handle() still issues and queues the magic-link email', function () {
    Mail::fake();

    app(RequestMagicLink::class)->handle('sampler@example.com');

    Mail::assertQueued(MagicLinkMail::class);
});

it('issue() with an explicit ttl_minutes uses that value for expires_at and returns it', function () {
    Mail::fake();
    Carbon::setTestNow(Carbon::parse('2026-07-01 12:00:00'));

    $result = app(RequestMagicLink::class)->issue('sampler@example.com', 2880);

    expect($result['ttl_minutes'])->toBe(2880)
        ->and((int) Carbon::now()->diffInMinutes($result['expires_at']))->toBe(2880);

    Carbon::setTestNow();
});

it('issue() with no ttl_minutes argument defaults to the config value', function () {
    Mail::fake();
    Carbon::setTestNow(Carbon::parse('2026-07-01 12:00:00'));

    $configDefault = (int) config('tripcast.magic_link.ttl_minutes');

    $result = app(RequestMagicLink::class)->issue('sampler@example.com');

    expect($result['ttl_minutes'])->toBe($configDefault)
        ->and((int) Carbon::now()->diffInMinutes($result['expires_at']))->toBe($configDefault);

    Carbon::setTestNow();
});
