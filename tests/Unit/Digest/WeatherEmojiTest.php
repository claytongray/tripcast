<?php

use App\Digest\WeatherEmoji;

it('maps common conditions to a weather emoji', function (string $condition, string $emoji) {
    expect(WeatherEmoji::for($condition))->toBe($emoji);
})->with([
    ['Sunny', '☀️'],
    ['Clear', '☀️'],
    ['Partly cloudy', '⛅'],
    ['Cloudy', '☁️'],
    ['Overcast', '☁️'],
    ['Light rain', '🌧️'],
    ['Patchy light drizzle', '🌧️'],
    ['Thundery outbreaks possible', '⛈️'],
    ['Light snow', '🌨️'],
    ['Blizzard', '🌨️'],
    ['Mist', '🌫️'],
    ['Fog', '🌫️'],
    // Dust / sand / smoke / smog obscuration family (all low-visibility → 🌫️).
    ['Dust', '🌫️'],
    ['Blowing dust', '🌫️'],
    ['Saharan dust', '🌫️'],
    ['Smoke', '🌫️'],
    ['Smog', '🌫️'],
    ['Severe smog', '🌫️'],
]);

it('prioritizes the more specific condition (thunder/snow over rain)', function () {
    expect(WeatherEmoji::for('Moderate or heavy rain with thunder'))->toBe('⛈️')
        ->and(WeatherEmoji::for('Patchy sleet possible'))->toBe('🌨️');
});

it('treats a dust storm / sandstorm as low-visibility, not a thunderstorm', function () {
    expect(WeatherEmoji::for('Dust storm'))->toBe('🌫️')
        ->and(WeatherEmoji::for('Sandstorm'))->toBe('🌫️')
        ->and(WeatherEmoji::for('Severe sandstorm'))->toBe('🌫️');
});

it('returns no emoji for an empty or unrecognized condition', function () {
    expect(WeatherEmoji::for(null))->toBe('')
        ->and(WeatherEmoji::for(''))->toBe('')
        ->and(WeatherEmoji::for('Tornado of frogs'))->toBe('');
});
