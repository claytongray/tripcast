<?php

use App\Services\Weather\WeatherApiProvider;
use App\Services\Weather\WeatherKit\WeatherKitProvider;
use App\Services\Weather\WeatherProvider;

it('resolves WeatherKit when the flag is set', function () {
    config()->set('tripcast.forecast.provider', 'weatherkit');
    config()->set('services.weatherkit', [
        'team_id' => 'T', 'service_id' => 'S', 'key_id' => 'K',
        'private_key_path' => 'tests/Fixtures/weatherkit/throwaway.p8',
    ]);

    expect(app(WeatherProvider::class))->toBeInstanceOf(WeatherKitProvider::class);
});

it('resolves WeatherAPI by default when keyed', function () {
    config()->set('tripcast.forecast.provider', 'weatherapi');
    config()->set('services.weatherapi.key', 'test-key');

    expect(app(WeatherProvider::class))->toBeInstanceOf(WeatherApiProvider::class);
});
