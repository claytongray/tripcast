<?php

namespace App\Actions;

use App\Models\EmailLog;
use App\Models\Trip;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * The bounded-retention sweep over the forecast-history series (AD-16). Nulls
 * **only** `email_logs.weather_snapshot` once the owning Trip's `return_date` is
 * older than the configured retention horizon — anchored on `return_date`, never
 * `send_date`/`created_at`, so it can never race AD-17's in-window prior-snapshot
 * read. The send-outcome row (`status`, `failure_reason`, dates, `claimed_at`)
 * survives, preserving the AD-5/AD-9 audit trail and `feedback` joins. This is
 * the one intentional lifecycle mutation on the snapshot payload.
 */
class PurgeForecastHistory
{
    /**
     * Null aged-out snapshots and return the number of rows purged. "Today" is
     * the America/New_York send clock (AD-7).
     */
    public function handle(?CarbonInterface $today = null): int
    {
        $today ??= Carbon::now('America/New_York');

        $cutoff = $today->copy()
            ->subDays((int) config('tripcast.forecast.retention_days'))
            ->toDateString();

        // withTrashed() is required: a soft-deleted Trip's snapshots must still
        // age out (the horizon is anchored on return_date, independent of status
        // or deletion). update() writes SQL NULL, leaving the outcome columns.
        return EmailLog::query()
            ->whereNotNull('weather_snapshot')
            ->whereIn('trip_id', Trip::withTrashed()
                ->whereDate('return_date', '<=', $cutoff)
                ->select('id'))
            ->update(['weather_snapshot' => null]);
    }
}
