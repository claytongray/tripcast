<?php

namespace App\Providers;

use App\Models\User;
use App\Services\Geocoding\FakeGeocoder;
use App\Services\Geocoding\FakePlaceAutocomplete;
use App\Services\Geocoding\Geocoder;
use App\Services\Geocoding\GoogleGeocoder;
use App\Services\Geocoding\GooglePlacesAutocomplete;
use App\Services\Geocoding\PlaceAutocomplete;
use App\Services\Narration\DeterministicNarrator;
use App\Services\Narration\Narrator;
use App\Services\Promo\AffiliatePromoProvider;
use App\Services\Promo\DatabasePromoProvider;
use App\Services\Promo\PromoProvider;
use App\Services\Weather\FakeWeatherProvider;
use App\Services\Weather\WeatherApiProvider;
use App\Services\Weather\WeatherProvider;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
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

        // AD-1: the autocomplete port shares the restricted Google key (Places
        // API (New) enabled on it — see the .env.example note). Same fake-in-dev
        // discipline; suggestions in production without a key would silently
        // mislead, so fail fast there too.
        $this->app->bind(PlaceAutocomplete::class, function (Application $app): PlaceAutocomplete {
            $key = config('services.google.geocoding_key');

            if (! $key) {
                if ($app->isProduction()) {
                    throw new RuntimeException('GOOGLE_GEOCODING_KEY is not set; refusing to use FakePlaceAutocomplete in production.');
                }

                return new FakePlaceAutocomplete;
            }

            return new GooglePlacesAutocomplete($key);
        });

        // AD-17: the narration port ships the deterministic adapter live (no
        // network, never alarmist, can't invent figures). The Claude adapter
        // runs in shadow, resolved by class in SendTripDigest — not bound here.
        $this->app->bind(Narrator::class, DeterministicNarrator::class);

        // AD-18: the promo port. 'database' (admin-managed catalog, Epic 8) by
        // default, 'affiliate' (config catalog) as a code-free rollback.
        $this->app->bind(PromoProvider::class, config('tripcast.promo.provider') === 'affiliate'
            ? AffiliatePromoProvider::class
            : DatabasePromoProvider::class);

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

        // The single admin enforcement point (AD-12): a boolean flag behind one
        // Gate — no allowlist, no admin CMS. Routes guard with `can:admin`.
        Gate::define('admin', fn (User $user): bool => $user->is_admin);
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
