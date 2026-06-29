<?php

namespace App\Console\Commands;

use App\Digest\CadencePredicate;
use App\Jobs\SendTripDigest;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('digests:send')]
#[Description('Select trips due a daily digest today and dispatch one send job each (AD-2).')]
class SendDailyDigests extends Command
{
    /**
     * AD-2: this command does exactly two things — compute the due set (via the one
     * cadence predicate, AD-11) and dispatch one SendTripDigest job per due trip.
     * No per-trip work happens here. "Today" is the fixed America/New_York send
     * clock (AD-7). (CompleteExpiredTrips / PurgeForecastHistory sweeps and the
     * run-liveness heartbeat are added in their own stories — AD-5/AD-16/AD-14.)
     */
    public function handle(CadencePredicate $cadence): int
    {
        $today = now('America/New_York');
        $due = $cadence->dueOn($today);

        foreach ($due as $trip) {
            SendTripDigest::dispatch($trip, $today->toDateString());
        }

        $this->info("Dispatched {$due->count()} digest job(s) for {$today->toDateString()}.");

        return self::SUCCESS;
    }
}
