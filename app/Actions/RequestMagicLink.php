<?php

namespace App\Actions;

use App\Mail\MagicLinkMail;
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
     * @return array{user: User, expires_at: CarbonImmutable, ttl_minutes: int}
     */
    public function handle(string $email): array
    {
        // Normalize in app code, not just via the DB collation: keeps account
        // matching consistent on every driver (the sqlite default has no CI
        // collation) and aligned with the lowercased throttle key.
        $email = Str::lower(trim($email));
        $ttlMinutes = (int) config('tripcast.magic_link.ttl_minutes');

        // Create-or-match by CI email (users.email uses a case-insensitive collation).
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

        $url = URL::route('magic.consume', ['token' => $rawToken]);

        // Queued so a slow/failing mail transport can't block the request thread
        // (a DoS amplifier on this public endpoint) or 500 after the token is
        // already persisted — queue retries handle transient delivery failures.
        Mail::to($user->email)->queue(new MagicLinkMail($url, $ttlMinutes));

        return [
            'user' => $user,
            'expires_at' => $expiresAt,
            'ttl_minutes' => $ttlMinutes,
        ];
    }

    /**
     * SHA-256 hash of a raw token — the only form persisted.
     */
    public static function hash(string $rawToken): string
    {
        return hash('sha256', $rawToken);
    }
}
