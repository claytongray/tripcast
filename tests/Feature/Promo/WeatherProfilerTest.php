<?php

use App\Services\Promo\WeatherProfiler;

function profSnap(array $days): array
{
    return ['days' => $days, 'limited' => false];
}
function profDay(float $highF, int $precip = 10, string $condition = 'Sunny'): array
{
    return ['highF' => $highF, 'precipChance' => $precip, 'conditionText' => $condition];
}

beforeEach(function () {
    $this->profiler = new WeatherProfiler;
});

it('maps snow, hot, cold-wet, and cold', function () {
    expect($this->profiler->profile(profSnap([profDay(30, 40, 'Heavy snow'), profDay(28, 30, 'Snow')])))->toBe('snow')
        ->and($this->profiler->profile(profSnap([profDay(85), profDay(88)])))->toBe('hot')
        ->and($this->profiler->profile(profSnap([profDay(55, 70, 'Rain'), profDay(52, 80, 'Rain')])))->toBe('cold-wet')
        ->and($this->profiler->profile(profSnap([profDay(30), profDay(35)])))->toBe('cold');
});

it('returns null for neutral (mild) weather', function () {
    expect($this->profiler->profile(profSnap([profDay(60), profDay(65)])))->toBeNull();
});

it('returns null for fewer than 2 usable forecast days, before the snow check', function () {
    expect($this->profiler->profile(profSnap([profDay(30, 40, 'Heavy snow')])))->toBeNull() // 1 day, even though snowy
        ->and($this->profiler->profile(profSnap([])))->toBeNull()
        ->and($this->profiler->profile([]))->toBeNull();
});
