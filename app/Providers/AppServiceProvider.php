<?php

namespace App\Providers;

use App\Services\Geocoding\FakeGeocoder;
use App\Services\Geocoding\Geocoder;
use App\Services\Geocoding\GoogleGeocoder;
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
