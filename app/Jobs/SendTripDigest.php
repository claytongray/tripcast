<?php

namespace App\Jobs;

use App\Mail\DigestMail;
use App\Models\EmailLog;
use App\Models\Trip;
use App\Models\User;
use App\Services\Narration\ClaudeNarrator;
use App\Services\Narration\NarrationContext;
use App\Services\Narration\Narrator;
use App\Services\Weather\WeatherProvider;
use App\Services\Weather\WeatherProviderFailedException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

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

        // Persist the snapshot once, before any delivery (AD-3/AD-9), so a later
        // delivery retry renders from it and never re-fetches weather.
        $snapshot = $forecast->toArray();
        $log->update(['weather_snapshot' => $snapshot]);

        // Day-over-day narration (AD-17): after the snapshot is secured, before
        // render. Computed once here — never inside the delivery retry, never on
        // the claim, never re-fetching weather; any failure → no line.
        $narration = $this->narrate($snapshot);

        $this->deliver($log, $snapshot, $narration);
    }

    /**
     * Build the calm day-over-day line (AD-17). Reads the prior send's snapshot
     * for this trip (AD-9, read-only), runs the live deterministic narrator, and
     * — when shadow is enabled — logs the Claude line alongside for comparison.
     * Strictly off the delivery path: any error yields a null line, never a
     * failed or delayed send.
     *
     * @param  array{days: list<array<string, mixed>>, limited: bool}  $snapshot
     */
    private function narrate(array $snapshot): ?string
    {
        $prior = EmailLog::query()
            ->where('trip_id', $this->trip->id)
            ->where('send_date', '<', $this->sendDate)
            ->whereNotNull('weather_snapshot')
            ->orderByDesc('send_date')
            ->first()?->weather_snapshot;

        $context = new NarrationContext(
            priorSnapshot: $prior,
            currentSnapshot: $snapshot,
            celsius: $this->trip->user->temperature_unit === User::UNIT_CELSIUS,
            departureDate: $this->trip->departure_date->toDateString(),
            returnDate: $this->trip->return_date->toDateString(),
        );

        $line = $this->narrateSafely(app(Narrator::class), $context);

        if (config('tripcast.narration.shadow')) {
            $shadow = $this->narrateSafely(app(ClaudeNarrator::class), $context);

            Log::info('narrator:compare', [
                'trip_id' => $this->trip->id,
                'send_date' => $this->sendDate,
                'deterministic' => $line,
                'claude' => $shadow,
            ]);
        }

        return $line;
    }

    /**
     * Run a narrator, swallowing any failure (AD-17: never break/delay the send).
     */
    private function narrateSafely(Narrator $narrator, NarrationContext $context): ?string
    {
        try {
            return $narrator->narrate($context);
        } catch (Throwable $e) {
            Log::warning('narration failed', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Render + deliver the digest from the persisted snapshot with bounded,
     * in-process retry (AD-4). The job stays tries = 1 (the queue must never
     * re-dispatch); retry is delivery-only — weather is never re-fetched. Always
     * terminal: `sent`, or `failed` + reason (recovered by the next day's run).
     *
     * @param  array{days: list<array<string, mixed>>, limited: bool}  $snapshot
     */
    private function deliver(EmailLog $log, array $snapshot, ?string $narration): void
    {
        $maxAttempts = (int) config('tripcast.send.max_delivery_attempts');
        $lastError = null;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                Mail::to($this->trip->user->email)->send(
                    new DigestMail($this->trip, $snapshot, $this->sendDate, $narration),
                );

                $log->update(['status' => EmailLog::STATUS_SENT]);

                return;
            } catch (Throwable $e) {
                $lastError = $e;
            }
        }

        // Never a broken digest (AD-4): bounded retries exhausted → terminal
        // failure, recovered by the next day's run (a new send_date).
        $log->update([
            'status' => EmailLog::STATUS_FAILED,
            'failure_reason' => 'delivery: '.$lastError?->getMessage(),
        ]);
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
