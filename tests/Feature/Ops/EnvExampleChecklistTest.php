<?php

use App\Services\Geocoding\Geocoder;
use App\Services\Geocoding\PlaceAutocomplete;
use App\Services\Weather\WeatherProvider;

// Story 9.6 (FR-27) — AC1's "env checklist is complete", made testable: every
// production-required env var must exist in .env.example as a real assignment
// (line-anchored — the go-live comment block naming the same keys must not
// satisfy this), and the production fail-fast guards must actually guard.

it('lists every production-required env var in .env.example', function (string $key) {
    $contents = file_get_contents(base_path('.env.example'));

    expect($contents)->toMatch('/^'.preg_quote($key, '/').'=/m');
})->with([
    'APP_KEY',
    'APP_URL',
    'DB_CONNECTION',
    'DB_HOST',
    'DB_DATABASE',
    'DB_USERNAME',
    'DB_PASSWORD',
    'QUEUE_CONNECTION',
    'CACHE_STORE',
    'REDIS_CLIENT',
    'REDIS_HOST',
    'REDIS_PORT',
    'MAIL_MAILER',
    'MAIL_FROM_ADDRESS',
    'GOOGLE_GEOCODING_KEY',
    'WEATHERAPI_KEY',
    'MAILERSEND_API_KEY',
    'TRIPCAST_POSTAL_ADDRESS',
    'TRIPCAST_HEARTBEAT_URL',
]);

// The three ports refuse to serve fake data in production when unkeyed —
// the boot-time half of the checklist. (MAILERSEND_API_KEY has no boot
// guard by design; the driver fails at send time.)
it('fails fast in production when the geocoding key is missing', function () {
    $this->app['env'] = 'production';
    config(['services.google.geocoding_key' => null]);

    expect(fn () => app(Geocoder::class))
        ->toThrow(RuntimeException::class, 'GOOGLE_GEOCODING_KEY is not set');
});

it('fails fast in production when the places key is missing', function () {
    $this->app['env'] = 'production';
    config(['services.google.geocoding_key' => null]);

    expect(fn () => app(PlaceAutocomplete::class))
        ->toThrow(RuntimeException::class, 'GOOGLE_GEOCODING_KEY is not set');
});

it('fails fast in production when the weather key is missing', function () {
    $this->app['env'] = 'production';
    config(['services.weatherapi.key' => null]);

    expect(fn () => app(WeatherProvider::class))
        ->toThrow(RuntimeException::class, 'WEATHERAPI_KEY is not set');
});
