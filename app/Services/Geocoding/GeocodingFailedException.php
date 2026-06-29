<?php

namespace App\Services\Geocoding;

use RuntimeException;

/**
 * Thrown by a Geocoder adapter when a destination cannot be resolved to a
 * usable place. Caught at the controller boundary and surfaced as an inline
 * form error — never leaks a vendor exception (AD-1, AD-8).
 */
class GeocodingFailedException extends RuntimeException {}
