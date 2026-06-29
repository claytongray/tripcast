<?php

use App\Services\Weather\FakeWeatherProvider;
use App\Services\Weather\WeatherProvider;

// With no key configured (phpunit env), the container resolves the fake.
it('binds the fake weather provider when no key is configured', function () {
    expect(app(WeatherProvider::class))->toBeInstanceOf(FakeWeatherProvider::class);
});

// The fake returns a deterministic complete 7-day forecast for normal coords.
it('fake returns a deterministic complete forecast', function () {
    $forecast = (new FakeWeatherProvider)->fetchForecast(55.9533, -3.1883);

    expect($forecast->days)->toHaveCount(7)
        ->and($forecast->isLimited())->toBeFalse()
        ->and($forecast->days[0]->highC)->toBe(18.0)
        ->and($forecast->days[0]->highF)->toBe(64.4);
});

// The sentinel latitude exercises the limited-data path.
it('fake returns a limited forecast for the sentinel coordinate', function () {
    $forecast = (new FakeWeatherProvider)->fetchForecast(0.0, 0.0);

    expect($forecast->isLimited())->toBeTrue()
        ->and($forecast->days)->toHaveCount(3)
        ->and($forecast->days[1]->isLimited())->toBeTrue()
        ->and($forecast->days[1]->highC)->toBeNull();
});
