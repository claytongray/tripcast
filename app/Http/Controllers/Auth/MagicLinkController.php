<?php

namespace App\Http\Controllers\Auth;

use App\Actions\RequestMagicLink;
use App\Actions\SendWelcomeEmail;
use App\Http\Controllers\Concerns\ThrottlesMagicLink;
use App\Http\Controllers\Controller;
use App\Models\LoginToken;
use App\Services\Analytics\KeyEvent;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Passwordless magic-link authentication (AD-6).
 */
class MagicLinkController extends Controller
{
    use ThrottlesMagicLink;

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

        // Reuse the link stashed for this browser when it's still valid, so a
        // resend never invalidates a first email that arrives late. The raw
        // token lives only in the (server-side) session — never in the tokens
        // table, which still holds hashes only.
        $pending = $request->session()->get('magic_link_pending');
        $pendingToken = is_array($pending) ? ($pending['token'] ?? null) : null;

        $result = $requestMagicLink->resendOrIssue($email, $pendingToken);

        // Preserve the original intent across a resend so a signup that reuses its
        // link keeps the "start your tripcast" activation copy; a genuinely fresh
        // issue on this (login) surface is a login.
        $intent = ($result['reused'] && is_array($pending)) ? ($pending['intent'] ?? 'login') : 'login';

        $request->session()->put('magic_link_pending', ['token' => $result['token'], 'intent' => $intent]);

        KeyEvent::flash(KeyEvent::LOGIN_LINK_REQUESTED);

        return redirect()->route('login.sent')->with([
            'magic_email' => $result['user']->email,
            'magic_ttl' => $result['ttl_minutes'],
            'magic_intent' => $intent,
        ]);
    }

    /**
     * The "check your inbox" interstitial (UX-DR10). The copy varies by intent:
     * a new signup must click to *start* their tripcast (activation); a returning
     * user clicks to *sign in*.
     */
    public function sent(Request $request): Response|RedirectResponse
    {
        if (! $request->session()->has('magic_email')) {
            return redirect()->route('login');
        }

        return Inertia::render('auth/CheckEmail', [
            'email' => $request->session()->get('magic_email'),
            'ttlMinutes' => $request->session()->get('magic_ttl'),
            'intent' => $request->session()->get('magic_intent', 'login'),
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
    public function consume(Request $request, string $token, SendWelcomeEmail $sendWelcomeEmail): Response|RedirectResponse
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

        $user = $record->user;

        // The first consume confirms the email (AD-6) and activates the account +
        // any trips created while unconfirmed — only now do their welcomes send.
        $justConfirmed = $user->confirmEmail();

        // remember: true issues a long-lived recaller cookie so the login
        // persists past the session lifetime — the user stays signed in until
        // they explicitly log out (which clears the token, see destroy()).
        Auth::login($user, true);
        $request->session()->regenerate();
        $request->session()->forget('magic_link_pending');

        // A first-time confirm is a new signup; a later consume is a returning
        // login. Both ride the redirect below to whichever page lands.
        KeyEvent::flash(
            $justConfirmed ? KeyEvent::SIGN_UP : KeyEvent::LOGIN,
            ['method' => 'magic_link'],
        );

        if ($justConfirmed) {
            $trips = $user->trips()->get();

            foreach ($trips as $trip) {
                $sendWelcomeEmail->handle($trip);
            }

            // Land a freshly-confirmed new signup on the shared dated success
            // screen (Story 3.2) for the trip they just created, rather than the
            // bare dashboard. Returning logins (no just-confirmed trip) are
            // unchanged.
            $featured = $trips->sortByDesc('id')->first();

            if ($featured !== null) {
                return redirect()->route('trips.added', $featured);
            }
        }

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
}
