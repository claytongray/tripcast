<?php

use App\Services\Narration\DeterministicNarrator;
use App\Services\Narration\NarrationContext;
use App\Services\Narration\NarrationDiffer;

function narrator(): DeterministicNarrator
{
    return new DeterministicNarrator(new NarrationDiffer);
}

function ctx(array $prior, array $current, bool $celsius = false): NarrationContext
{
    return new NarrationContext($prior, $current, $celsius, '2026-07-10', '2026-07-17');
}

it('phrases a rain drop calmly', function () {
    $line = narrator()->narrate(ctx(
        snap([fday('2026-07-12', precip: 60)]),
        snap([fday('2026-07-12', precip: 20)]),
    ));

    expect($line)->toStartWith('Since yesterday,')
        ->and($line)->toContain('rain chance dropped from 60% to 20%');
});

it('phrases a rain climb calmly', function () {
    $line = narrator()->narrate(ctx(
        snap([fday('2026-07-12', precip: 20)]),
        snap([fday('2026-07-12', precip: 70)]),
    ));

    expect($line)->toContain('rain chance climbed from 20% to 70%');
});

it('phrases a temperature drop in the owner unit', function () {
    $line = narrator()->narrate(ctx(
        snap([fday('2026-07-12', highF: 70.0)]),
        snap([fday('2026-07-12', highF: 55.0)]),
    ));

    expect($line)->toContain('high cooled from 70°F to 55°F');
});

it('phrases a temperature rise in celsius when preferred', function () {
    $line = narrator()->narrate(ctx(
        snap([fday('2026-07-12', highC: 14.0)]),
        snap([fday('2026-07-12', highC: 28.0)]),
        celsius: true,
    ));

    expect($line)->toContain('high warmed from 14°C to 28°C');
});

it('returns null when nothing notable changed', function () {
    $line = narrator()->narrate(ctx(
        snap([fday('2026-07-12', precip: 60)]),
        snap([fday('2026-07-12', precip: 55)]),
    ));

    expect($line)->toBeNull();
});

it('returns null with no prior snapshot', function () {
    $line = narrator()->narrate(new NarrationContext(
        null, snap([fday('2026-07-12', precip: 20)]), false, '2026-07-10', '2026-07-17',
    ));

    expect($line)->toBeNull();
});
