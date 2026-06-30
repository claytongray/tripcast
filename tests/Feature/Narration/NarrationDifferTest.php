<?php

use App\Services\Narration\NarrationContext;
use App\Services\Narration\NarrationDelta;
use App\Services\Narration\NarrationDiffer;

/**
 * @param  list<array<string, mixed>>  $days
 * @return array{days: list<array<string, mixed>>, limited: bool}
 */
function snap(array $days): array
{
    return ['days' => $days, 'limited' => false];
}

function fday(string $date, ?int $precip = null, ?float $highF = null, ?float $highC = null): array
{
    return [
        'date' => $date, 'conditionText' => 'Sunny', 'precipChance' => $precip,
        'highF' => $highF, 'highC' => $highC, 'lowF' => 50.0, 'lowC' => 10.0,
    ];
}

function diffCtx(array $prior, array $current, bool $celsius = false): NarrationContext
{
    // Trip window 2026-07-10 .. 2026-07-17.
    return new NarrationContext($prior, $current, $celsius, '2026-07-10', '2026-07-17');
}

it('detects a notable precip swing', function () {
    $deltas = (new NarrationDiffer)->diff(diffCtx(
        snap([fday('2026-07-12', precip: 60)]),
        snap([fday('2026-07-12', precip: 20)]),
    ));

    expect($deltas)->toHaveCount(1)
        ->and($deltas[0]->metric)->toBe(NarrationDelta::METRIC_RAIN)
        ->and($deltas[0]->from)->toBe(60)
        ->and($deltas[0]->to)->toBe(20);
});

it('detects a notable high-temp swing', function () {
    $deltas = (new NarrationDiffer)->diff(diffCtx(
        snap([fday('2026-07-12', highF: 70.0)]),
        snap([fday('2026-07-12', highF: 55.0)]),
    ));

    expect($deltas)->toHaveCount(1)
        ->and($deltas[0]->metric)->toBe(NarrationDelta::METRIC_HIGH)
        ->and($deltas[0]->from)->toBe(70)
        ->and($deltas[0]->to)->toBe(55);
});

it('ignores a sub-threshold change', function () {
    $deltas = (new NarrationDiffer)->diff(diffCtx(
        snap([fday('2026-07-12', precip: 60)]),
        snap([fday('2026-07-12', precip: 50)]), // 10 < default 25
    ));

    expect($deltas)->toBeEmpty();
});

it('ignores days outside the trip window', function () {
    $deltas = (new NarrationDiffer)->diff(diffCtx(
        snap([fday('2026-07-20', precip: 60)]),
        snap([fday('2026-07-20', precip: 20)]), // outside [07-10, 07-17]
    ));

    expect($deltas)->toBeEmpty();
});

it('ignores a date present in only one snapshot', function () {
    $deltas = (new NarrationDiffer)->diff(diffCtx(
        snap([fday('2026-07-12', precip: 60)]),
        snap([fday('2026-07-13', precip: 20)]),
    ));

    expect($deltas)->toBeEmpty();
});

it('returns nothing when there is no prior snapshot', function () {
    $deltas = (new NarrationDiffer)->diff(diffCtx(
        prior: ['days' => [], 'limited' => true], // gets normalized; but null path:
        current: snap([fday('2026-07-12', precip: 20)]),
    ));

    expect($deltas)->toBeEmpty();

    $nullPrior = new NarrationContext(null, snap([fday('2026-07-12', precip: 20)]), false, '2026-07-10', '2026-07-17');
    expect((new NarrationDiffer)->diff($nullPrior))->toBeEmpty();
});

it('sorts deltas by magnitude, largest first', function () {
    $deltas = (new NarrationDiffer)->diff(diffCtx(
        snap([fday('2026-07-12', precip: 60, highF: 70.0)]),
        snap([fday('2026-07-12', precip: 20, highF: 55.0)]), // rain Δ40, temp Δ15
    ));

    expect($deltas)->toHaveCount(2)
        ->and($deltas[0]->metric)->toBe(NarrationDelta::METRIC_RAIN) // 40 first
        ->and($deltas[1]->metric)->toBe(NarrationDelta::METRIC_HIGH);
});

it('respects a configurable threshold', function () {
    config(['tripcast.narration.notable.precip' => 5]);

    $deltas = (new NarrationDiffer)->diff(diffCtx(
        snap([fday('2026-07-12', precip: 60)]),
        snap([fday('2026-07-12', precip: 50)]), // 10 >= 5 now
    ));

    expect($deltas)->toHaveCount(1);
});

it('uses the celsius high field when the owner prefers celsius', function () {
    $deltas = (new NarrationDiffer)->diff(diffCtx(
        snap([fday('2026-07-12', highF: 70.0, highC: 21.0)]),
        snap([fday('2026-07-12', highF: 70.0, highC: 10.0)]), // only highC swings (11°)
        celsius: true,
    ));

    expect($deltas)->toHaveCount(1)
        ->and($deltas[0]->from)->toBe(21)
        ->and($deltas[0]->to)->toBe(10);
});
