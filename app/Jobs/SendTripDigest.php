<?php

namespace App\Jobs;

use App\Models\EmailLog;
use App\Models\PromoEvent;
use App\Models\Trip;
use App\Services\Digest\ComposedDigest;
use App\Services\Digest\DigestComposer;
use App\Services\Promo\Promo;
use App\Services\Weather\WeatherProvider;
use App\Services\Weather\WeatherProviderFailedException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Sleep;
use MailerSend\Exceptions\MailerSendRateLimitException;
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
        public bool $welcome = false,
    ) {}

    public function handle(WeatherProvider $weather): void
    {
        $log = $this->claim();

        if ($log === null) {
            return; // already claimed (in-flight, sent, or failed) — abort, no double-send
        }

        try {
            $forecast = $weather->fetchForecast(
                $this->trip->latitude,
                $this->trip->longitude,
                $this->trip->destination_timezone,
            );
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

        // Assemble narration + promo + the mail once, via the shared composer
        // (AD-17/AD-18). Never inside the delivery retry, never re-fetching weather.
        $composed = app(DigestComposer::class)->compose($this->trip, $snapshot, $this->sendDate, $this->welcome);

        $this->deliver($log, $composed);
    }

    /**
     * Log the promo impression for a sent digest (AD-18), idempotently. Guarded
     * so an attribution failure can never affect the already-delivered send.
     */
    private function recordImpression(?Promo $promo): void
    {
        if ($promo === null) {
            return;
        }

        try {
            PromoEvent::record($this->trip, $this->sendDate, $promo->slug, PromoEvent::EVENT_IMPRESSION);
        } catch (Throwable $e) {
            Log::warning('promo impression failed', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Render + deliver the digest from the composed mail with bounded, in-process
     * retry (AD-4). The job stays tries = 1 (the queue must never re-dispatch);
     * retry is delivery-only — weather is never re-fetched. Always terminal:
     * `sent`, or `failed` + reason (recovered by the next day's run).
     */
    private function deliver(EmailLog $log, ComposedDigest $composed): void
    {
        $maxAttempts = (int) config('tripcast.send.max_delivery_attempts');
        $lastError = null;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                Mail::to($this->trip->user->email)->send($composed->mail);

                $log->update(['status' => EmailLog::STATUS_SENT]);

                // Promo impression (FR-18, AD-18): logged once on the sent path,
                // idempotent per (trip_id, send_date, slug, impression). Guarded —
                // an attribution write must never fail the (already-sent) digest.
                $this->recordImpression($composed->promo);

                return;
            } catch (Throwable $e) {
                $lastError = $e;

                // A 429 is transient: MailerSend is over its per-minute ceiling
                // (incident 2026-07-05). Pause the (single) worker before the next
                // attempt so the rate window can drain — tight-looping only deepens
                // the limit. Dispatch pacing (SendDailyDigests) is the primary
                // guard; this rescues a burst that still slips through. Only pause
                // when another attempt remains, and only for rate limits.
                if ($attempt < $maxAttempts && $this->isRateLimited($e)) {
                    Sleep::for((int) config('tripcast.send.rate_limit_backoff_seconds'))->seconds();
                }
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
     * Was this delivery failure a MailerSend rate limit (429)? The vendor
     * transport flattens `MailerSendRateLimitException` into a Symfony
     * `TransportException` that preserves only the message and code (429) — the
     * real `retry-after` header is lost — so we detect the 429 by code (and the
     * typed exception, should any path preserve it) across the whole cause chain.
     */
    private function isRateLimited(Throwable $e): bool
    {
        for ($cause = $e; $cause !== null; $cause = $cause->getPrevious()) {
            if ($cause instanceof MailerSendRateLimitException || $cause->getCode() === 429) {
                return true;
            }
        }

        return false;
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
