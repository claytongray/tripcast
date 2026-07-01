<?php

use App\Digest\ForecastRows;

function snapshotDay(string $date, array $overrides = []): array
{
    return array_merge([
        'date' => $date,
        'conditionText' => 'Sunny',
        'precipChance' => 10,
        'highC' => 20.0, 'highF' => 68.0,
        'lowC' => 12.0, 'lowF' => 54.0,
        'humidity' => 50,
        'feelsLikeHighC' => 20.0, 'feelsLikeHighF' => 68.0,
    ], $overrides);
}

it('projects only the trip-window days, departure first, in Fahrenheit', function () {
    $snapshot = ['days' => [
        snapshotDay('2026-07-01'), // before window — excluded
        snapshotDay('2026-07-02'),
        snapshotDay('2026-07-03'),
    ]];

    $rows = (new ForecastRows)->project($snapshot, '2026-07-02', '2026-07-03', false);

    expect($rows)->toHaveCount(2)
        ->and($rows[0]['isDeparture'])->toBeTrue()
        ->and($rows[0]['high'])->toBe(68)
        ->and($rows[0]['low'])->toBe(54);
});

it('marks a day limited when a core value is missing', function () {
    $snapshot = ['days' => [snapshotDay('2026-07-02', ['conditionText' => null])]];

    $rows = (new ForecastRows)->project($snapshot, '2026-07-02', '2026-07-02', false);

    expect($rows[0]['limited'])->toBeTrue()
        ->and($rows[0]['high'])->toBeNull();
});
