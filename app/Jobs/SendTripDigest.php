<?php

namespace App\Jobs;

use App\Models\Trip;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Per-trip send job (AD-2). Dispatched once per due trip by SendDailyDigests.
 *
 * `tries = 1` (AD-4): the queue must never re-dispatch it — Story 2.3 makes the
 * claim-first email_logs row the dedup authority, and a re-dispatch would hit
 * its own claim. The actual work (claim → fetch forecast → persist snapshot →
 * render → deliver with bounded retry) is built in Stories 2.3 and 2.4.
 */
class SendTripDigest implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(
        public Trip $trip,
        public string $sendDate,
    ) {}

    public function handle(): void
    {
        // Story 2.3: claim the email_logs row (unique trip_id+send_date), fetch the
        //   forecast once (WeatherProvider, Story 2.1), persist the snapshot.
        // Story 2.4: render the Blade digest and deliver with bounded in-process retry.
    }
}
