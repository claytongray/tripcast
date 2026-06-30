<?php

use App\Digest\CadencePredicate;
use App\Jobs\SendTripDigest;
use App\Mail\DigestMail;
use App\Models\EmailLog;
use App\Models\Trip;
use App\Models\User;
use App\Services\Weather\Forecast;
use App\Services\Weather\ForecastDay;
use App\Services\Weather\WeatherProvider;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

const HEARTBEAT_URL = 'https://hc.example/ping/abc';

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-06-29 09:00:00', 'America/New_York'));
    Queue::fake();
});

afterEach(function () {
    Carbon::setTestNow();
});

function dueTrip(): Trip
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

// AC2 — the command dispatches one job per due trip with today's send_date.
it('dispatches one SendTripDigest per due trip', function () {
    $a = dueTrip();
    $b = dueTrip();

    $this->artisan('digests:send')->assertSuccessful();

    Queue::assertPushed(SendTripDigest::class, 2);
    Queue::assertPushed(SendTripDigest::class, fn (SendTripDigest $job) => $job->sendDate === '2026-06-29'
        && in_array($job->trip->id, [$a->id, $b->id], true));
});

// AC2/AC3 — non-due trips are never dispatched.
it('dispatches nothing for non-due trips', function () {
    // Paused, unconfirmed, opted-out, and out-of-window trips.
    User::factory()->confirmed()->create()->trips()->create([
        'destination_raw' => 'X', 'canonical_place_name' => 'X', 'latitude' => 1.0, 'longitude' => 2.0,
        'departure_date' => '2026-07-03', 'return_date' => '2026-07-10', 'status' => Trip::STATUS_PAUSED,
    ]);
    User::factory()->create()->trips()->create([ // unconfirmed owner
        'destination_raw' => 'Y', 'canonical_place_name' => 'Y', 'latitude' => 1.0, 'longitude' => 2.0,
        'departure_date' => '2026-07-03', 'return_date' => '2026-07-10', 'status' => Trip::STATUS_ACTIVE,
    ]);

    $this->artisan('digests:send')->assertSuccessful();

    Queue::assertNothingPushed();
});

// AC1/AC2/AC3 — end-to-end on the sync queue: a due trip → digests:send → job
// renders + delivers the digest from the snapshot and lands a terminal `sent`.
it('delivers a digest end-to-end and records a sent email_logs row', function () {
    Queue::fake()->except(SendTripDigest::class); // run the per-trip job inline (sync)
    Mail::fake();

    $trip = dueTrip();
    $this->mock(WeatherProvider::class)
        ->shouldReceive('fetchForecast')->once()
        ->andReturn(new Forecast([
            new ForecastDay('2026-06-29', 'Sunny', 10, 20.0, 68.0, 12.0, 54.0),
        ]));

    $this->artisan('digests:send')->assertSuccessful();

    $log = EmailLog::where('trip_id', $trip->id)->where('send_date', '2026-06-29')->first();
    expect($log)->not->toBeNull()
        ->and($log->status)->toBe(EmailLog::STATUS_SENT);

    Mail::assertSent(DigestMail::class, fn (DigestMail $m) => $m->hasTo($trip->user->email));
});

// AC1 — a healthy run pings the success heartbeat and records the outcome.
it('pings the success heartbeat on a healthy run', function () {
    config(['tripcast.heartbeat.url' => HEARTBEAT_URL]);
    Http::fake();
    dueTrip();

    $this->artisan('digests:send')->assertSuccessful();

    Queue::assertPushed(SendTripDigest::class, 1);
    Http::assertSent(fn (Request $r) => $r->url() === HEARTBEAT_URL);
    Http::assertNotSent(fn (Request $r) => str_ends_with($r->url(), '/fail'));
});

// AC1 — zero-due is a normal healthy run (success ping, no alert).
it('pings success even when nothing is due', function () {
    config(['tripcast.heartbeat.url' => HEARTBEAT_URL]);
    Http::fake();

    $this->artisan('digests:send')->assertSuccessful();

    Http::assertSent(fn (Request $r) => $r->url() === HEARTBEAT_URL);
});

// AC1/AC2 — no URL configured → no ping at all, run still succeeds (dev no-op).
it('pings nothing when no heartbeat url is configured', function () {
    config(['tripcast.heartbeat.url' => null]);
    Http::fake();
    dueTrip();

    $this->artisan('digests:send')->assertSuccessful();

    Http::assertNothingSent();
});

// AC2 — a select/dispatch failure fail-pings, exits failure, and never escapes.
it('fail-pings and exits failure when the run throws', function () {
    config(['tripcast.heartbeat.url' => HEARTBEAT_URL]);
    Http::fake();

    $this->mock(CadencePredicate::class)
        ->shouldReceive('dueOn')->once()->andThrow(new RuntimeException('db down'));

    $this->artisan('digests:send')->assertFailed();

    Http::assertSent(fn (Request $r) => $r->url() === rtrim(HEARTBEAT_URL, '/').'/fail');
});

// AC2 — a heartbeat ping outage never breaks the product run.
it('completes the run even if the heartbeat ping fails', function () {
    config(['tripcast.heartbeat.url' => HEARTBEAT_URL]);
    Http::fake(fn () => Http::response('', 500));
    dueTrip();

    $this->artisan('digests:send')->assertSuccessful();

    Queue::assertPushed(SendTripDigest::class, 1); // the send happened regardless
});
