<?php

namespace App\Actions;

use App\Mail\MagicLinkMail;
use App\Models\User;
use Illuminate\Support\Carbon;
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
     * @return array{user: User, expires_at: Carbon, ttl_minutes: int}
     */
    public function handle(string $email): array
    {
        $email = trim($email);
        $ttlMinutes = (int) config('tripcast.magic_link.ttl_minutes');

        // Create-or-match by CI email (users.email uses a case-insensitive collation).
        $user = User::firstOrCreate(['email' => $email]);

        // A new request invalidates every prior unconsumed token for this user.
        $user->loginTokens()->whereNull('consumed_at')->delete();

        $rawToken = Str::random(64);
        $expiresAt = now()->addMinutes($ttlMinutes);

        $user->loginTokens()->create([
            'token_hash' => self::hash($rawToken),
            'expires_at' => $expiresAt,
        ]);

        $url = URL::route('magic.consume', ['token' => $rawToken]);

        Mail::to($user->email)->send(new MagicLinkMail($url, $ttlMinutes));

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
