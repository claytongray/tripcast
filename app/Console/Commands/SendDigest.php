<?php

namespace App\Console\Commands;

use App\Mail\DigestMail;
use App\Models\Trip;
use App\Services\Weather\WeatherProvider;
use App\Services\Weather\WeatherProviderFailedException;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;

/**
 * Manual digest send for testing/QA. Bypasses cadence (AD-11) AND the claim
 * (AD-3) — it writes no email_logs row, so it never interferes with the real
 * daily run's dedup. Fetches a fresh forecast and renders the exact DigestMail
 * a user would receive, to any address. NOT part of the production send path.
 */
#[Signature('digest:send {trip : The trip ID} {--to= : Override recipient (defaults to the trip owner)} {--date= : Send-clock date Y-m-d (defaults to today, America/New_York)}')]
#[Description('Render and send a digest for one trip to a chosen address (testing only — no email_logs row).')]
class SendDigest extends Command
{
    public function handle(WeatherProvider $weather): int
    {
        $trip = Trip::find((int) $this->argument('trip'));

        if ($trip === null) {
            $this->error("No trip found with ID {$this->argument('trip')}.");

            return self::FAILURE;
        }

        $date = $this->resolveDate();

        if ($date === null) {
            return self::FAILURE;
        }

        $recipient = $this->option('to') ?: $trip->user->email;

        try {
            $forecast = $weather->fetchForecast($trip->latitude, $trip->longitude);
        } catch (WeatherProviderFailedException $e) {
            $this->error("Forecast fetch failed: {$e->getMessage()}");

            return self::FAILURE;
        }

        Mail::to($recipient)->send(new DigestMail($trip, $forecast->toArray(), $date));

        $place = (string) $trip->canonical_place_name;
        $this->info("Sent digest for trip {$trip->id} ({$place}) to {$recipient} for {$date}.");

        return self::SUCCESS;
    }

    /**
     * The send-clock calendar date (America/New_York today by default). Returns
     * null on an unparseable --date so the command fails cleanly.
     */
    private function resolveDate(): ?string
    {
        $option = $this->option('date');

        if ($option === null) {
            return now('America/New_York')->toDateString();
        }

        try {
            return Carbon::createFromFormat('Y-m-d', $option, 'America/New_York')->toDateString();
        } catch (\Throwable) {
            $this->error("Invalid --date '{$option}'. Expected Y-m-d (e.g. 2026-06-29).");

            return null;
        }
    }
}
