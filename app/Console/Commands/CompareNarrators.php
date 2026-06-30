<?php

namespace App\Console\Commands;

use App\Models\Trip;
use App\Models\User;
use App\Services\Narration\ClaudeNarrator;
use App\Services\Narration\DeterministicNarrator;
use App\Services\Narration\NarrationContext;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Evaluation tool (Story 4.2): print the deterministic vs Claude narration line
 * side-by-side over each trip's latest consecutive snapshot pair from email_logs.
 * Read-only — no sends, no DB writes; the shadow comparison on demand.
 */
#[Signature('narrator:compare {--trip= : Limit to one trip id} {--limit=20 : Max trips to compare}')]
#[Description('Compare the deterministic and Claude narrators over real snapshot pairs (read-only).')]
class CompareNarrators extends Command
{
    public function handle(DeterministicNarrator $deterministic, ClaudeNarrator $claude): int
    {
        $query = Trip::withTrashed()->with('user');

        if ($tripId = $this->option('trip')) {
            $query->whereKey($tripId);
        }

        $rows = [];

        foreach ($query->limit((int) $this->option('limit'))->get() as $trip) {
            $snapshots = $trip->emailLogs()
                ->whereNotNull('weather_snapshot')
                ->orderByDesc('send_date')
                ->limit(2)
                ->get();

            if ($snapshots->count() < 2) {
                continue; // need a prior snapshot to diff
            }

            $context = new NarrationContext(
                priorSnapshot: $snapshots[1]->weather_snapshot,
                currentSnapshot: $snapshots[0]->weather_snapshot ?? ['days' => [], 'limited' => true],
                celsius: $trip->user->temperature_unit === User::UNIT_CELSIUS,
                departureDate: $trip->departure_date->toDateString(),
                returnDate: $trip->return_date->toDateString(),
            );

            $rows[] = [
                'trip' => (string) $trip->id,
                'place' => $trip->canonical_place_name,
                'deterministic' => $deterministic->narrate($context) ?? '—',
                'claude' => $claude->narrate($context) ?? '—',
            ];
        }

        if ($rows === []) {
            $this->info('No trips with a prior snapshot to compare.');

            return self::SUCCESS;
        }

        $this->table(['Trip', 'Place', 'Deterministic', 'Claude (shadow)'], $rows);

        return self::SUCCESS;
    }
}
