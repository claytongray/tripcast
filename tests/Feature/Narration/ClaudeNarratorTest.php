<?php

use App\Services\Narration\ClaudeNarrator;
use App\Services\Narration\NarrationContext;
use App\Services\Narration\NarrationDiffer;

// The live HTTP path is exercised via `narrator:compare`, never in tests — these
// assert the off-path guards that keep the API from being hit.

it('returns null without calling the API when there is no notable change', function () {
    config(['tripcast.narration.api_key' => 'sk-should-not-be-used']);

    $line = (new ClaudeNarrator(new NarrationDiffer))->narrate(new NarrationContext(
        snap([fday('2026-07-12', precip: 60)]),
        snap([fday('2026-07-12', precip: 58)]), // sub-threshold → no deltas → no call
        false, '2026-07-10', '2026-07-17',
    ));

    expect($line)->toBeNull();
});

it('returns null when no API key is configured even with a notable change', function () {
    config(['tripcast.narration.api_key' => null]);

    $line = (new ClaudeNarrator(new NarrationDiffer))->narrate(new NarrationContext(
        snap([fday('2026-07-12', precip: 60)]),
        snap([fday('2026-07-12', precip: 20)]), // notable, but no key → no call
        false, '2026-07-10', '2026-07-17',
    ));

    expect($line)->toBeNull();
});
