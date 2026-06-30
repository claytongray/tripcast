<?php

namespace App\Services\Narration;

/**
 * The live narrator (AD-17): templates the single most-notable computed delta
 * into a calm, never-alarmist sentence. No network, zero latency, and it can
 * never invent a figure or alarm — every number comes straight from the
 * snapshots via {@see NarrationDiffer}. Returns null when nothing's notable.
 */
class DeterministicNarrator implements Narrator
{
    public function __construct(private NarrationDiffer $differ) {}

    public function narrate(NarrationContext $context): ?string
    {
        $deltas = $this->differ->diff($context);

        if ($deltas === []) {
            return null;
        }

        return $this->sentence($deltas[0], $context->celsius);
    }

    private function sentence(NarrationDelta $delta, bool $celsius): string
    {
        if ($delta->metric === NarrationDelta::METRIC_RAIN) {
            $verb = $delta->to < $delta->from ? 'dropped' : 'climbed';

            return "Since yesterday, {$delta->dayLabel}'s rain chance {$verb} from {$delta->from}% to {$delta->to}%.";
        }

        $unit = $celsius ? '°C' : '°F';
        $verb = $delta->to < $delta->from ? 'cooled' : 'warmed';

        return "Since yesterday, {$delta->dayLabel}'s high {$verb} from {$delta->from}{$unit} to {$delta->to}{$unit}.";
    }
}
