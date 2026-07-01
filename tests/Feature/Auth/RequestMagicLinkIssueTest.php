<?php

use App\Actions\RequestMagicLink;
use App\Mail\MagicLinkMail;
use App\Models\User;
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
