<?php

namespace App\Http\Controllers\Auth;

use App\Actions\RequestMagicLink;
use App\Http\Controllers\Controller;
use App\Models\LoginToken;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Passwordless magic-link authentication (AD-6).
 */
class MagicLinkController extends Controller
{
    /**
     * Show the request-a-link form.
     */
    public function create(): Response
    {
        return Inertia::render('auth/RequestLink');
    }

    /**
     * Issue a magic link for the given email, throttled per address.
     */
    public function store(Request $request, RequestMagicLink $requestMagicLink): RedirectResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
        ]);

        $email = $validated['email'];

        $this->ensureNotThrottled($request, $email);

        $result = $requestMagicLink->handle($email);

        return redirect()->route('login.sent')->with([
            'magic_email' => $result['user']->email,
            'magic_ttl' => $result['ttl_minutes'],
        ]);
    }

    /**
     * The "check your inbox" interstitial (UX-DR10).
     */
    public function sent(Request $request): Response|RedirectResponse
    {
        if (! $request->session()->has('magic_email')) {
            return redirect()->route('login');
        }

        return Inertia::render('auth/CheckEmail', [
            'email' => $request->session()->get('magic_email'),
            'ttlMinutes' => $request->session()->get('magic_ttl'),
        ]);
    }

    /**
     * Show the "confirm sign-in" screen for a magic link (AC5, AC6).
     *
     * This is the GET target of the emailed link. It NEVER consumes the token —
     * mail scanners, unfurlers, and prefetch all hit this safely. A usable token
     * renders the confirm screen (whose POST does the consume); an expired/consumed/
     * unknown token renders the calm resend page.
     */
    public function confirm(string $token): Response
    {
        $record = LoginToken::query()
            ->where('token_hash', RequestMagicLink::hash($token))
            ->first();

        if (! $record || ! $record->isUsable()) {
            return Inertia::render('auth/MagicLinkResult', [
                'email' => $record?->user?->email,
            ]);
        }

        return Inertia::render('auth/MagicLinkConfirm', [
            'token' => $token,
        ]);
    }

    /**
     * Consume a magic link: log in on success, calm resend page otherwise (AC5, AC6).
     *
     * CSRF-protected POST. Consumption is a single atomic conditional UPDATE
     * (`WHERE consumed_at IS NULL AND expires_at > now`), so two concurrent
     * requests for the same token can never both authenticate.
     */
    public function consume(Request $request, string $token): Response|RedirectResponse
    {
        $hash = RequestMagicLink::hash($token);

        $claimed = LoginToken::query()
            ->where('token_hash', $hash)
            ->whereNull('consumed_at')
            ->where('expires_at', '>', now())
            ->update(['consumed_at' => now()]);

        $record = LoginToken::query()->where('token_hash', $hash)->first();

        if ($claimed !== 1 || $record === null) {
            return Inertia::render('auth/MagicLinkResult', [
                'email' => $record?->user?->email,
            ]);
        }

        Auth::login($record->user);
        $request->session()->regenerate();

        return redirect()->intended(route('dashboard'));
    }

    /**
     * Log out and return to the landing page.
     */
    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('home');
    }

    /**
     * Enforce the request throttle: per-email (AC4) plus a per-IP cap so an
     * attacker cannot rotate addresses to email-bomb recipients or flood the
     * users table from a single source.
     */
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

    /**
     * Apply a single rate-limit bucket; throw a calm validation error when tripped.
     */
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
