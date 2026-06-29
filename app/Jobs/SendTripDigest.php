<?php

namespace App\Jobs;

use App\Models\EmailLog;
use App\Models\Trip;
use App\Services\Weather\WeatherProvider;
use App\Services\Weather\WeatherProviderFailedException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Per-trip send job (AD-2, AD-3, AD-4). Dispatched once per due trip.
 *
 * Claim-first: insert the email_logs row before any work — the unique
 * (trip_id, send_date) index is the dedup authority (AD-3). Then fetch the
 * forecast once and persist the snapshot before delivery, so a later delivery
 * retry never re-fetches weather. `tries = 1`: the queue must never re-dispatch.
 *
 * Story 2.4 renders + delivers from the persisted snapshot and sets the terminal
 * `sent`/`failed`. This story leaves the claimed row in `sending` with the
 * snapshot ready (or `failed` if the forecast couldn't be fetched).
 */
class SendTripDigest implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(
        public Trip $trip,
        public string $sendDate,
    ) {}

    public function handle(WeatherProvider $weather): void
    {
        $log = $this->claim();

        if ($log === null) {
            return; // already claimed (in-flight, sent, or failed) — abort, no double-send
        }

        try {
            $forecast = $weather->fetchForecast($this->trip->latitude, $this->trip->longitude);
        } catch (WeatherProviderFailedException $e) {
            // Never a broken digest (AD-4): terminal failure, recovered by the next
            // day's run (a new send_date). Don't leave the row stuck in `sending`.
            $log->update([
                'status' => EmailLog::STATUS_FAILED,
                'failure_reason' => 'weather: '.$e->getMessage(),
            ]);

            return;
        }

        // Persist the snapshot once, before any delivery (AD-3/AD-9). Story 2.4
        // renders from this and sets the terminal sent/failed.
        $log->update(['weather_snapshot' => $forecast->toArray()]);
    }

    /**
     * Claim the (trip_id, send_date) row, or null if already claimed.
     *
     * The insert is the atomic claim (AD-3). On a unique-constraint collision we
     * reclaim only a STALE `sending` row (a crash mid-send) via a conditional
     * UPDATE; a fresh `sending`, `sent`, or `failed` row aborts.
     */
    private function claim(): ?EmailLog
    {
        try {
            return EmailLog::create([
                'trip_id' => $this->trip->id,
                'send_date' => $this->sendDate,
                'status' => EmailLog::STATUS_SENDING,
                'claimed_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            $staleBefore = now()->subMinutes((int) config('tripcast.send.stale_lease_minutes'));

            $reclaimed = EmailLog::query()
                ->where('trip_id', $this->trip->id)
                ->where('send_date', $this->sendDate)
                ->where('status', EmailLog::STATUS_SENDING)
                ->where('claimed_at', '<', $staleBefore)
                ->update(['claimed_at' => now()]);

            if ($reclaimed !== 1) {
                return null; // fresh in-flight, or terminal — leave it alone
            }

            return EmailLog::query()
                ->where('trip_id', $this->trip->id)
                ->where('send_date', $this->sendDate)
                ->first();
        }
    }
}
