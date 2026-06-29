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
     * Fetch a fresh 7-day forecast for the given coordinates.
     *
     * @throws WeatherProviderFailedException when the forecast can't be fetched.
     */
    public function fetchForecast(float $latitude, float $longitude): Forecast;
}
