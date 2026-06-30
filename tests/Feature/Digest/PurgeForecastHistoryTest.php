<?php

use App\Actions\PurgeForecastHistory;
use App\Models\Trip;
use App\Models\User;
use Illuminate\Support\Carbon;

beforeEach(function () {
    // Today on the send clock; default retention 30d → cutoff = 2026-05-31.
    Carbon::setTestNow(Carbon::parse('2026-06-30 09:00', 'America/New_York'));
});

afterEach(function () {
    Carbon::setTestNow();
});

function tripWithReturn(string $returnDate, ?User $user = null): Trip
{
    $user ??= User::factory()->confirmed()->create();

    return Trip::factory()->for($user)->create([
        'departure_date' => Carbon::parse($returnDate)->subDays(7)->toDateString(),
        'return_date' => $returnDate,
    ]);
}

function logWithSnapshot(Trip $trip, string $sendDate): void
{
    $trip->emailLogs()->create([
        'send_date' => $sendDate,
        'status' => 'sent',
        'weather_snapshot' => ['precip_probability' => 60],
    ]);
}

it('nulls snapshots past the retention horizon but keeps the outcome row', function () {
    $trip = tripWithReturn('2026-05-01'); // return ~60 days ago (> 30)
    logWithSnapshot($trip, '2026-04-25');

    $purged = app(PurgeForecastHistory::class)->handle();

    expect($purged)->toBe(1);
    $this->assertDatabaseHas('email_logs', [
        'trip_id' => $trip->id,
        'send_date' => '2026-04-25',
        'status' => 'sent',
        'weather_snapshot' => null, // payload aged out
    ]);
});

it('leaves snapshots for trips within the retention horizon', function () {
    $trip = tripWithReturn('2026-06-20'); // return 10 days ago (< 30)
    logWithSnapshot($trip, '2026-06-19');

    $purged = app(PurgeForecastHistory::class)->handle();

    expect($purged)->toBe(0);
    expect($trip->emailLogs()->first()->weather_snapshot)->toBe(['precip_probability' => 60]);
});

it('anchors on return_date, not send_date', function () {
    // Old send_date, but the trip returns recently → must NOT purge.
    $trip = tripWithReturn('2026-06-25');
    logWithSnapshot($trip, '2026-04-01');

    $purged = app(PurgeForecastHistory::class)->handle();

    expect($purged)->toBe(0);
    expect($trip->emailLogs()->first()->weather_snapshot)->not->toBeNull();
});

it('purges snapshots for soft-deleted trips too', function () {
    $trip = tripWithReturn('2026-05-01');
    logWithSnapshot($trip, '2026-04-25');
    $trip->delete(); // soft delete

    $purged = app(PurgeForecastHistory::class)->handle();

    expect($purged)->toBe(1);
    expect($trip->emailLogs()->first()->weather_snapshot)->toBeNull();
});

it('is a no-op for already-null snapshots', function () {
    $trip = tripWithReturn('2026-05-01');
    $trip->emailLogs()->create(['send_date' => '2026-04-25', 'status' => 'failed', 'failure_reason' => 'x']);

    expect(app(PurgeForecastHistory::class)->handle())->toBe(0);
});

it('honors a configurable retention horizon', function () {
    config(['tripcast.forecast.retention_days' => 5]); // cutoff = 2026-06-25
    $trip = tripWithReturn('2026-06-20'); // now beyond a 5-day horizon
    logWithSnapshot($trip, '2026-06-19');

    expect(app(PurgeForecastHistory::class)->handle())->toBe(1);
});

it('keeps consecutive captures independently diffable (the series exists)', function () {
    $trip = tripWithReturn('2026-07-20'); // future return — never purged
    $trip->emailLogs()->create(['send_date' => '2026-06-28', 'status' => 'sent', 'weather_snapshot' => ['precip_probability' => 60]]);
    $trip->emailLogs()->create(['send_date' => '2026-06-29', 'status' => 'sent', 'weather_snapshot' => ['precip_probability' => 20]]);

    $byDate = $trip->emailLogs()->get()->keyBy(fn ($log) => $log->send_date->toDateString());

    expect($byDate['2026-06-28']->weather_snapshot['precip_probability'])->toBe(60)
        ->and($byDate['2026-06-29']->weather_snapshot['precip_probability'])->toBe(20);
});
