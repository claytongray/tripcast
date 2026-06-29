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

        $this->ensureNotThrottled($email);

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
     * Consume a magic link: log in on success, calm resend page otherwise (AC5, AC6).
     */
    public function consume(Request $request, string $token): Response|RedirectResponse
    {
        $record = LoginToken::query()
            ->where('token_hash', RequestMagicLink::hash($token))
            ->first();

        if (! $record || ! $record->isUsable()) {
            return Inertia::render('auth/MagicLinkResult', [
                'email' => $record?->user->email,
            ]);
        }

        $record->forceFill(['consumed_at' => now()])->save();

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
     * Enforce the per-email request throttle (AC4).
     */
    protected function ensureNotThrottled(string $email): void
    {
        $key = 'magic-link:'.Str::lower($email);
        $maxAttempts = (int) config('tripcast.magic_link.throttle.max_attempts');
        $decaySeconds = (int) config('tripcast.magic_link.throttle.decay_minutes') * 60;

        if (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
            $minutes = (int) ceil(RateLimiter::availableIn($key) / 60);

            throw ValidationException::withMessages([
                'email' => "Too many requests. Try again in {$minutes} minute".($minutes === 1 ? '' : 's').'.',
            ]);
        }

        RateLimiter::hit($key, $decaySeconds);
    }
}
