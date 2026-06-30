<?php

namespace App\Actions;

use DomainException;

/**
 * Thrown by {@see CreateTrip} when an add would exceed the free-tier active-trip
 * cap (AD-15). Pure cost-control — there is no upsell or billing path; callers
 * surface the calm message and the option to pause or remove an existing trip.
 */
class TripLimitReachedException extends DomainException
{
    public function __construct(
        string $message = "You're watching the most trips your plan allows. Pause or remove one to add another.",
    ) {
        parent::__construct($message);
    }
}
