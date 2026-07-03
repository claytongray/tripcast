<?php

namespace App\Actions;

use App\Digest\CadencePredicate;
use App\Jobs\SendTripDigest;
use App\Mail\WelcomeMail;
use App\Models\Trip;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;

/**
 * The single welcome decision point (FR-9), honoring account-level opt-out
 * (AD-13). Fired when a trip becomes real-for-sending: at creation for an
 * already-confirmed owner, or at email confirmation for a new signup.
 *
 * If the trip is already inside the Forecast Window today, the welcome IS the
 * first tripcast: dispatch the send job in welcome mode, which claims today's
 * (trip_id, send_date) slot — so the 7am run skips this trip and the traveller
 * gets value immediately instead of waiting. Otherwise send the calm heads-up
 * welcome (with its sample offer) and let the daily cadence begin on schedule.
 */
class SendWelcomeEmail
{
    public function __construct(private CadencePredicate $cadence) {}

    public function handle(Trip $trip): void
    {
        if ($trip->user->email_opted_out) {
            return;
        }

        $today = CarbonImmutable::now('America/New_York');

        if ($this->cadence->isDue($trip, $today)) {
            SendTripDigest::dispatch($trip, $today->toDateString(), welcome: true);

            return;
        }

        Mail::to($trip->user->email)->queue(new WelcomeMail($trip));
    }
}
