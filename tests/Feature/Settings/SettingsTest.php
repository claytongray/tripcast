<?php

use App\Models\User;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\patch;

// Guests have no settings — the page is behind auth.
it('redirects a guest from settings to login', function () {
    get(route('settings.edit'))->assertRedirect(route('login'));
});

// The page renders the account email and the current temperature unit.
it('renders the settings page with the user email and temperature unit', function () {
    $user = User::factory()->create([
        'email' => 'traveler@example.com',
        'temperature_unit' => User::UNIT_CELSIUS,
    ]);

    actingAs($user);

    get(route('settings.edit'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Settings')
            ->where('email', 'traveler@example.com')
            ->where('temperatureUnit', User::UNIT_CELSIUS));
});

// Flipping the unit persists it and confirms calmly.
it('updates the temperature unit', function () {
    $user = User::factory()->create(['temperature_unit' => User::UNIT_FAHRENHEIT]);

    actingAs($user);

    patch(route('settings.update'), ['temperature_unit' => User::UNIT_CELSIUS])
        ->assertRedirect()
        ->assertSessionHas('status');

    expect($user->refresh()->temperature_unit)->toBe(User::UNIT_CELSIUS);
});

// An unknown unit is rejected and nothing changes.
it('rejects an invalid temperature unit', function () {
    $user = User::factory()->create(['temperature_unit' => User::UNIT_FAHRENHEIT]);

    actingAs($user);

    patch(route('settings.update'), ['temperature_unit' => 'kelvin'])
        ->assertSessionHasErrors('temperature_unit');

    expect($user->refresh()->temperature_unit)->toBe(User::UNIT_FAHRENHEIT);
});

// Guests cannot update settings.
it('redirects a guest from the settings update to login', function () {
    patch(route('settings.update'), ['temperature_unit' => User::UNIT_CELSIUS])
        ->assertRedirect(route('login'));
});
