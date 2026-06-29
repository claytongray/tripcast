<?php

use App\Models\User;

use function Pest\Laravel\get;
use function Pest\Laravel\post;
use function Pest\Laravel\put;

// AC2 — no password/registration/reset/2FA routes resolve anywhere.
it('does not resolve any password or registration route', function (string $method, string $uri) {
    $response = match ($method) {
        'post' => post($uri),
        'put' => put($uri),
        default => get($uri),
    };

    $response->assertNotFound();
})->with([
    'register (GET)' => ['get', '/register'],
    'register (POST)' => ['post', '/register'],
    'forgot password' => ['get', '/forgot-password'],
    'reset password' => ['get', '/reset-password'],
    'confirm password' => ['get', '/password/confirm'],
    'update password' => ['put', '/password'],
    'two-factor challenge' => ['get', '/two-factor-challenge'],
    'passkeys endpoint' => ['get', '/.well-known/passkey-endpoints'],
]);

// AC2 — the user record carries no password attribute.
it('has no password attribute on the user model', function () {
    $user = User::factory()->create();

    expect($user->getAttributes())->not->toHaveKey('password')
        ->and($user->getAttributes())->not->toHaveKey('remember_token');
});
