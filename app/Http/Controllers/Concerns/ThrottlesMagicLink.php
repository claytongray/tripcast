<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Shared per-email + per-IP throttle for endpoints that issue a magic link
 * (login and the public sample). Both share the same buckets so the sample can't
 * be used to bypass the login-link send limit.
 */
trait ThrottlesMagicLink
{
    protected function ensureNotThrottled(Request $request, string $email): void
    {
        $decaySeconds = (int) config('tripcast.magic_link.throttle.decay_minutes') * 60;

        $this->throttle(
            'magic-link:'.Str::lower($email),
            (int) config('tripcast.magic_link.throttle.max_attempts'),
            $decaySeconds,
        );

        $this->throttle(
            'magic-link-ip:'.$request->ip(),
            (int) config('tripcast.magic_link.throttle.ip_max_attempts'),
            $decaySeconds,
        );
    }

    protected function throttle(string $key, int $maxAttempts, int $decaySeconds): void
    {
        if (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
            $minutes = max(1, (int) ceil(RateLimiter::availableIn($key) / 60));

            throw ValidationException::withMessages([
                'email' => "Too many requests. Try again in {$minutes} minute".($minutes === 1 ? '' : 's').'.',
            ]);
        }

        RateLimiter::hit($key, $decaySeconds);
    }
}
