<?php

namespace App\Services\Geocoding;

/**
 * Geocoding port (AD-1). Code depends on this interface; the vendor SDK/HTTP
 * appears only in a concrete adapter bound in a ServiceProvider.
 */
interface Geocoder
{
    /**
     * Resolve a free-text destination to a canonical place + coordinates.
     *
     * @throws GeocodingFailedException when no usable result exists.
     */
    public function geocode(string $destination): GeocodeResult;
}
