<?php

namespace App\Services\Weather;

/**
 * Weather port (AD-1). Code depends on this interface; the vendor SDK/HTTP
 * appears only in a concrete adapter bound in a ServiceProvider. Weather is
 * requested by coordinates only — no geocoding dependency.
 */
interface WeatherProvider
{
    /**
     * Fetch a fresh forecast for the given coordinates, covering today through
     * the configured forecast horizon (`tripcast.forecast.horizon_days`).
     *
     * `$timezone` (IANA) aligns daily highs to the destination's local day;
     * adapters that don't need it ignore it (WeatherKit requires it — AD-1).
     *
     * @throws WeatherProviderFailedException when the forecast can't be fetched.
     */
    public function fetchForecast(float $latitude, float $longitude, ?string $timezone = null): Forecast;
}
