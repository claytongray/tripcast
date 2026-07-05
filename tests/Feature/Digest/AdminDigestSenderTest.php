<?php

use App\Mail\DigestMail;
use App\Models\AdminEmailSend;
use App\Models\EmailLog;
use App\Models\PromoEvent;
use App\Models\Trip;
use App\Models\User;
use App\Services\Digest\AdminDigestSender;
use App\Services\Digest\SuppressedRecipientException;
use App\Services\Weather\Forecast;
use App\Services\Weather\ForecastDay;
use App\Services\Weather\WeatherProvider;
use App\Services\Weather\WeatherProviderFailedException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-06-29 09:05:00', 'America/New_York'));
});

afterEach(function () {
    Carbon::setTestNow();
});

function adminForecast(): Forecast
{
    return new Forecast([
        new ForecastDay('2026-06-29', 'Sunny', 10, 20.0, 68.0, 12.0, 53.6),
    ]);
}

function bindWeather(): void
{
    $weather = Mockery::mock(WeatherProvider::class);
    $weather->shouldReceive('fetchForecast')->andReturn(adminForecast());
    app()->instance(WeatherProvider::class, $weather);
}

it('sends to the admin, audits it, and touches neither email_logs nor promo_events', function () {
    Mail::fake();
    bindWeather();
    $admin = User::factory()->admin()->create();
    $trip = Trip::factory()->for(User::factory()->confirmed()->create())->create();

    $send = app(AdminDigestSender::class)->sendToAdmin($trip, $admin);

    expect($send->status)->toBe(AdminEmailSend::STATUS_SENT)
        ->and($send->recipient)->toBe(AdminEmailSend::RECIPIENT_ADMIN)
        ->and($send->recipient_email)->toBe($admin->email)
        ->and(EmailLog::count())->toBe(0)
        ->and(PromoEvent::count())->toBe(0);

    Mail::assertSent(DigestMail::class, fn (DigestMail $m) => $m->hasTo($admin->email));
});

it('force-sends to the owner even when today already sent, without a second email_logs row', function () {
    Mail::fake();
    bindWeather();
    $admin = User::factory()->admin()->create();
    $trip = Trip::factory()->for(User::factory()->confirmed()->create())->create();
    EmailLog::create(['trip_id' => $trip->id, 'send_date' => '2026-06-29', 'status' => EmailLog::STATUS_SENT, 'claimed_at' => now()]);

    $send = app(AdminDigestSender::class)->sendToOwner($trip, $admin);

    expect($send->status)->toBe(AdminEmailSend::STATUS_SENT)
        ->and($send->recipient_email)->toBe($trip->user->email)
        ->and(EmailLog::count())->toBe(1); // unchanged — no out-of-band row in email_logs

    Mail::assertSent(DigestMail::class, fn (DigestMail $m) => $m->hasTo($trip->user->email));
});

it('refuses send-to-owner for an opted-out owner', function () {
    Mail::fake();
    bindWeather();
    $admin = User::factory()->admin()->create();
    $trip = Trip::factory()->for(User::factory()->confirmed()->optedOut()->create())->create();

    expect(fn () => app(AdminDigestSender::class)->sendToOwner($trip, $admin))
        ->toThrow(SuppressedRecipientException::class);

    expect(AdminEmailSend::count())->toBe(0);
    Mail::assertNothingSent();
});

it('refuses send-to-owner for an unconfirmed owner', function () {
    Mail::fake();
    bindWeather();
    $admin = User::factory()->admin()->create();
    $trip = Trip::factory()->for(User::factory()->create())->create(); // no ->confirmed()

    expect(fn () => app(AdminDigestSender::class)->sendToOwner($trip, $admin))
        ->toThrow(SuppressedRecipientException::class);

    expect(AdminEmailSend::count())->toBe(0);
});

it('records a failed audit row when the weather fetch fails', function () {
    Mail::fake();
    $weather = Mockery::mock(WeatherProvider::class);
    $weather->shouldReceive('fetchForecast')->andThrow(new WeatherProviderFailedException('provider down'));
    app()->instance(WeatherProvider::class, $weather);
    $admin = User::factory()->admin()->create();
    $trip = Trip::factory()->for(User::factory()->confirmed()->create())->create();

    $send = app(AdminDigestSender::class)->sendToAdmin($trip, $admin);

    expect($send->status)->toBe(AdminEmailSend::STATUS_FAILED)
        ->and($send->failure_reason)->toContain('weather:');
    Mail::assertNothingSent();
});

it('records a failed audit row when the delivery send throws', function () {
    bindWeather();
    Mail::shouldReceive('to')->andReturnSelf();
    Mail::shouldReceive('send')->andThrow(new RuntimeException('smtp down'));
    $admin = User::factory()->admin()->create();
    $trip = Trip::factory()->for(User::factory()->confirmed()->create())->create();

    $send = app(AdminDigestSender::class)->sendToAdmin($trip, $admin);

    expect($send)->toBeInstanceOf(AdminEmailSend::class)
        ->and($send->status)->toBe(AdminEmailSend::STATUS_FAILED)
        ->and($send->failure_reason)->toContain('delivery:');
});
