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
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
// `plan` and `is_admin` are intentionally NOT mass-assignable — they are
// privilege/entitlement flags and must be set explicitly (factory, seeder,
// billing), never from request input.
#[Fillable(['email', 'timezone', 'email_opted_out'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

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
}
