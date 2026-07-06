<?php

it('exposes the weatherkit credential config keys', function () {
    config()->set('services.weatherkit', [
        'team_id' => 'TEAM123456',
        'service_id' => 'com.example.app',
        'key_id' => 'KEY1234567',
        'private_key_path' => 'weatherkit-private-key.p8',
    ]);

    expect(config('services.weatherkit.team_id'))->toBe('TEAM123456')
        ->and(config('services.weatherkit.service_id'))->toBe('com.example.app');
});

it('defaults the forecast provider to weatherapi and a fallback timezone', function () {
    expect(config('tripcast.forecast.provider'))->toBe('weatherapi')
        ->and(config('tripcast.forecast.default_timezone'))->toBe('America/New_York');
});

it('defaults the forecast horizon to 9 days ahead (a full 10-day WeatherKit forecast)', function () {
    expect(config('tripcast.forecast.horizon_days'))->toBe(9);
});
