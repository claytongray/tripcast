<?php

use Illuminate\Support\Facades\Http;

/**
 * Preflight command for the WeatherKit cutover (Epic 11): proves the running app
 * can load + sign with the .p8 the config points at, independent of the provider
 * flag. The throwaway EC key fixture (a real prime256v1 key, not an Apple one) is
 * safe to sign with in tests.
 */
function weatherkitConfig(array $overrides = []): array
{
    return array_merge([
        'team_id' => 'TEAM123456',
        'service_id' => 'com.example.app',
        'key_id' => 'KEY1234567',
        'private_key_path' => 'tests/Fixtures/weatherkit/throwaway.p8',
    ], $overrides);
}

it('passes when the credentials are set and the key is readable and signs', function () {
    config()->set('services.weatherkit', weatherkitConfig());

    $this->artisan('weatherkit:check')
        ->assertExitCode(0);
});

it('fails when the key file is missing from the release', function () {
    config()->set('services.weatherkit', weatherkitConfig([
        'private_key_path' => 'tests/Fixtures/weatherkit/does-not-exist.p8',
    ]));

    $this->artisan('weatherkit:check')
        ->assertExitCode(1);
});

it('fails when a credential is missing', function () {
    config()->set('services.weatherkit', weatherkitConfig(['team_id' => '']));

    $this->artisan('weatherkit:check')
        ->assertExitCode(1);
});

it('fails when no key path is configured', function () {
    config()->set('services.weatherkit', weatherkitConfig(['private_key_path' => null]));

    $this->artisan('weatherkit:check')
        ->assertExitCode(1);
});

it('confirms a live fetch under --live without hitting the network', function () {
    config()->set('services.weatherkit', weatherkitConfig());
    Http::fake([
        'weatherkit.apple.com/*' => Http::response(
            json_decode((string) file_get_contents(base_path('tests/Fixtures/weatherkit/kennett.json')), true)
        ),
    ]);

    $this->artisan('weatherkit:check --live')
        ->assertExitCode(0);

    Http::assertSent(fn ($request) => str_contains($request->url(), 'weatherkit.apple.com'));
});
