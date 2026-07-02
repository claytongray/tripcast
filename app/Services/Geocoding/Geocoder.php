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

    /**
     * Resolve an autocomplete place identifier exactly (FR-22). Passing the
     * autocomplete session token lets the vendor bill the keystrokes as one
     * session. Callers fall back to {@see geocode()} on failure — AD-8's
     * one-resolution-at-creation invariant is unchanged.
     *
     * @throws GeocodingFailedException when the id does not resolve.
     */
    public function resolvePlace(string $placeId, ?string $sessionToken = null): GeocodeResult;
}
