<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string $plan
 * @property string $timezone
 * @property bool $is_admin
 * @property bool $email_opted_out
 * @property string $temperature_unit
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
// `plan` and `is_admin` are intentionally NOT mass-assignable — they are
// privilege/entitlement flags and must be set explicitly (factory, seeder,
// billing), never from request input.
#[Fillable(['email', 'timezone', 'email_opted_out', 'temperature_unit'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    // The account-level temperature unit the digest renders (single unit).
    public const UNIT_FAHRENHEIT = 'fahrenheit';

    public const UNIT_CELSIUS = 'celsius';

    // The ads/ad-free entitlement (AD-19). `plan` is a live switch, not a stub;
    // no checkout sets `ad_free` in v1 (billing deferred, data-settable only).
    public const PLAN_FREE = 'free';

    public const PLAN_AD_FREE = 'ad_free';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'is_admin' => 'boolean',
            'email_opted_out' => 'boolean',
        ];
    }

    /**
     * Whether the user has confirmed their email by clicking a magic link (AD-6).
     * Unconfirmed users' trips never send (AD-11).
     */
    public function hasConfirmedEmail(): bool
    {
        return $this->email_verified_at !== null;
    }

    /**
     * The single entitlement decision point (AD-19): show a promo to free-tier
     * users, never to ad-free. No other call site re-implements this check.
     */
    public function shouldShowPromo(): bool
    {
        return $this->plan === self::PLAN_FREE;
    }

    /**
     * Mark the email confirmed on first magic-link consume (AD-6). Returns true
     * only on the transition from unconfirmed → confirmed.
     */
    public function confirmEmail(): bool
    {
        if ($this->hasConfirmedEmail()) {
            return false;
        }

        $this->forceFill(['email_verified_at' => now()])->save();

        return true;
    }

    /**
     * Opt out of all email (AD-13): account-level suppression the cadence
     * predicate (AD-11) excludes for every one of this user's trips. Idempotent.
     */
    public function optOut(): void
    {
        if ($this->email_opted_out) {
            return;
        }

        $this->forceFill(['email_opted_out' => true])->save();
    }

    /**
     * Login tokens issued for this user (AD-6).
     *
     * @return HasMany<LoginToken, $this>
     */
    public function loginTokens(): HasMany
    {
        return $this->hasMany(LoginToken::class);
    }

    /**
     * Trips owned by this user (AD-10).
     *
     * @return HasMany<Trip, $this>
     */
    public function trips(): HasMany
    {
        return $this->hasMany(Trip::class);
    }

    /**
     * Sample-tripcast requests made by this user.
     *
     * @return HasMany<SampleRequest, $this>
     */
    public function sampleRequests(): HasMany
    {
        return $this->hasMany(SampleRequest::class);
    }
}
