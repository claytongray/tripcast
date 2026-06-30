<?php

use App\Mail\DigestMail;
use App\Models\EmailLog;
use App\Models\Trip;
use App\Models\User;
use App\Services\Weather\Forecast;
use App\Services\Weather\ForecastDay;
use App\Services\Weather\WeatherProvider;
use App\Services\Weather\WeatherProviderFailedException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-06-29 09:00:00', 'America/New_York'));
});

afterEach(function () {
    Carbon::setTestNow();
});

function commandTrip(): Trip
{
    return User::factory()->confirmed()->create(['email' => 'owner@example.com'])->trips()->create([
        'destination_raw' => 'Edinburgh',
        'canonical_place_name' => 'Edinburgh, United Kingdom',
        'latitude' => 55.9533,
        'longitude' => -3.1883,
        'departure_date' => '2026-07-03',
        'return_date' => '2026-07-10',
        'status' => Trip::STATUS_ACTIVE,
    ]);
}

function stubForecast(): Forecast
{
    return new Forecast([
        new ForecastDay('2026-06-29', 'Sunny', 10, 20.0, 68.0, 12.0, 54.0),
    ]);
}

it('sends a digest to the --to override and writes no email_logs row', function () {
    Mail::fake();
    $trip = commandTrip();
    $this->mock(WeatherProvider::class)
        ->shouldReceive('fetchForecast')->once()->with(55.9533, -3.1883)->andReturn(stubForecast());

    $this->artisan('digest:send', ['trip' => $trip->id, '--to' => 'qa@example.com'])
        ->assertSuccessful();

    Mail::assertSent(DigestMail::class, fn (DigestMail $m) => $m->hasTo('qa@example.com'));
    // Testing tool: it must never touch the real send dedup.
    expect(EmailLog::count())->toBe(0);
});

it('defaults the recipient to the trip owner', function () {
    Mail::fake();
    $trip = commandTrip();
    $this->mock(WeatherProvider::class)
        ->shouldReceive('fetchForecast')->once()->andReturn(stubForecast());

    $this->artisan('digest:send', ['trip' => $trip->id])->assertSuccessful();

    Mail::assertSent(DigestMail::class, fn (DigestMail $m) => $m->hasTo('owner@example.com'));
});

it('fails cleanly when the trip does not exist', function () {
    Mail::fake();
    $this->mock(WeatherProvider::class)->shouldNotReceive('fetchForecast');

    $this->artisan('digest:send', ['trip' => 9999])->assertFailed();

    Mail::assertNothingSent();
});

it('fails cleanly when the forecast fetch fails', function () {
    Mail::fake();
    $trip = commandTrip();
    $this->mock(WeatherProvider::class)
        ->shouldReceive('fetchForecast')->once()->andThrow(new WeatherProviderFailedException('api down'));

    $this->artisan('digest:send', ['trip' => $trip->id])->assertFailed();

    Mail::assertNothingSent();
});

it('honors a --date override for the send clock', function () {
    Mail::fake();
    $trip = commandTrip();
    $this->mock(WeatherProvider::class)
        ->shouldReceive('fetchForecast')->once()->andReturn(stubForecast());

    // 2026-07-03 is the departure day → subject suffix "today".
    $this->artisan('digest:send', ['trip' => $trip->id, '--date' => '2026-07-03'])
        ->assertSuccessful();

    Mail::assertSent(DigestMail::class, fn (DigestMail $m) => $m->envelope()->subject === 'Edinburgh — today');
});
