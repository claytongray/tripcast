<?php

namespace App\Console\Commands;

use App\Mail\DigestMail;
use App\Models\Trip;
use App\Models\User;
use App\Services\Narration\NarrationContext;
use App\Services\Narration\Narrator;
use App\Services\Promo\PromoProvider;
use App\Services\Weather\Forecast;
use App\Services\Weather\ForecastDay;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

/**
 * Dev tool — send three sample digests to a mailbox so the forecast-window
 * rendering states can be eyeballed: (1) a trip fully within the forecast window
 * (full forecast), (2) a longer trip partly beyond it (some days + "we'll have
 * the full picture tomorrow"), and (3) a trip 6 days out (only the first day in
 * the window). Dates are computed from the current date. Weather is synthetic
 * and spans exactly the forecast horizon — the live WeatherAPI free tier only
 * returns ~3 days, which can't exercise these boundaries. No DB writes, no send
 * job — it builds and mails the digests directly. Never runs in production.
 */
#[Signature('digests:preview {--email=preview@tripcast.test : Recipient mailbox}')]
#[Description('Email three sample digests (full / partial / one-day forecast windows) for visual testing.')]
class PreviewDigests extends Command
{
    public function handle(): int
    {
        if (app()->isProduction()) {
            $this->error('Refusing to run in production.');

            return self::FAILURE;
        }

        $email = (string) $this->option('email');
        $today = CarbonImmutable::now('America/New_York')->startOfDay();
        $horizon = (int) config('tripcast.forecast.horizon_days');
        $sendDate = $today->toDateString();

        $saturday = $today->dayOfWeek === CarbonInterface::SATURDAY ? $today : $today->next(CarbonInterface::SATURDAY);

        $scenarios = [
            [
                'place' => 'Ocean City, NJ', 'lat' => 39.2776, 'lng' => -74.5746, 'baseF' => 84, 'condition' => 'Sunny',
                'departure' => $saturday, 'return' => $saturday->addDay(),
                'shows' => 'this weekend — fully within the window (full forecast)',
            ],
            [
                'place' => 'Columbus, OH', 'lat' => 39.9612, 'lng' => -82.9988, 'baseF' => 70, 'condition' => 'Partly cloudy',
                'departure' => $today->addDays(3), 'return' => $today->addDays(9),
                'shows' => 'next-week trip — partly beyond the window (some days + "more info soon")',
            ],
            [
                'place' => 'Asheville, NC', 'lat' => 35.5951, 'lng' => -82.5515, 'baseF' => 63, 'condition' => 'Cloudy',
                'departure' => $today->addDays(6), 'return' => $today->addDays(9),
                'shows' => '6 days out — only the first day is in the window',
            ],
        ];

        $rows = [];

        foreach ($scenarios as $s) {
            $trip = $this->trip($s, $email);
            $snapshot = $this->forecast($today, $horizon, $s['baseF'], $s['condition']);

            $context = new NarrationContext($this->priorOf($snapshot), $snapshot, false,
                $trip->departure_date->toDateString(), $trip->return_date->toDateString());
            $narration = app(Narrator::class)->narrate($context);
            $promo = app(PromoProvider::class)->select($snapshot, $sendDate);

            Mail::to($email)->send(new DigestMail($trip, $snapshot, $sendDate, $narration, $promo));

            $rows[] = [$s['place'], $s['departure']->toDateString().' → '.$s['return']->toDateString(), $s['shows']];
        }

        $this->table(['Trip', 'Window', 'What it shows'], $rows);
        $this->info("Sent 3 sample digests to {$email} (synthetic {$horizon}-day forecast, dates from {$sendDate}).");

        return self::SUCCESS;
    }

    /**
     * Build an unsaved, owned trip — enough for DigestMail to render (ids drive
     * the signed footer URLs); no DB writes.
     *
     * @param  array<string, mixed>  $s
     */
    private function trip(array $s, string $email): Trip
    {
        $user = new User(['email' => $email, 'temperature_unit' => User::UNIT_FAHRENHEIT]);
        $user->id = 90001;
        $user->plan = User::PLAN_FREE;

        $trip = new Trip([
            'destination_raw' => $s['place'],
            'canonical_place_name' => $s['place'],
            'latitude' => $s['lat'],
            'longitude' => $s['lng'],
            'departure_date' => $s['departure']->toDateString(),
            'return_date' => $s['return']->toDateString(),
            'status' => Trip::STATUS_ACTIVE,
        ]);
        $trip->id = 80001;
        $trip->user_id = $user->id;
        $trip->setRelation('user', $user);

        return $trip;
    }

    /**
     * A synthetic forecast spanning exactly the horizon (today .. today+horizon-1),
     * with mild day-to-day variation so the rows look real.
     *
     * @return array{days: list<array<string, mixed>>, limited: bool}
     */
    private function forecast(CarbonImmutable $today, int $horizon, int $baseF, string $condition): array
    {
        $precips = [10, 20, 40, 60, 30, 50, 20];
        $humidities = [55, 60, 70, 80, 65, 75, 58];

        $days = [];

        for ($i = 0; $i < $horizon; $i++) {
            $highF = $baseF + (($i % 3) - 1) * 4;
            $highC = round(($highF - 32) * 5 / 9, 1);

            $days[] = new ForecastDay(
                date: $today->addDays($i)->toDateString(),
                conditionText: $condition,
                precipChance: $precips[$i % 7],
                highC: $highC,
                highF: (float) $highF,
                lowC: round($highC - 6, 1),
                lowF: (float) ($highF - 12),
                humidity: $humidities[$i % 7],
            );
        }

        return (new Forecast($days))->toArray();
    }

    /**
     * A simulated "yesterday" — each day's rain shifted down so the Overview line
     * always has a notable change to narrate (the prior is illustrative; the
     * forecast figures are what matter here).
     *
     * @param  array{days: list<array<string, mixed>>, limited: bool}  $snapshot
     * @return array{days: list<array<string, mixed>>, limited: bool}
     */
    private function priorOf(array $snapshot): array
    {
        foreach ($snapshot['days'] as &$day) {
            if (isset($day['precipChance']) && is_int($day['precipChance'])) {
                $day['precipChance'] = max(0, $day['precipChance'] - 35);
            }
        }

        return $snapshot;
    }
}
