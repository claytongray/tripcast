<?php

namespace App\Policies;

use App\Models\Trip;
use App\Models\User;

/**
 * Ownership authorization for trip management (FR-12). A user may only act on
 * their own trips; everyone else is denied (403). Laravel auto-discovers this
 * policy for {@see Trip}. The admin monitoring view (FR-13, Story 3.4) is a
 * separate Gate and does not pass through here.
 */
class TripPolicy
{
    /**
     * View a single trip (e.g. the add-trip success screen) — owner only.
     */
    public function view(User $user, Trip $trip): bool
    {
        return $trip->user_id === $user->id;
    }

    /**
     * Pause / resume — owner only.
     */
    public function update(User $user, Trip $trip): bool
    {
        return $trip->user_id === $user->id;
    }

    /**
     * Soft-delete — owner only.
     */
    public function delete(User $user, Trip $trip): bool
    {
        return $trip->user_id === $user->id;
    }
}
