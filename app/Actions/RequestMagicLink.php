<?php

namespace App\Actions;

use App\Mail\MagicLinkMail;
use App\Models\LoginToken;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * Issue a passwordless magic-link login (AD-6).
 *
 * Creates or matches the user by case-insensitive email, invalidates any prior
 * unconsumed tokens for that user, stores a single-use hashed token, and emails
 * the raw link. The raw token never touches the database — only its SHA-256 hash.
 */
class RequestMagicLink
{
    /**
     * Issue a single-use magic-link token and return its consume URL WITHOUT
     * sending any email. Create-or-match the user by case-insensitive email and
     * atomically rotate their unconsumed tokens. The raw token never persists.
     *
     * @param  int|null  $ttlMinutes  Override the default TTL (minutes). Defaults to
     *                                `tripcast.magic_link.ttl_minutes` when null.
     * @return array{user: User, url: string, token: string, expires_at: CarbonImmutable, ttl_minutes: int}
     */
    public function issue(string $email, ?int $ttlMinutes = null): array
    {
        // Normalize in app code, not just via the DB collation: keeps account
        // matching consistent on every driver (the sqlite default has no CI
        // collation) and aligned with the lowercased throttle key.
        $email = Str::lower(trim($email));
        $ttlMinutes = $ttlMinutes ?? (int) config('tripcast.magic_link.ttl_minutes');

        $user = User::firstOrCreate(['email' => $email]);

        $rawToken = Str::random(64);
        $expiresAt = now()->addMinutes($ttlMinutes);

        // Invalidate prior unconsumed tokens and issue the new one atomically, so
        // concurrent requests can't interleave delete/create and leave the user
        // with zero or duplicate live tokens.
        DB::transaction(function () use ($user, $rawToken, $expiresAt) {
            $user->loginTokens()->whereNull('consumed_at')->delete();

            $user->loginTokens()->create([
                'token_hash' => self::hash($rawToken),
                'expires_at' => $expiresAt,
            ]);
        });

        return [
            'user' => $user,
            'url' => URL::route('magic.consume', ['token' => $rawToken]),
            'token' => $rawToken,
            'expires_at' => $expiresAt,
            'ttl_minutes' => $ttlMinutes,
        ];
    }

    /**
     * Issue a magic link and email it (the standard login path, AD-6).
     *
     * @return array{user: User, url: string, token: string, expires_at: CarbonImmutable, ttl_minutes: int}
     */
    public function handle(string $email): array
    {
        $result = $this->issue($email);

        // Queued so a slow/failing transport can't block the request thread or
        // 500 after the token is persisted — queue retries handle transient
        // delivery failures.
        Mail::to($result['user']->email)->queue(new MagicLinkMail($result['url'], $result['ttl_minutes']));

        return $result;
    }

    /**
     * Resend (don't rotate) when a still-valid link exists — otherwise issue a
     * fresh one. On a resend, a delayed first email can arrive after the user
     * clicks "resend"; rotating would silently invalidate the link they finally
     * received. So if $pendingRawToken still resolves to a usable token owned by
     * $email, re-mail that exact link. Reuse keeps the ORIGINAL expiry (the
     * window is never extended) and advertises the remaining minutes; a
     * consumed/expired/foreign/absent token falls back to a fresh issue. Either
     * branch queues the email.
     *
     * @return array{user: User, url: string, token: string, expires_at: CarbonImmutable, ttl_minutes: int, reused: bool}
     */
    public function resendOrIssue(string $email, ?string $pendingRawToken): array
    {
        $email = Str::lower(trim($email));

        if ($pendingRawToken !== null) {
            $record = LoginToken::query()
                ->where('token_hash', self::hash($pendingRawToken))
                ->whereNull('consumed_at')
                ->where('expires_at', '>', now())
                ->with('user')
                ->first();

            if ($record !== null && Str::lower(trim($record->user->email)) === $email) {
                $url = URL::route('magic.consume', ['token' => $pendingRawToken]);
                // floor (not ceil) so the "expires in N min" copy never over-reports
                // the window — a link with 4m40s left says 4, not 5.
                $ttlMinutes = max(1, (int) floor(now()->diffInSeconds($record->expires_at) / 60));

                Mail::to($email)->queue(new MagicLinkMail($url, $ttlMinutes));

                return [
                    'user' => $record->user,
                    'url' => $url,
                    'token' => $pendingRawToken,
                    'expires_at' => $record->expires_at->toImmutable(),
                    'ttl_minutes' => $ttlMinutes,
                    'reused' => true,
                ];
            }
        }

        return [...$this->handle($email), 'reused' => false];
    }

    /**
     * SHA-256 hash of a raw token — the only form persisted.
     */
    public static function hash(string $rawToken): string
    {
        return hash('sha256', $rawToken);
    }
}
