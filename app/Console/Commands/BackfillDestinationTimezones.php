<?php

namespace App\Console\Commands;

use App\Models\Trip;
use App\Services\Weather\DestinationTimezone;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

/**
 * One-off, idempotent backfill (Epic 11.2): resolve + persist
 * `destination_timezone` for active trips created before the column existed (or
 * whose creation-time resolve failed). Re-runnable — only touches null zones —
 * so it can be run again to catch stragglers before the 11.3 provider cutover.
 */
#[Signature('trips:backfill-timezones {--chunk=100 : Rows per batch}')]
class BackfillDestinationTimezones extends Command
{
    protected $description = 'Resolve + persist destination_timezone for active trips missing one (idempotent).';

    public function handle(DestinationTimezone $timezones): int
    {
        if (blank(config('services.google.geocoding_key'))) {
            $this->error('GOOGLE_GEOCODING_KEY is not set — cannot resolve timezones.');

            return self::FAILURE;
        }

        $resolved = 0;
        $unresolved = 0;

        Trip::query()
            ->where('status', Trip::STATUS_ACTIVE)
            ->whereNull('destination_timezone')
            ->chunkById(max(1, (int) $this->option('chunk')), function (Collection $trips) use ($timezones, &$resolved, &$unresolved): void {
                foreach ($trips as $trip) {
                    $zone = $timezones->resolve($trip->latitude, $trip->longitude);

                    if ($zone !== null) {
                        $trip->forceFill(['destination_timezone' => $zone])->save();
                        $resolved++;
                        $this->line("  #{$trip->id} {$trip->canonical_place_name} → {$zone}");
                    } else {
                        $unresolved++;
                        $this->warn("  #{$trip->id} {$trip->canonical_place_name} → unresolved (left null)");
                    }
                }
            });

        $this->info("Backfill complete: {$resolved} resolved, {$unresolved} unresolved.");

        return self::SUCCESS;
    }
}
