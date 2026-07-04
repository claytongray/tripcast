<?php

use App\Jobs\SendTripDigest;
use App\Mail\DigestMail;
use App\Models\EmailLog;
use App\Models\PromoEvent;
use App\Models\Trip;
use App\Models\User;
use App\Services\Narration\Narrator;
use App\Services\Promo\PromoProvider;
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
    $weather->shouldReceive('fetchForecast')->once()->with(55.9533, -3.1883, null)->andReturn(sampleForecast());

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

// Story 11.2 — the persisted destination zone is passed into the fetch (WeatherKit
// aligns daily highs to it; WeatherAPI ignores the third arg).
it('passes the trip destination timezone into the forecast fetch', function () {
    Mail::fake();
    $trip = sendTrip();
    $trip->update(['destination_timezone' => 'Europe/London']);
    $weather = Mockery::mock(WeatherProvider::class);
    $weather->shouldReceive('fetchForecast')->once()->with(55.9533, -3.1883, 'Europe/London')->andReturn(sampleForecast());

    runSendJob($trip, '2026-06-29', $weather);

    expect(EmailLog::where('trip_id', $trip->id)->where('send_date', '2026-06-29')->first()?->status)
        ->toBe(EmailLog::STATUS_SENT);
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

// Story 4.2 — narration: a notable day-over-day change renders a calm line.
it('attaches a day-over-day narration line when the forecast notably changed', function () {
    Mail::fake();
    $trip = sendTrip(); // window 2026-07-03 .. 2026-07-10

    // Yesterday's snapshot for the departure day: 60% rain.
    $trip->emailLogs()->create([
        'send_date' => '2026-06-28',
        'status' => EmailLog::STATUS_SENT,
        'weather_snapshot' => ['days' => [[
            'date' => '2026-07-03', 'conditionText' => 'Rain', 'precipChance' => 60,
            'highF' => 64.0, 'highC' => 17.0, 'lowF' => 53.0, 'lowC' => 11.0,
        ]], 'limited' => true],
    ]);

    // Today the same day drops to 20% — a 40-point swing.
    $weather = Mockery::mock(WeatherProvider::class);
    $weather->shouldReceive('fetchForecast')->once()->andReturn(new Forecast([
        new ForecastDay('2026-07-03', 'Sunny', 20, 17.0, 64.0, 11.0, 53.0),
    ]));

    runSendJob($trip, '2026-06-29', $weather);

    Mail::assertSent(DigestMail::class, fn (DigestMail $m) => $m->narration !== null
        && str_contains($m->narration, 'rain chance dropped from 60% to 20%'));
});

// Story 4.2 — no prior snapshot → no line, digest still sends.
it('omits narration when there is no prior snapshot', function () {
    Mail::fake();
    $trip = sendTrip();

    $weather = Mockery::mock(WeatherProvider::class);
    $weather->shouldReceive('fetchForecast')->once()->andReturn(new Forecast([
        new ForecastDay('2026-07-03', 'Sunny', 20, 17.0, 64.0, 11.0, 53.0),
    ]));

    runSendJob($trip, '2026-06-29', $weather);

    Mail::assertSent(DigestMail::class, fn (DigestMail $m) => $m->narration === null);
});

// Story 4.2 — a narrator that throws never fails the send (AD-17).
it('still delivers when the narrator throws', function () {
    Mail::fake();
    $trip = sendTrip();

    $this->mock(Narrator::class)
        ->shouldReceive('narrate')
        ->andThrow(new RuntimeException('narrator boom'));

    $weather = Mockery::mock(WeatherProvider::class);
    $weather->shouldReceive('fetchForecast')->once()->andReturn(sampleForecast());

    runSendJob($trip, '2026-06-29', $weather);

    $log = EmailLog::where('trip_id', $trip->id)->where('send_date', '2026-06-29')->first();
    expect($log->status)->toBe(EmailLog::STATUS_SENT);
    Mail::assertSent(DigestMail::class, fn (DigestMail $m) => $m->narration === null);
});

// Story 5.3 — a free-tier user gets a weather-keyed promo in the digest.
it('attaches a promo for a free-tier user', function () {
    Mail::fake();
    $trip = sendTrip(); // confirmed, free plan by default
    $weather = Mockery::mock(WeatherProvider::class);
    $weather->shouldReceive('fetchForecast')->once()->andReturn(sampleForecast());

    runSendJob($trip, '2026-06-29', $weather);

    Mail::assertSent(DigestMail::class, fn (DigestMail $m) => $m->promo !== null
        && str_contains($m->promo->url, 'tag='));
});

// Story 5.3 — ad-free users never see a promo (entitlement gate, AD-19).
it('omits the promo for an ad-free user', function () {
    Mail::fake();
    $trip = User::factory()->confirmed()->adFree()->create()->trips()->create([
        'destination_raw' => 'Edinburgh', 'canonical_place_name' => 'Edinburgh, United Kingdom',
        'latitude' => 55.9533, 'longitude' => -3.1883,
        'departure_date' => '2026-07-03', 'return_date' => '2026-07-10', 'status' => Trip::STATUS_ACTIVE,
    ]);
    $weather = Mockery::mock(WeatherProvider::class);
    $weather->shouldReceive('fetchForecast')->once()->andReturn(sampleForecast());

    runSendJob($trip, '2026-06-29', $weather);

    Mail::assertSent(DigestMail::class, fn (DigestMail $m) => $m->promo === null);
});

// Story 5.3 — a promo-selection failure never breaks the send (AD-18).
it('still delivers when promo selection throws', function () {
    Mail::fake();
    $trip = sendTrip();

    $this->mock(PromoProvider::class)
        ->shouldReceive('select')
        ->andThrow(new RuntimeException('promo boom'));

    $weather = Mockery::mock(WeatherProvider::class);
    $weather->shouldReceive('fetchForecast')->once()->andReturn(sampleForecast());

    runSendJob($trip, '2026-06-29', $weather);

    $log = EmailLog::where('trip_id', $trip->id)->where('send_date', '2026-06-29')->first();
    expect($log->status)->toBe(EmailLog::STATUS_SENT);
    Mail::assertSent(DigestMail::class, fn (DigestMail $m) => $m->promo === null);
});

// Story 5.4 — an impression is logged for a sent promo (idempotent).
it('logs a promo impression on send', function () {
    Mail::fake();
    $trip = sendTrip(); // free
    $weather = Mockery::mock(WeatherProvider::class);
    $weather->shouldReceive('fetchForecast')->once()->andReturn(sampleForecast());

    runSendJob($trip, '2026-06-29', $weather);

    $this->assertDatabaseHas('promo_events', [
        'trip_id' => $trip->id,
        'send_date' => '2026-06-29',
        'event' => 'impression',
    ]);
    expect(PromoEvent::where('event', 'impression')->count())->toBe(1);
});

// Story 5.4 — ad-free sends log no promo event.
it('logs no promo event for an ad-free user', function () {
    Mail::fake();
    $trip = User::factory()->confirmed()->adFree()->create()->trips()->create([
        'destination_raw' => 'Edinburgh', 'canonical_place_name' => 'Edinburgh, United Kingdom',
        'latitude' => 55.9533, 'longitude' => -3.1883,
        'departure_date' => '2026-07-03', 'return_date' => '2026-07-10', 'status' => Trip::STATUS_ACTIVE,
    ]);
    $weather = Mockery::mock(WeatherProvider::class);
    $weather->shouldReceive('fetchForecast')->once()->andReturn(sampleForecast());

    runSendJob($trip, '2026-06-29', $weather);

    expect(PromoEvent::count())->toBe(0);
});

// Story 5.4 — a failed delivery logs no impression (only on the sent path).
it('logs no impression when delivery fails', function () {
    config(['tripcast.send.max_delivery_attempts' => 2]);
    $trip = sendTrip();
    $weather = Mockery::mock(WeatherProvider::class);
    $weather->shouldReceive('fetchForecast')->once()->andReturn(sampleForecast());
    Mail::shouldReceive('to')->andThrow(new RuntimeException('smtp down'));

    runSendJob($trip, '2026-06-29', $weather);

    expect(PromoEvent::count())->toBe(0);
});

// Task 2 — welcome mode threads through the send job.
it('delivers the digest in welcome mode when the welcome flag is set', function () {
    Mail::fake();
    $trip = sendTrip();
    $weather = Mockery::mock(WeatherProvider::class);
    $weather->shouldReceive('fetchForecast')->once()->andReturn(sampleForecast());

    (new SendTripDigest($trip, '2026-06-29', welcome: true))->handle($weather);

    Mail::assertSent(DigestMail::class, fn (DigestMail $m) => $m->welcome === true && $m->hasTo($trip->user->email));
});
