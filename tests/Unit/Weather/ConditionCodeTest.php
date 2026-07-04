<?php

use App\Digest\WeatherEmoji;
use App\Services\Weather\WeatherKit\ConditionCode;

it('turns PascalCase condition codes into spaced labels', function (string $code, string $label) {
    expect(ConditionCode::label($code))->toBe($label);
})->with([
    ['Clear', 'Clear'],
    ['MostlyClear', 'Mostly Clear'],
    ['PartlyCloudy', 'Partly Cloudy'],
    ['ScatteredThunderstorms', 'Scattered Thunderstorms'],
    ['HeavyRain', 'Heavy Rain'],
    ['Thunderstorms', 'Thunderstorms'],
]);

it('produces labels the existing emoji matcher resolves correctly', function (string $code, string $emoji) {
    expect(WeatherEmoji::for(ConditionCode::label($code)))->toBe($emoji);
})->with([
    ['PartlyCloudy', '⛅'],
    ['ScatteredThunderstorms', '⛈️'],
    ['HeavyRain', '🌧️'],
    ['MostlyClear', '☀️'],
    ['Breezy', '💨'],
    ['Hurricane', '⛈️'],
]);
