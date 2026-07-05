<?php

use App\Mail\DigestMail;
use App\Models\AdminEmailSend;
use App\Models\Trip;
use App\Models\User;
use App\Services\Weather\Forecast;
use App\Services\Weather\ForecastDay;
use App\Services\Weather\WeatherProvider;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-06-29 09:05:00', 'America/New_York'));
    $weather = Mockery::mock(WeatherProvider::class);
    $weather->shouldReceive('fetchForecast')->andReturn(new Forecast([
        new ForecastDay('2026-06-29', 'Sunny', 10, 20.0, 68.0, 12.0, 53.6),
    ]));
    app()->instance(WeatherProvider::class, $weather);
});

afterEach(function () {
    Carbon::setTestNow();
});

it('rejects guests and non-admins', function () {
    $trip = Trip::factory()->for(User::factory()->confirmed()->create())->create();

    $this->post(route('admin.trips.digest.send', $trip), ['recipient' => 'admin'])
        ->assertRedirect(route('login'));

    $this->actingAs(User::factory()->create())
        ->post(route('admin.trips.digest.send', $trip), ['recipient' => 'admin'])
        ->assertForbidden();
});

it('sends a preview to the admin and flashes success', function () {
    Mail::fake();
    $admin = User::factory()->admin()->create();
    $trip = Trip::factory()->for(User::factory()->confirmed()->create())->create();

    $this->actingAs($admin)
        ->post(route('admin.trips.digest.send', $trip), ['recipient' => 'admin'])
        ->assertRedirect()
        ->assertSessionHas('status');

    expect(AdminEmailSend::where('recipient', 'admin')->where('status', 'sent')->count())->toBe(1);
    Mail::assertSent(DigestMail::class, fn (DigestMail $m) => $m->hasTo($admin->email));
});

it('force-sends to the owner and flashes success', function () {
    Mail::fake();
    $admin = User::factory()->admin()->create();
    $trip = Trip::factory()->for(User::factory()->confirmed()->create())->create();

    $this->actingAs($admin)
        ->post(route('admin.trips.digest.send', $trip), ['recipient' => 'owner'])
        ->assertRedirect()
        ->assertSessionHas('status');

    Mail::assertSent(DigestMail::class, fn (DigestMail $m) => $m->hasTo($trip->user->email));
});

it('refuses send-to-owner for a suppressed owner and flashes an error', function () {
    Mail::fake();
    $admin = User::factory()->admin()->create();
    $trip = Trip::factory()->for(User::factory()->confirmed()->optedOut()->create())->create();

    $this->actingAs($admin)
        ->post(route('admin.trips.digest.send', $trip), ['recipient' => 'owner'])
        ->assertRedirect()
        ->assertSessionHas('error');

    expect(AdminEmailSend::count())->toBe(0);
    Mail::assertNothingSent();
});

it('validates the recipient', function () {
    $admin = User::factory()->admin()->create();
    $trip = Trip::factory()->for(User::factory()->confirmed()->create())->create();

    $this->actingAs($admin)
        ->post(route('admin.trips.digest.send', $trip), ['recipient' => 'nobody'])
        ->assertSessionHasErrors('recipient');
});
