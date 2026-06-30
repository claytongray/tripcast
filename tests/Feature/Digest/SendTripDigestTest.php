<?php

use App\Jobs\SendTripDigest;
use App\Mail\DigestMail;
use App\Models\EmailLog;
use App\Models\Trip;
use App\Models\User;
use App\Services\Weather\Forecast;
use App\Services\Weather\ForecastDay;
use App\Services\Weather\WeatherProvider;
use App\Services\Weather\WeatherProviderFailedException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-06-29 09:05:00', 'America/New_York'));
});

afterEach(function () {
    Carbon::setTestNow();
});

function sendTrip(): Trip
{
    return User::factory()->confirmed()->create()->trips()->create([
        'destination_raw' => 'Edinburgh',
        'canonical_place_name' => 'Edinburgh, United Kingdom',
        'latitude' => 55.9533,
        'longitude' => -3.1883,
        'departure_date' => '2026-07-03',
        'return_date' => '2026-07-10',
        'status' => Trip::STATUS_ACTIVE,
    ]);
}

function sampleForecast(): Forecast
{
    return new Forecast([
        new ForecastDay('2026-06-29', 'Sunny', 10, 20.0, 68.0, 12.0, 53.6),
        new ForecastDay('2026-06-30', 'Cloudy', 30, 18.0, 64.4, 11.0, 51.8),
    ]);
}

function runSendJob(Trip $trip, string $sendDate, WeatherProvider $weather): void
{
    (new SendTripDigest($trip, $sendDate))->handle($weather);
}

// AC1/AC2 — claim first, fetch once, persist the snapshot, deliver, terminal sent.
it('claims the send row, fetches once, persists the snapshot, and delivers', function () {
    Mail::fake();
    $trip = sendTrip();
    $weather = Mockery::mock(WeatherProvider::class);
    $weather->shouldReceive('fetchForecast')->once()->with(55.9533, -3.1883)->andReturn(sampleForecast());

    runSendJob($trip, '2026-06-29', $weather);

    $log = EmailLog::where('trip_id', $trip->id)->where('send_date', '2026-06-29')->first();
    expect($log)->not->toBeNull()
        ->and($log->status)->toBe(EmailLog::STATUS_SENT)
        ->and($log->claimed_at)->not->toBeNull()
        ->and($log->weather_snapshot['days'][0]['conditionText'])->toBe('Sunny')
        ->and($log->weather_snapshot['days'][0]['highC'])->toEqual(20.0) // JSON normalizes 20.0 → 20
        ->and($log->weather_snapshot['days'][0]['precipChance'])->toEqual(10)
        ->and($log->weather_snapshot['limited'])->toBeTrue(); // only 2 days

    Mail::assertSent(DigestMail::class, fn (DigestMail $m) => $m->hasTo($trip->user->email));
});

// AC2 — delivery fails on every attempt → bounded retry, terminal failed, no
// re-fetch (the snapshot is persisted), no exception escapes.
it('retries delivery up to the cap, then fails terminally without re-fetching weather', function () {
    config(['tripcast.send.max_delivery_attempts' => 3]);
    $trip = sendTrip();
    $weather = Mockery::mock(WeatherProvider::class);
    $weather->shouldReceive('fetchForecast')->once()->andReturn(sampleForecast()); // exactly one fetch

    // The Mailer throws on every delivery attempt.
    Mail::shouldReceive('to')->times(3)->andThrow(new RuntimeException('smtp down'));

    runSendJob($trip, '2026-06-29', $weather);

    $log = EmailLog::where('trip_id', $trip->id)->where('send_date', '2026-06-29')->first();
    expect($log->status)->toBe(EmailLog::STATUS_FAILED)
        ->and($log->failure_reason)->toContain('delivery: smtp down')
        ->and($log->weather_snapshot)->not->toBeNull(); // snapshot kept; recovery is next day's run
});

// AC1 — a fresh in-flight claim aborts the job (no double-send, no second fetch).
it('aborts when a fresh sending row already exists', function () {
    $trip = sendTrip();
    EmailLog::create(['trip_id' => $trip->id, 'send_date' => '2026-06-29', 'status' => 'sending', 'claimed_at' => now()]);

    $weather = Mockery::mock(WeatherProvider::class);
    $weather->shouldNotReceive('fetchForecast');

    runSendJob($trip, '2026-06-29', $weather);

    expect(EmailLog::where('trip_id', $trip->id)->count())->toBe(1)
        ->and(EmailLog::first()->weather_snapshot)->toBeNull();
});

// AC1 — a terminal row aborts.
it('aborts when the row is already sent', function () {
    $trip = sendTrip();
    EmailLog::create(['trip_id' => $trip->id, 'send_date' => '2026-06-29', 'status' => 'sent', 'claimed_at' => now()->subHour()]);

    $weather = Mockery::mock(WeatherProvider::class);
    $weather->shouldNotReceive('fetchForecast');

    runSendJob($trip, '2026-06-29', $weather);

    expect(EmailLog::first()->status)->toBe('sent');
});

// AC3 — a stale sending row (crash mid-send) is reclaimed and processed.
it('reclaims a stale sending row and persists the snapshot', function () {
    $trip = sendTrip();
    EmailLog::create([
        'trip_id' => $trip->id, 'send_date' => '2026-06-29',
        'status' => 'sending', 'claimed_at' => now()->subMinutes(60), // > 30min stale lease
    ]);

    $weather = Mockery::mock(WeatherProvider::class);
    $weather->shouldReceive('fetchForecast')->once()->andReturn(sampleForecast());

    runSendJob($trip, '2026-06-29', $weather);

    $log = EmailLog::first();
    expect(EmailLog::count())->toBe(1)
        ->and($log->weather_snapshot)->not->toBeNull()
        ->and($log->claimed_at->greaterThan(now()->subMinutes(5)))->toBeTrue(); // lease refreshed
});

// AC3 — forecast failure is terminal, not stuck in sending, no broken digest.
it('marks the row failed when the forecast fetch fails', function () {
    $trip = sendTrip();
    $weather = Mockery::mock(WeatherProvider::class);
    $weather->shouldReceive('fetchForecast')->once()->andThrow(new WeatherProviderFailedException('api down'));

    runSendJob($trip, '2026-06-29', $weather);

    $log = EmailLog::first();
    expect($log->status)->toBe(EmailLog::STATUS_FAILED)
        ->and($log->failure_reason)->toContain('api down')
        ->and($log->weather_snapshot)->toBeNull();
});

// AC1 — the unique (trip_id, send_date) index is enforced at the DB.
it('enforces a unique trip_id and send_date', function () {
    $trip = sendTrip();
    EmailLog::create(['trip_id' => $trip->id, 'send_date' => '2026-06-29', 'status' => 'sending']);
    EmailLog::create(['trip_id' => $trip->id, 'send_date' => '2026-06-29', 'status' => 'sending']);
})->throws(UniqueConstraintViolationException::class);
