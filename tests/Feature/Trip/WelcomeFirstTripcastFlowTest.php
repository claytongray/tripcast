<?php

use App\Actions\CreateTrip;
use App\Jobs\SendTripDigest;
use App\Mail\DigestMail;
use App\Mail\WelcomeMail;
use App\Models\EmailLog;
use App\Models\Trip;
use App\Models\User;
use App\Services\Weather\Forecast;
use App\Services\Weather\ForecastDay;
use App\Services\Weather\WeatherProvider;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-06-29 09:05:00', 'America/New_York'));
});

afterEach(function () {
    Carbon::setTestNow();
});

it('dispatches a welcome-mode tripcast when a confirmed user adds an in-window trip', function () {
    Bus::fake();
    $user = User::factory()->confirmed()->create();

    app(CreateTrip::class)->handle($user->email, [
        'destination' => 'Edinburgh',
        'canonical_place_name' => 'Edinburgh, United Kingdom',
        'latitude' => 55.9533, 'longitude' => -3.1883,
        'departure_date' => '2026-07-03', 'return_date' => '2026-07-10',
    ]);

    Bus::assertDispatched(SendTripDigest::class, fn (SendTripDigest $j) => $j->welcome === true && $j->sendDate === '2026-06-29');
});

it('queues the heads-up welcome when a confirmed user adds an out-of-window trip', function () {
    Bus::fake();
    Mail::fake();
    $user = User::factory()->confirmed()->create();

    app(CreateTrip::class)->handle($user->email, [
        'destination' => 'Edinburgh',
        'canonical_place_name' => 'Edinburgh, United Kingdom',
        'latitude' => 55.9533, 'longitude' => -3.1883,
        'departure_date' => '2026-08-31', 'return_date' => '2026-09-07',
    ]);

    Mail::assertQueued(WelcomeMail::class);
    Bus::assertNotDispatched(SendTripDigest::class);
});

it('does not double-send: the immediate welcome-mode send claims today so the 7am run skips the trip', function () {
    Mail::fake();
    // Real weather stub so the welcome-mode job actually claims + delivers.
    $weather = Mockery::mock(WeatherProvider::class);
    $weather->shouldReceive('fetchForecast')->once()->andReturn(new Forecast([
        new ForecastDay('2026-06-29', 'Sunny', 10, 20.0, 68.0, 12.0, 53.6),
        new ForecastDay('2026-07-03', 'Cloudy', 30, 18.0, 64.4, 11.0, 51.8),
    ]));
    app()->instance(WeatherProvider::class, $weather);

    $trip = User::factory()->confirmed()->create()->trips()->create([
        'destination_raw' => 'Edinburgh',
        'canonical_place_name' => 'Edinburgh, United Kingdom',
        'latitude' => 55.9533, 'longitude' => -3.1883,
        'departure_date' => '2026-07-03', 'return_date' => '2026-07-10',
        'status' => Trip::STATUS_ACTIVE,
    ]);

    // The immediate welcome-mode send (runs synchronously here).
    (new SendTripDigest($trip, '2026-06-29', welcome: true))->handle($weather);

    // The same-day 7am run: the (trip_id, 2026-06-29) slot is already claimed.
    $this->artisan('digests:send')->assertExitCode(0);

    expect(EmailLog::where('trip_id', $trip->id)->where('send_date', '2026-06-29')->count())->toBe(1);
    Mail::assertSent(DigestMail::class, 1);
});
