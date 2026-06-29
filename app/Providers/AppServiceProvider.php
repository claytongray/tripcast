<?php

namespace App\Providers;

use App\Services\Geocoding\FakeGeocoder;
use App\Services\Geocoding\Geocoder;
use App\Services\Geocoding\GoogleGeocoder;
use App\Services\Weather\FakeWeatherProvider;
use App\Services\Weather\WeatherApiProvider;
use App\Services\Weather\WeatherProvider;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // AD-1: bind the Geocoder port to the real Google adapter when a key is
        // configured, otherwise a deterministic fake so dev/CI run without a key.
        // In production a missing key is a fatal misconfiguration — never silently
        // serve fake coordinates.
        $this->app->bind(Geocoder::class, function (Application $app): Geocoder {
            $key = config('services.google.geocoding_key');

            if (! $key) {
                if ($app->isProduction()) {
                    throw new RuntimeException('GOOGLE_GEOCODING_KEY is not set; refusing to use FakeGeocoder in production.');
                }

                return new FakeGeocoder;
            }

            return new GoogleGeocoder($key);
        });

        // AD-1: same pattern for the weather port — real adapter when keyed, else
        // a deterministic fake; never fake forecasts in production.
        $this->app->bind(WeatherProvider::class, function (Application $app): WeatherProvider {
            $key = config('services.weatherapi.key');

            if (! $key) {
                if ($app->isProduction()) {
                    throw new RuntimeException('WEATHERAPI_KEY is not set; refusing to use FakeWeatherProvider in production.');
                }

                return new FakeWeatherProvider;
            }

            return new WeatherApiProvider($key);
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );
    }
}
