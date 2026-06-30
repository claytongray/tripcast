<?php

namespace App\Models;

use DomainException;

/**
 * Thrown when a Trip status change violates AD-5: an unknown target status, or
 * any attempt to leave the terminal `completed` state.
 */
class InvalidTripTransitionException extends DomainException {}
