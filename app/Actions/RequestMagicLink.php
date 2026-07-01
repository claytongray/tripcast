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
     * Issue a single-use magic-link token and return its consume URL WITHOUT
     * sending any email. Create-or-match the user by case-insensitive email and
     * atomically rotate their unconsumed tokens. The raw token never persists.
     *
     * @param  int|null  $ttlMinutes  Override the default TTL (minutes). Defaults to
     *                                `tripcast.magic_link.ttl_minutes` when null.
     * @return array{user: User, url: string, expires_at: CarbonImmutable, ttl_minutes: int}
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
            'expires_at' => $expiresAt,
            'ttl_minutes' => $ttlMinutes,
        ];
    }

    /**
     * Issue a magic link and email it (the standard login path, AD-6).
     *
     * @return array{user: User, url: string, expires_at: CarbonImmutable, ttl_minutes: int}
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
     * SHA-256 hash of a raw token — the only form persisted.
     */
    public static function hash(string $rawToken): string
    {
        return hash('sha256', $rawToken);
    }
}
