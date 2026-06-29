<?php

namespace App\Services\Geocoding;

/**
 * The resolved geocoding result for a destination (AD-8).
 */
final class GeocodeResult
{
    public function __construct(
        public string $canonicalPlaceName,
        public float $latitude,
        public float $longitude,
    ) {}
}
