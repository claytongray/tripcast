<?php

namespace App\Console\Commands;

use App\Actions\PurgeForecastHistory;
use App\Digest\CadencePredicate;
use App\Jobs\SendTripDigest;
use Carbon\CarbonInterface;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

#[Signature('digests:send')]
#[Description('Select trips due a daily digest today and dispatch one send job each (AD-2).')]
class SendDailyDigests extends Command
{
    /** Cache key holding the last run's liveness snapshot for the admin panel (AD-14). */
    public const LAST_RUN_CACHE_KEY = 'admin:digests:last_run';

    /**
     * AD-2: this command does exactly two things — compute the due set (via the one
     * cadence predicate, AD-11) and dispatch one SendTripDigest job per due trip.
     * No per-trip send work happens here. "Today" is the fixed America/New_York
     * send clock (AD-7). It also runs the selection-only forecast-history
     * retention sweep (PurgeForecastHistory, AD-16) — guarded so it can never
     * affect the run's health. (CompleteExpiredTrips is its own later story; the
     * run-liveness heartbeat is AD-14.)
     */
    public function handle(CadencePredicate $cadence, PurgeForecastHistory $purge): int
    {
        $today = now('America/New_York');
        $startedAt = now();
        $dueCount = 0;
        $dispatched = 0;

        try {
            $due = $cadence->dueOn($today);
            $dueCount = $due->count();

            foreach ($due as $trip) {
                SendTripDigest::dispatch($trip, $today->toDateString());
                $dispatched++;
            }
        } catch (Throwable $e) {
            // The whole-run dead-man's-switch (AD-14): a select/dispatch failure
            // is a run-level failure — fail-ping the monitor and exit non-zero,
            // never letting the exception escape the scheduled command.
            $this->recordRun($dueCount, $dispatched, false, $startedAt, $e->getMessage());
            $this->emitHeartbeat(false);
            $this->error("Daily digest run failed: {$e->getMessage()}");

            return self::FAILURE;
        }

        // Forecast-history retention sweep (AD-16), selection-only and guarded:
        // the digests already dispatched, so a purge failure must never fail the
        // run or flip the heartbeat — it logs a warning and the run continues.
        try {
            $purged = $purge->handle($today);
            Log::info('digests:purge', ['purged' => $purged]);
        } catch (Throwable $e) {
            Log::warning('digests:purge failed', ['error' => $e->getMessage()]);
        }

        // Unhealthy if trips were due but nothing dispatched (AD-14): a silent
        // no-op when there was work is exactly the failure mode to alert on.
        $healthy = ! ($dueCount > 0 && $dispatched === 0);

        $this->recordRun($dueCount, $dispatched, $healthy, $startedAt);
        $this->emitHeartbeat($healthy);

        $this->info("Dispatched {$dispatched} digest job(s) for {$today->toDateString()}.");

        return $healthy ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Record the run-level outcome (AD-14) as structured logging — the whole-run
     * signal above the per-trip email_logs rows (AD-9). No new table. Also caches
     * the snapshot so the admin panel (Story 7.5) can surface last-run liveness
     * without parsing logs.
     */
    private function recordRun(int $due, int $dispatched, bool $healthy, CarbonInterface $startedAt, ?string $error = null): void
    {
        $durationMs = (int) $startedAt->diffInMilliseconds(now());

        Log::info('digests:run', [
            'due' => $due,
            'dispatched' => $dispatched,
            'healthy' => $healthy,
            'duration_ms' => $durationMs,
            'error' => $error,
        ]);

        Cache::put(self::LAST_RUN_CACHE_KEY, [
            'healthy' => $healthy,
            'due' => $due,
            'dispatched' => $dispatched,
            'duration_ms' => $durationMs,
            'error' => $error,
            'ran_at' => now()->toIso8601String(),
        ], now()->addDays(14));
    }

    /**
     * Ping the external dead-man's-switch monitor (AD-14): `{url}` on a healthy
     * run, `{url}/fail` otherwise. A null URL is a no-op (local/dev). A ping
     * outage is swallowed — the digests already dispatched, so monitoring must
     * never break the product run.
     */
    private function emitHeartbeat(bool $healthy): void
    {
        $url = config('tripcast.heartbeat.url');

        if (! $url) {
            return;
        }

        $target = $healthy ? $url : rtrim((string) $url, '/').'/fail';

        try {
            Http::timeout((int) config('tripcast.heartbeat.timeout'))->get($target);
        } catch (Throwable $e) {
            Log::warning('digests:heartbeat failed', ['error' => $e->getMessage()]);
        }
    }
}
