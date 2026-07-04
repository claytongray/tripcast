# WeatherKit Provider Swap Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the WeatherAPI.com forecast provider with Apple WeatherKit behind the existing `WeatherProvider` port, fixing the 5–8°F high-temperature inflation on hot/humid inland days, and render the mandatory Apple Weather attribution — behind a config flag, hard cutover.

**Architecture:** A new `WeatherKitProvider implements WeatherProvider`, selected by `config('tripcast.forecast.provider')`. WeatherKit is metric-only, so the adapter converts °C→°F and 0–1 decimals→int percent, keeping the `Forecast`/`ForecastDay` contract and the render pipeline unchanged. Auth is a cached ES256 JWT (`firebase/php-jwt`). WeatherKit requires a destination IANA timezone as input; it is resolved from coordinates via the Google Time Zone API and persisted on `trips.destination_timezone` at trip creation, so it is ready before the welcome email's first tripcast and reused on every send with no repeat Google call.

**Tech Stack:** Laravel 13, PHP 8.3, Pest 4, `firebase/php-jwt` v7, Carbon, Tailwind v4 (email), Inertia/Vue 3 (unaffected here).

**Contract source:** `_bmad-output/specs/spec-weatherkit-provider-swap/SPEC.md` (CAP-1…9) and its companion `weatherkit-integration.md` (field lineage, JWT shape, endpoint, attribution). Live payload already verified: Jul 4 highs 97/93/86°F.

## Global Constraints

- **PHP style:** curly braces on all control structures; explicit return types + param type hints; constructor property promotion; PHPDoc over inline comments. Run `vendor/bin/pint --dirty --format agent` before every PHP commit.
- **Tests:** Pest, `php artisan test --compact --filter=...`. Fake all HTTP with `Http::fake()`; never hit Apple/Google in tests. Pin the clock with `Carbon::setTestNow(...)` where time matters.
- **Response contract frozen:** `Forecast` / `ForecastDay` shape and `email_logs.weather_snapshot` serialization must not change. The only allowed port change is adding an optional `?string $timezone = null` to `WeatherProvider::fetchForecast`.
- **Units:** WeatherKit returns °C, mm, km/h, and 0–1 decimals (no unit param). Convert °C→°F as `round($c * 9 / 5 + 32)`; scale `precipitationChance`/`humidity` as `round($v * 100)`.
- **Limited-not-fabricated (FR-7):** a day missing any core value (`conditionText`, `precipChance`, high/low in either unit) stays limited via `ForecastDay::isLimited()`; humidity and feels-like are optional enrichment and never make a day limited.
- **Secrets:** credentials come only from env (`APPLE_WEATHERKIT_*`); the `.p8` is a git-ignored path (`*.p8` already in `.gitignore`); never log or echo the key or a minted token.
- **Timezone fallback:** on resolution failure, use `config('tripcast.forecast.default_timezone')` (`America/New_York`) for the fetch and log a warning — never GMT, never abort.
- **Hard cutover:** build behind `config('tripcast.forecast.provider')` (default `weatherapi`); verify locally with `MAIL_MAILER=log`; flip to `weatherkit` only in Task 10.

## Coordination with the timezone-aware-send-time plan

`docs/superpowers/plans/2026-07-03-timezone-aware-send-time.md` also touches destination timezone. This plan **supersedes** two of its tasks:

- **Its Task 3** (add `destination_timezone` column) → **done here in Task 5** with the identical column shape (`string`, nullable, after `longitude`; `Trip::$destination_timezone` fillable + `@property`). The send-time executor must **skip its Task 3** — the column will already exist.
- **Its Task 4** (capture the zone from WeatherAPI `location.tz_id`, add `Forecast::$timezone`) → **replaced** here: the zone is captured at trip creation from the Google Time Zone API (Task 5), not from the weather response. WeatherKit returns no `tz_id`. The send-time executor must **skip its Task 4**; `Forecast::$timezone` is not needed.
- Its remaining tasks (home-tz capture, settings, phase-aware `CadencePredicate` resolution, hourly scheduler, dashboard zone, integration, docs) consume `Trip::$destination_timezone` unchanged. A `SUPERSEDED` banner is added to those two tasks in that file by this plan's Task 5.

---

## Task 1: Config, credentials, provider flag, and env docs

**Files:**
- Modify: `config/services.php` (add a `weatherkit` block)
- Modify: `config/tripcast.php` (add `forecast.provider` and `forecast.default_timezone`)
- Modify: `.env.example` (document the new keys)
- Test: `tests/Unit/Weather/WeatherKitConfigTest.php` (create)

**Interfaces:**
- Produces: `config('services.weatherkit')` = `['team_id','service_id','key_id','private_key_path']`; `config('tripcast.forecast.provider')` (default `'weatherapi'`); `config('tripcast.forecast.default_timezone')` (default `'America/New_York'`).

- [ ] **Step 1: Write the failing config test**

Create `tests/Unit/Weather/WeatherKitConfigTest.php`:

```php
<?php

it('exposes the weatherkit credential config keys', function () {
    config()->set('services.weatherkit', [
        'team_id' => 'TEAM123456',
        'service_id' => 'com.example.app',
        'key_id' => 'KEY1234567',
        'private_key_path' => 'weatherkit-private-key.p8',
    ]);

    expect(config('services.weatherkit.team_id'))->toBe('TEAM123456')
        ->and(config('services.weatherkit.service_id'))->toBe('com.example.app');
});

it('defaults the forecast provider to weatherapi and a fallback timezone', function () {
    expect(config('tripcast.forecast.provider'))->toBe('weatherapi')
        ->and(config('tripcast.forecast.default_timezone'))->toBe('America/New_York');
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php artisan test --compact --filter=WeatherKitConfig`
Expected: FAIL — `tripcast.forecast.provider` / `default_timezone` don't exist yet.

- [ ] **Step 3: Add the services block**

In `config/services.php`, add next to the existing `weatherapi` block:

```php
    'weatherkit' => [
        'team_id' => env('APPLE_WEATHERKIT_TEAM_ID'),
        'service_id' => env('APPLE_WEATHERKIT_SERVICE_ID'),
        'key_id' => env('APPLE_WEATHERKIT_KEY_ID'),
        // Path to the .p8 private key (git-ignored), resolved via base_path().
        'private_key_path' => env('APPLE_WEATHERKIT_PRIVATE_KEY'),
    ],
```

- [ ] **Step 4: Add the forecast config**

In `config/tripcast.php`, extend the existing `forecast` block (keep the existing keys such as `horizon_days`):

```php
        // Active weather provider adapter: 'weatherapi' (legacy) | 'weatherkit'.
        'provider' => env('TRIPCAST_WEATHER_PROVIDER', 'weatherapi'),

        // Fallback IANA zone when a destination timezone can't be resolved
        // (WeatherKit needs a zone to align daily highs to the local day).
        'default_timezone' => env('TRIPCAST_FALLBACK_TIMEZONE', 'America/New_York'),
```

- [ ] **Step 5: Document the env keys**

In `.env.example`, near the other provider keys, add:

```
# Weather provider: weatherapi (legacy) | weatherkit
# TRIPCAST_WEATHER_PROVIDER=weatherapi

# Apple WeatherKit (REST) — 500k calls/mo free with Apple Developer membership.
APPLE_WEATHERKIT_TEAM_ID=
APPLE_WEATHERKIT_SERVICE_ID=
APPLE_WEATHERKIT_KEY_ID=
# Path (relative to project root) to the .p8 private key; the file is git-ignored.
APPLE_WEATHERKIT_PRIVATE_KEY=weatherkit-private-key.p8
```

- [ ] **Step 6: Run the config test**

Run: `php artisan test --compact --filter=WeatherKitConfig`
Expected: PASS.

- [ ] **Step 7: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add config/services.php config/tripcast.php .env.example tests/Unit/Weather/WeatherKitConfigTest.php
git commit -m "feat(weather): add WeatherKit config, provider flag, and fallback timezone"
```

---

## Task 2: `WeatherKitToken` — cached ES256 JWT

**Files:**
- Create: `app/Services/Weather/WeatherKit/WeatherKitToken.php`
- Test: `tests/Unit/Weather/WeatherKitTokenTest.php`

**Interfaces:**
- Produces: `new WeatherKitToken(string $teamId, string $serviceId, string $keyId, string $privateKeyPem)`; `WeatherKitToken::bearer(): string` — a signed JWT (`ES256`) with header `{alg, kid, id: "<team>.<service>"}` and claims `{iss: team, sub: service, iat, exp}`, cached until shortly before expiry.

- [ ] **Step 1: Write the failing unit test (throwaway key)**

Create `tests/Unit/Weather/WeatherKitTokenTest.php`:

```php
<?php

use App\Services\Weather\WeatherKit\WeatherKitToken;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

function throwawayEcKey(): array
{
    $res = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
    openssl_pkey_export($res, $private);

    return [$private, openssl_pkey_get_details($res)['key']];
}

it('mints an ES256 JWT with the id header and iss/sub claims', function () {
    [$private, $public] = throwawayEcKey();
    $token = new WeatherKitToken('TEAM123456', 'com.example.app', 'KEY1234567', $private);

    $jwt = $token->bearer();

    // header carries alg/kid and the non-standard id = team.service
    $header = json_decode(base64_decode(strtr(explode('.', $jwt)[0], '-_', '+/')), true);
    expect($header['alg'])->toBe('ES256')
        ->and($header['kid'])->toBe('KEY1234567')
        ->and($header['id'])->toBe('TEAM123456.com.example.app');

    // signature verifies with the public key; claims are correct
    $claims = (array) JWT::decode($jwt, new Key($public, 'ES256'));
    expect($claims['iss'])->toBe('TEAM123456')
        ->and($claims['sub'])->toBe('com.example.app')
        ->and($claims['exp'])->toBeGreaterThan($claims['iat']);
});

it('reuses the cached token within its lifetime', function () {
    [$private] = throwawayEcKey();
    $token = new WeatherKitToken('TEAM123456', 'com.example.app', 'KEY1234567', $private);

    expect($token->bearer())->toBe($token->bearer());
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php artisan test --compact --filter=WeatherKitToken`
Expected: FAIL — class does not exist.

- [ ] **Step 3: Implement the token**

Create `app/Services/Weather/WeatherKit/WeatherKitToken.php`:

```php
<?php

namespace App\Services\Weather\WeatherKit;

use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Cache;

/**
 * Builds and caches the ES256 JWT WeatherKit requires on every request. The
 * header carries the non-standard `id` field (`<team>.<service>`) that Apple
 * demands — the #1 cause of 401s — alongside the standard `kid`. The token is
 * cached (keyed by the signing key id) and reused until shortly before expiry;
 * WeatherKit accepts a token for many requests within its window.
 */
class WeatherKitToken
{
    private const TTL_SECONDS = 3000;   // < the 3600 Apple ceiling

    public function __construct(
        private string $teamId,
        private string $serviceId,
        private string $keyId,
        private string $privateKeyPem,
    ) {}

    public function bearer(): string
    {
        return Cache::remember("weatherkit:jwt:{$this->keyId}", self::TTL_SECONDS, function (): string {
            $now = time();

            return JWT::encode(
                ['iss' => $this->teamId, 'sub' => $this->serviceId, 'iat' => $now, 'exp' => $now + 3600],
                $this->privateKeyPem,
                'ES256',
                $this->keyId,
                ['id' => "{$this->teamId}.{$this->serviceId}"],
            );
        });
    }
}
```

- [ ] **Step 4: Run it to verify it passes**

Run: `php artisan test --compact --filter=WeatherKitToken`
Expected: PASS.

- [ ] **Step 5: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Services/Weather/WeatherKit/WeatherKitToken.php tests/Unit/Weather/WeatherKitTokenTest.php
git commit -m "feat(weather): cached ES256 WeatherKit JWT with the id header"
```

---

## Task 3: `ConditionCode` label + `WeatherEmoji` keyword gaps

**Files:**
- Create: `app/Services/Weather/WeatherKit/ConditionCode.php`
- Modify: `app/Digest/WeatherEmoji.php` (add keywords the WeatherKit vocabulary needs)
- Test: `tests/Unit/Weather/ConditionCodeTest.php`, `tests/Unit/Digest/WeatherEmojiTest.php` (extend if it exists, else create)

**Interfaces:**
- Produces: `ConditionCode::label(string $code): string` — PascalCase enum → spaced human label that feeds `WeatherEmoji::for()` unchanged.

- [ ] **Step 1: Write the failing tests**

Create `tests/Unit/Weather/ConditionCodeTest.php`:

```php
<?php

use App\Digest\WeatherEmoji;
use App\Services\Weather\WeatherKit\ConditionCode;

it('turns PascalCase condition codes into spaced labels', function (string $code, string $label) {
    expect(ConditionCode::label($code))->toBe($label);
})->with([
    ['Clear', 'Clear'],
    ['MostlyClear', 'Mostly Clear'],
    ['PartlyCloudy', 'Partly Cloudy'],
    ['ScatteredThunderstorms', 'Scattered Thunderstorms'],
    ['HeavyRain', 'Heavy Rain'],
    ['Thunderstorms', 'Thunderstorms'],
]);

it('produces labels the existing emoji matcher resolves correctly', function (string $code, string $emoji) {
    expect(WeatherEmoji::for(ConditionCode::label($code)))->toBe($emoji);
})->with([
    ['PartlyCloudy', '⛅'],
    ['ScatteredThunderstorms', '⛈️'],
    ['HeavyRain', '🌧️'],
    ['MostlyClear', '☀️'],
    ['Breezy', '💨'],
    ['Hurricane', '⛈️'],
]);
```

- [ ] **Step 2: Run to verify failure**

Run: `php artisan test --compact --filter=ConditionCode`
Expected: FAIL — `ConditionCode` missing; `Breezy`/`Hurricane` emoji not yet mapped.

- [ ] **Step 3: Implement `ConditionCode`**

Create `app/Services/Weather/WeatherKit/ConditionCode.php`:

```php
<?php

namespace App\Services\Weather\WeatherKit;

/**
 * Turns WeatherKit's PascalCase `conditionCode` enum (e.g. `PartlyCloudy`,
 * `ScatteredThunderstorms`) into a spaced human label ("Partly Cloudy",
 * "Scattered Thunderstorms"). The label is what renders in the digest and what
 * feeds the existing keyword-based WeatherEmoji — so no code→emoji table is
 * needed and unseen future codes still degrade to readable text.
 */
class ConditionCode
{
    public static function label(string $code): string
    {
        $code = trim($code);

        if ($code === '') {
            return '';
        }

        // Insert a space before each interior capital: "HeavyRain" → "Heavy Rain".
        return preg_replace('/(?<!^)(?=[A-Z])/', ' ', $code);
    }
}
```

- [ ] **Step 4: Add the missing emoji keywords**

In `app/Digest/WeatherEmoji.php`, extend the `MAP` so the WeatherKit vocabulary is fully covered. Add `hurricane`/`tropical` to the thunder line, `hail` to the snow/ice line, and `breez` to the wind line:

```php
    private const MAP = [
        [['thunder', 'hurricane', 'tropical'], '⛈️'],
        [['blizzard', 'snow', 'sleet', 'ice', 'icy', 'hail'], '🌨️'],
        [['rain', 'drizzle', 'shower'], '🌧️'],
        [['fog', 'mist', 'haze', 'dust', 'sand', 'smoke', 'smog'], '🌫️'],
        [['partly', 'partial'], '⛅'],
        [['overcast', 'cloud'], '☁️'],
        [['sun', 'clear'], '☀️'],
        [['wind', 'breez'], '💨'],
    ];
```

- [ ] **Step 5: Run the tests**

Run: `php artisan test --compact --filter="ConditionCode|WeatherEmoji"`
Expected: PASS (and any pre-existing WeatherEmoji tests still green — the additions are additive keywords).

- [ ] **Step 6: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Services/Weather/WeatherKit/ConditionCode.php app/Digest/WeatherEmoji.php tests/Unit/Weather/ConditionCodeTest.php tests/Unit/Digest/WeatherEmojiTest.php
git commit -m "feat(weather): map WeatherKit conditionCode to labels; cover new emoji keywords"
```

---

## Task 4: `DestinationTimezone` resolver (Google Time Zone API)

**Files:**
- Create: `app/Services/Weather/DestinationTimezone.php`
- Test: `tests/Feature/Weather/DestinationTimezoneTest.php`

**Interfaces:**
- Produces: `DestinationTimezone::resolve(float $latitude, float $longitude): ?string` — the IANA zone for the coordinates, or `null` on failure (logged). Successful lookups are cached by rounded coordinate.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Weather/DestinationTimezoneTest.php`:

```php
<?php

use App\Services\Weather\DestinationTimezone;
use Illuminate\Support\Facades\Http;

it('resolves an IANA zone from coordinates', function () {
    Http::fake(['maps.googleapis.com/*' => Http::response([
        'status' => 'OK', 'timeZoneId' => 'America/New_York',
    ])]);

    expect(app(DestinationTimezone::class)->resolve(39.8467, -75.7116))->toBe('America/New_York');
});

it('returns null and logs on a non-OK status', function () {
    Http::fake(['maps.googleapis.com/*' => Http::response(['status' => 'ZERO_RESULTS'])]);

    expect(app(DestinationTimezone::class)->resolve(0.0, 0.0))->toBeNull();
});

it('caches a resolved zone (one HTTP call for repeat coordinates)', function () {
    Http::fake(['maps.googleapis.com/*' => Http::response(['status' => 'OK', 'timeZoneId' => 'Europe/London'])]);

    $svc = app(DestinationTimezone::class);
    $svc->resolve(51.5074, -0.1278);
    $svc->resolve(51.5074, -0.1278);

    Http::assertSentCount(1);
});
```

- [ ] **Step 2: Run to verify failure**

Run: `php artisan test --compact --filter=DestinationTimezone`
Expected: FAIL — class does not exist.

- [ ] **Step 3: Implement the resolver**

Create `app/Services/Weather/DestinationTimezone.php`:

```php
<?php

namespace App\Services\Weather;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Resolves a destination's IANA timezone from coordinates via the Google Time
 * Zone API (the same key already used for geocoding). WeatherKit needs a zone to
 * align daily highs to the destination's local day; this is the shared source
 * that also populates `trips.destination_timezone`. Returns null on any failure
 * — callers persist only real zones and fall back to the config default for a
 * one-off fetch — so a Google blip never fabricates a wrong stored zone.
 */
class DestinationTimezone
{
    private const ENDPOINT = 'https://maps.googleapis.com/maps/api/timezone/json';

    public function resolve(float $latitude, float $longitude): ?string
    {
        $key = 'tz:'.round($latitude, 3).','.round($longitude, 3);

        return Cache::remember($key, now()->addDays(30), function () use ($latitude, $longitude): ?string {
            try {
                $response = Http::timeout(8)->get(self::ENDPOINT, [
                    'location' => $latitude.','.$longitude,
                    'timestamp' => time(),
                    'key' => config('services.google.geocoding_key'),
                ]);
            } catch (Throwable $e) {
                Log::warning('destination timezone request failed', ['error' => $e->getMessage()]);

                return null;
            }

            $data = $response->json();

            if (($data['status'] ?? null) !== 'OK' || empty($data['timeZoneId'])) {
                Log::warning('destination timezone unresolved', ['status' => $data['status'] ?? 'no-status']);

                return null;
            }

            return (string) $data['timeZoneId'];
        });
    }
}
```

Note: `Cache::remember` caches `null` too. That is acceptable — a failed lookup is briefly cached and retried on the next window; callers treat `null` as "fall back for this fetch." If you prefer immediate retry on failure, guard the cache write to only store non-null (return early before `remember`); not required for correctness.

- [ ] **Step 4: Run the tests**

Run: `php artisan test --compact --filter=DestinationTimezone`
Expected: PASS.

- [ ] **Step 5: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Services/Weather/DestinationTimezone.php tests/Feature/Weather/DestinationTimezoneTest.php
git commit -m "feat(weather): resolve destination IANA timezone from coordinates (Google)"
```

---

## Task 5: `trips.destination_timezone` — column, model, resolve-at-creation (CAP-9)

**Files:**
- Create: `database/migrations/2026_07_04_000001_add_destination_timezone_to_trips_table.php`
- Modify: `app/Models/Trip.php` (fillable + `@property`)
- Modify: `app/Actions/CreateTrip.php` (inject `DestinationTimezone`; resolve before the transaction; persist; extend the `@param` shape)
- Modify: `docs/superpowers/plans/2026-07-03-timezone-aware-send-time.md` (SUPERSEDED banners on its Task 3 + Task 4)
- Test: `tests/Feature/Trip/DestinationTimezoneCaptureTest.php`

**Interfaces:**
- Consumes: `DestinationTimezone::resolve` (Task 4).
- Produces: `Trip::$destination_timezone` (`?string`, nullable, mass-assignable), written at creation before the welcome email renders.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/Trip/DestinationTimezoneCaptureTest.php`:

```php
<?php

use App\Actions\CreateTrip;
use App\Models\Trip;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::fake(['maps.googleapis.com/*' => Http::response(['status' => 'OK', 'timeZoneId' => 'America/New_York'])]);
});

function tripDetails(array $overrides = []): array
{
    return array_merge([
        'destination' => 'Kennett Square, PA',
        'canonical_place_name' => 'Kennett Square, PA, USA',
        'latitude' => 39.8467,
        'longitude' => -75.7116,
        'departure_date' => now('America/New_York')->addDays(10)->toDateString(),
        'return_date' => now('America/New_York')->addDays(17)->toDateString(),
    ], $overrides);
}

it('persists a nullable destination_timezone column', function () {
    $trip = Trip::factory()->create(['destination_timezone' => 'Europe/London']);
    expect($trip->fresh()->destination_timezone)->toBe('Europe/London');
    expect(Trip::factory()->create()->destination_timezone)->toBeNull();
});

it('resolves and stores the destination timezone at trip creation', function () {
    $trip = app(CreateTrip::class)->handle('traveler@example.com', tripDetails());

    expect($trip->fresh()->destination_timezone)->toBe('America/New_York');
});

it('leaves destination_timezone null when resolution fails', function () {
    Http::fake(['maps.googleapis.com/*' => Http::response(['status' => 'ZERO_RESULTS'])]);

    $trip = app(CreateTrip::class)->handle('traveler2@example.com', tripDetails(['latitude' => 0.0, 'longitude' => 0.0]));

    expect($trip->fresh()->destination_timezone)->toBeNull();
});
```

- [ ] **Step 2: Run to verify failure**

Run: `php artisan test --compact --filter=DestinationTimezoneCapture`
Expected: FAIL — column missing; `CreateTrip` doesn't resolve a zone.

- [ ] **Step 3: Create the migration**

Create `database/migrations/2026_07_04_000001_add_destination_timezone_to_trips_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The destination's IANA zone (e.g. Europe/London), resolved from coordinates
     * at trip creation. Nullable: a resolution failure leaves it null and callers
     * fall back to the config default for that fetch until it is filled.
     */
    public function up(): void
    {
        Schema::table('trips', function (Blueprint $table): void {
            $table->string('destination_timezone')->nullable()->after('longitude');
        });
    }

    public function down(): void
    {
        Schema::table('trips', function (Blueprint $table): void {
            $table->dropColumn('destination_timezone');
        });
    }
};
```

- [ ] **Step 4: Add it to the model**

In `app/Models/Trip.php`, add `'destination_timezone',` to `$fillable` (after `'longitude'`) and `@property string|null $destination_timezone` to the class docblock (after the `$longitude` line).

- [ ] **Step 5: Resolve + persist in `CreateTrip` (before the transaction)**

In `app/Actions/CreateTrip.php`: inject the resolver, resolve the zone **before** `DB::transaction` (external calls stay out of the txn, per the class contract), extend the `@param` shape with `destination_timezone` sourced here, and persist it on the trip.

Change the constructor:

```php
    public function __construct(
        private SendWelcomeEmail $sendWelcomeEmail,
        private \App\Services\Weather\DestinationTimezone $destinationTimezone,
    ) {}
```

In `handle`, add the resolution right after the email normalization and before `DB::transaction`:

```php
        $email = Str::lower(trim($email));

        // Resolve the destination zone up front (an external call, so outside the
        // DB txn) — ready before the welcome email's first tripcast, and reused on
        // every send. Null on failure; callers fall back for that one fetch.
        $destinationTimezone = $this->destinationTimezone->resolve(
            (float) $tripDetails['latitude'],
            (float) $tripDetails['longitude'],
        );
```

In the `$user->trips()->create([...])` array, add:

```php
                'destination_timezone' => $destinationTimezone,
```

- [ ] **Step 6: Migrate and run the tests**

Run: `php artisan migrate && php artisan test --compact --filter=DestinationTimezoneCapture`
Expected: PASS.

- [ ] **Step 7: Mark the send-time plan's superseded tasks**

In `docs/superpowers/plans/2026-07-03-timezone-aware-send-time.md`, add a banner immediately under the `## Task 3:` heading and under the `## Task 4:` heading:

```markdown
> **SUPERSEDED by `2026-07-04-weatherkit-provider-swap.md` (Task 5).** The `trips.destination_timezone` column is created and populated there (resolved from coordinates via the Google Time Zone API at trip creation). Skip this task; WeatherKit returns no `location.tz_id`, so `Forecast::$timezone` is not needed. The remaining tasks consume `Trip::$destination_timezone` unchanged.
```

- [ ] **Step 8: Run the trip suite, Pint, commit**

Run: `php artisan test --compact --filter=Trip`
Expected: PASS.

```bash
vendor/bin/pint --dirty --format agent
git add database/migrations app/Models/Trip.php app/Actions/CreateTrip.php tests/Feature/Trip/DestinationTimezoneCaptureTest.php docs/superpowers/plans/2026-07-03-timezone-aware-send-time.md
git commit -m "feat(trip): capture destination_timezone at creation via Google (CAP-9)"
```

---

## Task 6: `WeatherKitProvider` — the adapter (+ optional timezone port param)

**Files:**
- Modify: `app/Services/Weather/WeatherProvider.php` (add `?string $timezone = null`)
- Modify: `app/Services/Weather/WeatherApiProvider.php` (accept the param, ignore it)
- Modify: `app/Services/Weather/FakeWeatherProvider.php` (accept the param, ignore it)
- Create: `app/Services/Weather/WeatherKit/WeatherKitProvider.php`
- Create: `tests/Fixtures/weatherkit/kennett.json`
- Test: `tests/Feature/Weather/WeatherKitProviderTest.php`

**Interfaces:**
- Consumes: `WeatherKitToken::bearer` (Task 2), `ConditionCode::label` (Task 3), `DestinationTimezone::resolve` (Task 4), `config('tripcast.forecast.default_timezone')`.
- Produces: `WeatherKitProvider::fetchForecast(float $lat, float $lon, ?string $timezone = null): Forecast` mapping WeatherKit → `ForecastDay`.

- [ ] **Step 1: Add the optional param to the port and existing adapters**

In `app/Services/Weather/WeatherProvider.php`, change the method signature and doc:

```php
    /**
     * Fetch a fresh forecast for the coordinates, covering today through the
     * configured horizon. `$timezone` (IANA) aligns daily highs to the
     * destination's local day; adapters that don't need it ignore it.
     *
     * @throws WeatherProviderFailedException when the forecast can't be fetched.
     */
    public function fetchForecast(float $latitude, float $longitude, ?string $timezone = null): Forecast;
```

In `app/Services/Weather/WeatherApiProvider.php`, update the signature only (body unchanged):

```php
    public function fetchForecast(float $latitude, float $longitude, ?string $timezone = null): Forecast
```

In `app/Services/Weather/FakeWeatherProvider.php`, update the signature only:

```php
    public function fetchForecast(float $latitude, float $longitude, ?string $timezone = null): Forecast
```

- [ ] **Step 2: Add the fixture (trimmed from the verified live payload)**

Create `tests/Fixtures/weatherkit/kennett.json` (real Kennett Jul 4 values; two hours are enough for the apparent-temp peak):

```json
{
  "forecastDaily": {
    "days": [
      {
        "forecastStart": "2026-07-04T04:00:00Z",
        "forecastEnd": "2026-07-05T04:00:00Z",
        "conditionCode": "Thunderstorms",
        "temperatureMax": 36.23,
        "temperatureMin": 23.41,
        "precipitationChance": 0.52,
        "daytimeForecast": { "humidity": 0.51 }
      }
    ]
  },
  "forecastHourly": {
    "hours": [
      { "forecastStart": "2026-07-04T15:00:00Z", "temperature": 36.0, "temperatureApparent": 37.9 },
      { "forecastStart": "2026-07-04T20:00:00Z", "temperature": 33.0, "temperatureApparent": 34.1 }
    ]
  }
}
```

- [ ] **Step 3: Write the failing feature test**

Create `tests/Feature/Weather/WeatherKitProviderTest.php`:

```php
<?php

use App\Services\Weather\WeatherKit\WeatherKitProvider;
use App\Services\Weather\WeatherProviderFailedException;
use Illuminate\Support\Facades\Http;

function fakeWeatherKit(string $fixture = 'kennett'): void
{
    Http::fake([
        'weatherkit.apple.com/*' => Http::response(
            json_decode(file_get_contents(base_path("tests/Fixtures/weatherkit/{$fixture}.json")), true)
        ),
    ]);
}

it('maps the daily high to air-temp Fahrenheit (not heat index)', function () {
    fakeWeatherKit();

    $forecast = app(WeatherKitProvider::class)->fetchForecast(39.8467, -75.7116, 'America/New_York');
    $day = $forecast->days[0];

    expect($day->date)->toBe('2026-07-04')
        ->and($day->highF)->toBe(97)          // 36.23°C → 97°F, not the old 105
        ->and($day->lowF)->toBe(74)           // 23.41°C → 74°F
        ->and($day->highC)->toBe(36)
        ->and($day->precipChance)->toBe(52)   // 0.52 → 52%
        ->and($day->humidity)->toBe(51)       // daytimeForecast.humidity 0.51 → 51%
        ->and($day->conditionText)->toBe('Thunderstorms')
        ->and($day->feelsLikeHighF)->toBe(100) // peak temperatureApparent 37.9°C → 100°F
        ->and($day->isLimited())->toBeFalse();
});

it('sends the bearer token and timezone param', function () {
    fakeWeatherKit();

    app(WeatherKitProvider::class)->fetchForecast(39.8467, -75.7116, 'America/New_York');

    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'weatherkit.apple.com/api/v1/weather/en/39.8467/-75.7116')
            && str_contains($request->url(), 'timezone=America%2FNew_York')
            && $request->hasHeader('Authorization');
    });
});

it('throws WeatherProviderFailedException on an HTTP error', function () {
    Http::fake(['weatherkit.apple.com/*' => Http::response('nope', 401)]);

    app(WeatherKitProvider::class)->fetchForecast(39.8467, -75.7116, 'America/New_York');
})->throws(WeatherProviderFailedException::class);
```

Note: these resolve `WeatherKitProvider` from the container. Bind it in the test's setup or rely on Task 7's binding if you run tasks in order — to keep this task self-contained, add a `beforeEach` that binds a `WeatherKitToken` with a throwaway key:

```php
beforeEach(function () {
    [$private] = (function () {
        $res = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        openssl_pkey_export($res, $pem);

        return [$pem];
    })();

    app()->bind(\App\Services\Weather\WeatherKit\WeatherKitToken::class, fn () => new \App\Services\Weather\WeatherKit\WeatherKitToken('T', 'S', 'K', $private));
});
```

- [ ] **Step 4: Run to verify failure**

Run: `php artisan test --compact --filter=WeatherKitProvider`
Expected: FAIL — provider class does not exist.

- [ ] **Step 5: Implement the provider**

Create `app/Services/Weather/WeatherKit/WeatherKitProvider.php`:

```php
<?php

namespace App\Services\Weather\WeatherKit;

use App\Services\Weather\DestinationTimezone;
use App\Services\Weather\Forecast;
use App\Services\Weather\ForecastDay;
use App\Services\Weather\WeatherProvider;
use App\Services\Weather\WeatherProviderFailedException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Apple WeatherKit adapter (AD-1) — the only place its HTTP/JSON contract
 * appears. WeatherKit is metric-only, so highs/lows convert °C→°F and the 0–1
 * `precipitationChance`/`humidity` scale to int percent. Feels-like is the peak
 * hourly `temperatureApparent`, mirroring the WeatherAPI adapter. Missing values
 * stay null (limited), never fabricated (FR-7).
 */
class WeatherKitProvider implements WeatherProvider
{
    private const BASE = 'https://weatherkit.apple.com/api/v1/weather/en';

    public function __construct(
        private WeatherKitToken $token,
        private DestinationTimezone $timezones,
    ) {}

    public function fetchForecast(float $latitude, float $longitude, ?string $timezone = null): Forecast
    {
        $zone = $timezone
            ?? $this->timezones->resolve($latitude, $longitude)
            ?? config('tripcast.forecast.default_timezone');

        try {
            $response = Http::withToken($this->token->bearer())
                ->timeout(10)
                ->get(self::BASE."/{$latitude}/{$longitude}", [
                    'dataSets' => 'forecastDaily,forecastHourly',
                    'timezone' => $zone,
                ]);
        } catch (Throwable $e) {
            throw new WeatherProviderFailedException("WeatherKit request failed for [{$latitude},{$longitude}].", 0, $e);
        }

        if ($response->failed()) {
            throw new WeatherProviderFailedException("WeatherKit HTTP error [{$response->status()}].");
        }

        $data = $response->json();
        $days = $data['forecastDaily']['days'] ?? null;

        if (! is_array($days)) {
            throw new WeatherProviderFailedException("WeatherKit response missing forecastDaily for [{$latitude},{$longitude}].");
        }

        $apparentPeaks = $this->peakApparentByDate($data['forecastHourly']['hours'] ?? [], $zone);

        $mapped = [];

        foreach ($days as $day) {
            if (! is_array($day) || empty($day['forecastStart'])) {
                continue;
            }

            $date = CarbonImmutable::parse($day['forecastStart'])->setTimezone($zone)->toDateString();
            $maxC = isset($day['temperatureMax']) ? (float) $day['temperatureMax'] : null;
            $minC = isset($day['temperatureMin']) ? (float) $day['temperatureMin'] : null;
            $peakC = $apparentPeaks[$date] ?? null;

            $mapped[] = new ForecastDay(
                date: $date,
                conditionText: isset($day['conditionCode']) ? ConditionCode::label((string) $day['conditionCode']) : null,
                precipChance: isset($day['precipitationChance']) ? (int) round($day['precipitationChance'] * 100) : null,
                highC: $maxC !== null ? round($maxC) : null,
                highF: $maxC !== null ? $this->toF($maxC) : null,
                lowC: $minC !== null ? round($minC) : null,
                lowF: $minC !== null ? $this->toF($minC) : null,
                humidity: isset($day['daytimeForecast']['humidity'])
                    ? (int) round($day['daytimeForecast']['humidity'] * 100)
                    : null,
                feelsLikeHighC: $peakC !== null ? round($peakC) : null,
                feelsLikeHighF: $peakC !== null ? $this->toF($peakC) : null,
            );
        }

        return new Forecast($mapped);
    }

    /**
     * Highest hourly `temperatureApparent` (°C) per destination-local date.
     *
     * @param  array<int, mixed>  $hours
     * @return array<string, float>
     */
    private function peakApparentByDate(array $hours, string $zone): array
    {
        $peaks = [];

        foreach ($hours as $hour) {
            if (! is_array($hour) || ! isset($hour['temperatureApparent'], $hour['forecastStart'])) {
                continue;
            }

            $date = CarbonImmutable::parse($hour['forecastStart'])->setTimezone($zone)->toDateString();
            $apparent = (float) $hour['temperatureApparent'];

            if (! isset($peaks[$date]) || $apparent > $peaks[$date]) {
                $peaks[$date] = $apparent;
            }
        }

        return $peaks;
    }

    private function toF(float $celsius): int
    {
        return (int) round($celsius * 9 / 5 + 32);
    }
}
```

Note: `ForecastDay` carries `highC`/`highF` as floats today; rounding to whole degrees here matches how the renderer displays them and keeps the fixture assertions exact. If a caller needs sub-degree °C, drop the `round()` on the `*C` fields — the render layer already rounds. Confirm against `ForecastDay`/`ForecastRows` expectations during Step 6.

- [ ] **Step 6: Run the provider tests + the full weather/digest suite**

Run: `php artisan test --compact --filter="WeatherKitProvider|Weather|Digest"`
Expected: PASS. The interface param is additive (default null), so existing WeatherAPI/Fake/Sample/digest tests stay green.

- [ ] **Step 7: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Services/Weather tests/Feature/Weather/WeatherKitProviderTest.php tests/Fixtures/weatherkit
git commit -m "feat(weather): WeatherKitProvider mapping (C→F, %, feels-like) behind the port"
```

---

## Task 7: Bind the provider by config flag

**Files:**
- Modify: `app/Providers/AppServiceProvider.php`
- Test: `tests/Feature/Weather/ProviderBindingTest.php`

**Interfaces:**
- Consumes: `config('tripcast.forecast.provider')`, `config('services.weatherkit')`.
- Produces: `app(WeatherProvider::class)` resolves to `WeatherKitProvider` when the flag is `weatherkit`, else `WeatherApiProvider`; `WeatherKitToken` is built from config (reading the `.p8` via `base_path`).

- [ ] **Step 1: Write the failing binding test**

Create `tests/Feature/Weather/ProviderBindingTest.php`:

```php
<?php

use App\Services\Weather\WeatherApiProvider;
use App\Services\Weather\WeatherKit\WeatherKitProvider;
use App\Services\Weather\WeatherProvider;

it('resolves WeatherKit when the flag is set', function () {
    config()->set('tripcast.forecast.provider', 'weatherkit');
    config()->set('services.weatherkit', [
        'team_id' => 'T', 'service_id' => 'S', 'key_id' => 'K',
        'private_key_path' => 'tests/Fixtures/weatherkit/throwaway.p8',
    ]);

    expect(app(WeatherProvider::class))->toBeInstanceOf(WeatherKitProvider::class);
});

it('resolves WeatherAPI by default', function () {
    config()->set('tripcast.forecast.provider', 'weatherapi');

    expect(app(WeatherProvider::class))->toBeInstanceOf(WeatherApiProvider::class);
});
```

Create the throwaway key the test reads:

```bash
openssl ecparam -genkey -name prime256v1 -noout -out tests/Fixtures/weatherkit/throwaway.p8
```

(This fixture key is safe to commit — it is not a real Apple key. Add an exception so `*.p8` ignore doesn't drop it: `git add -f tests/Fixtures/weatherkit/throwaway.p8`.)

- [ ] **Step 2: Run to verify failure**

Run: `php artisan test --compact --filter=ProviderBinding`
Expected: FAIL — the container still always builds `WeatherApiProvider`.

- [ ] **Step 3: Update the binding**

In `app/Providers/AppServiceProvider.php`, find the existing `WeatherProvider` binding (currently constructing `WeatherApiProvider` from `config('services.weatherapi.key')`) and make it flag-aware. Replace that binding with:

```php
        $this->app->bind(WeatherProvider::class, function ($app): WeatherProvider {
            if (config('tripcast.forecast.provider') === 'weatherkit') {
                $kit = config('services.weatherkit');

                $token = new WeatherKitToken(
                    (string) $kit['team_id'],
                    (string) $kit['service_id'],
                    (string) $kit['key_id'],
                    (string) file_get_contents(base_path($kit['private_key_path'])),
                );

                return new WeatherKitProvider($token, $app->make(DestinationTimezone::class));
            }

            return new WeatherApiProvider((string) config('services.weatherapi.key'));
        });
```

Add the imports at the top of the file:

```php
use App\Services\Weather\DestinationTimezone;
use App\Services\Weather\WeatherApiProvider;
use App\Services\Weather\WeatherKit\WeatherKitProvider;
use App\Services\Weather\WeatherKit\WeatherKitToken;
use App\Services\Weather\WeatherProvider;
```

(Keep any existing `WeatherProvider`/`WeatherApiProvider` import lines; don't duplicate.)

- [ ] **Step 4: Run the binding test + full suite**

Run: `php artisan test --compact --filter=ProviderBinding` then `php artisan test --compact`
Expected: PASS (whole suite; default flag keeps WeatherAPI everywhere).

- [ ] **Step 5: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Providers/AppServiceProvider.php tests/Feature/Weather/ProviderBindingTest.php tests/Fixtures/weatherkit/throwaway.p8
git commit -m "feat(weather): bind the weather provider by config flag"
```

---

## Task 8: Pass the persisted zone from the send job

**Files:**
- Modify: `app/Jobs/SendTripDigest.php` (pass `trip.destination_timezone` into the fetch)
- Test: `tests/Feature/Digest/SendTripDigestTest.php` (add a case)

**Interfaces:**
- Consumes: `Trip::$destination_timezone` (Task 5), `WeatherProvider::fetchForecast(..., ?string $timezone)` (Task 6).

- [ ] **Step 1: Write the failing test**

In `tests/Feature/Digest/SendTripDigestTest.php`, add (match the file's existing `WeatherProvider` binding/setup):

```php
it('passes the trip destination timezone into the forecast fetch', function () {
    $trip = Trip::factory()->create(['destination_timezone' => 'Europe/London']);

    $captured = null;
    $this->mock(WeatherProvider::class, function ($mock) use (&$captured) {
        $mock->shouldReceive('fetchForecast')->andReturnUsing(function ($lat, $lon, $tz) use (&$captured) {
            $captured = $tz;

            return new \App\Services\Weather\Forecast([]);
        });
    });

    (new SendTripDigest($trip, '2026-07-01'))->handle(app(WeatherProvider::class));

    expect($captured)->toBe('Europe/London');
});
```

(If the existing tests bind `FakeWeatherProvider` rather than mocking, follow that pattern instead — assert via a spy that records the third argument.)

- [ ] **Step 2: Run to verify failure**

Run: `php artisan test --compact --filter=SendTripDigest`
Expected: FAIL — the job calls `fetchForecast($lat, $lon)` with no zone.

- [ ] **Step 3: Pass the zone in the job**

In `app/Jobs/SendTripDigest.php`, change the fetch call (around line 57) to pass the trip's stored zone:

```php
            $forecast = $weather->fetchForecast(
                $this->trip->latitude,
                $this->trip->longitude,
                $this->trip->destination_timezone,
            );
```

(WeatherAPI ignores the third arg; WeatherKit uses it, falling back internally when it is null.)

- [ ] **Step 4: Run the digest suite**

Run: `php artisan test --compact --filter=Digest`
Expected: PASS.

- [ ] **Step 5: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Jobs/SendTripDigest.php tests/Feature/Digest/SendTripDigestTest.php
git commit -m "feat(digest): pass the persisted destination timezone into the forecast fetch"
```

---

## Task 9: Apple Weather attribution in the digest footer

**Files:**
- Create: `resources/views/emails/partials/weather-attribution.blade.php`
- Modify: the digest email template/footer (`resources/views/emails/partials/forecast-days.blade.php` or the digest layout that wraps the footer — locate the footer include)
- Test: `tests/Feature/Digest/AttributionTest.php`

**Interfaces:**
- Consumes: `config('tripcast.forecast.provider')`.
- Produces: the digest footer renders the Apple Weather mark + a link to Apple's legal attribution page when the active provider is `weatherkit`.

- [ ] **Step 1: Fetch the attribution assets (one-time, during implementation)**

Fetch the current assets and legal URL so real values are inlined (not hotlinked):

```bash
curl -s https://weatherkit.apple.com/attribution/en | jq '{legalPageUrl, logoLight: (.["logo-light"] // .logoLight // .logo), name}'
```

Download the small light-mode logo the endpoint points to and base64-encode it for inlining:

```bash
# Replace <LOGO_URL> with the URL from the JSON above
curl -s "<LOGO_URL>" | base64 | tr -d '\n' > /tmp/apple-weather-logo.b64
```

- [ ] **Step 2: Write the failing test**

Create `tests/Feature/Digest/AttributionTest.php`. Render the digest mailable for a trip and assert the attribution appears only under WeatherKit. Mirror how other digest render tests build the mailable (`DigestMail`) in this suite:

```php
<?php

use App\Mail\DigestMail;
use App\Models\Trip;

function renderDigest(Trip $trip): string
{
    // Match the existing digest render tests' construction of DigestMail.
    return (new DigestMail(/* …existing args… */))->render();
}

it('shows Apple Weather attribution under WeatherKit', function () {
    config()->set('tripcast.forecast.provider', 'weatherkit');

    $html = renderDigest(Trip::factory()->create());

    expect($html)->toContain('alt="Apple Weather"')
        ->and($html)->toContain('https://developer.apple.com/weatherkit/data-source-attribution');
});

it('omits Apple attribution under WeatherAPI', function () {
    config()->set('tripcast.forecast.provider', 'weatherapi');

    expect(renderDigest(Trip::factory()->create()))->not->toContain('alt="Apple Weather"');
});
```

(Confirm the exact `legalPageUrl` from Step 1 and use it verbatim in the assertion and the partial.)

- [ ] **Step 3: Run to verify failure**

Run: `php artisan test --compact --filter=Attribution`
Expected: FAIL — no attribution partial rendered.

- [ ] **Step 4: Create the partial**

Create `resources/views/emails/partials/weather-attribution.blade.php` (inline base64 logo — email clients block remote images; paste the base64 from Step 1):

```blade
@if (config('tripcast.forecast.provider') === 'weatherkit')
    <p style="margin:16px 0 0;font-size:12px;color:#8a8a8a;text-align:center;">
        <a href="https://developer.apple.com/weatherkit/data-source-attribution/" style="color:#8a8a8a;text-decoration:none;">
            <img src="data:image/png;base64,PASTE_BASE64_FROM_STEP_1"
                 alt="Apple Weather" width="88" style="vertical-align:middle;border:0;" />
            Weather data &amp; other data sources
        </a>
    </p>
@endif
```

- [ ] **Step 5: Include it in the digest footer**

Locate the digest footer (search `@include` in `resources/views/emails/` for the footer partial that already renders the unsubscribe/end-trip links) and add, just above the closing footer block:

```blade
        @include('emails.partials.weather-attribution')
```

(Add the same to the plain-text digest if one carries a source line — a text line `Weather data by Apple Weather — https://developer.apple.com/weatherkit/data-source-attribution/` under the same `@if`.)

- [ ] **Step 6: Run the test**

Run: `php artisan test --compact --filter=Attribution`
Expected: PASS.

- [ ] **Step 7: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add resources/views/emails tests/Feature/Digest/AttributionTest.php
git commit -m "feat(digest): Apple Weather attribution in the footer under WeatherKit"
```

---

## Task 10: Local live-render verification + cutover

**Files:**
- Modify: `.env` (local only — flip the flag; not committed)
- Modify: `.env.example` (leave default `weatherapi`; note the cutover in deployment docs if present)

- [ ] **Step 1: Full suite green**

Run: `php artisan test --compact`
Expected: PASS (whole suite, still on the default `weatherapi` flag).

- [ ] **Step 2: Live render for a real trip via the log mailer**

Set locally (do not commit): `TRIPCAST_WEATHER_PROVIDER=weatherkit` and `MAIL_MAILER=log` in `.env`, then send a digest for a real hot-inland destination and a coastal control. Use the existing forced-send command (from the codebase, e.g. `digest:send {trip} --date= --to=`) against a seeded trip at Kennett Square and Dewey Beach.

Expected in `storage/logs/laravel.log`: Kennett high renders **97°F** (not 105), Dewey **86°F**; the footer shows the Apple Weather attribution with the legal link.

- [ ] **Step 3: Confirm the timezone was captured at creation**

For a trip created after Task 5, verify (read-only):

```bash
php artisan tinker --execute 'echo App\Models\Trip::latest()->first()->destination_timezone;'
```

Expected: a real IANA zone (e.g. `America/New_York`), proving CAP-9 populated it at creation.

- [ ] **Step 4: Cut over**

Set `TRIPCAST_WEATHER_PROVIDER=weatherkit` in the production env (per `docs/deployment.md`'s env-checklist process). Keep `.env.example` defaulting to `weatherapi` so the flag is an explicit opt-in. Update `docs/deployment.md` env checklist to list the four `APPLE_WEATHERKIT_*` keys + `TRIPCAST_WEATHER_PROVIDER`.

- [ ] **Step 5: Commit doc/env-example changes**

```bash
vendor/bin/pint --dirty --format agent
git add .env.example docs/deployment.md
git commit -m "docs(weather): WeatherKit env checklist + provider cutover notes"
```

---

## Self-Review (completed while writing)

- **Spec coverage:** CAP-1 provider behind the port (T6/T7) · CAP-2 accurate high, proven 97°F in the fixture test (T6) · CAP-3 C→F + 0–1→% conversions (T6) · CAP-4 feels-like from hourly `temperatureApparent` peak (T6) · CAP-5 `conditionCode`→label + emoji (T3) · CAP-6 ES256 JWT + `id` header, cached (T2) · CAP-7 Google timezone resolver + fallback (T4) · CAP-8 attribution (T9) · CAP-9 resolve+persist at trip creation (T5). Config/flag (T1), binding (T7), send-job wiring (T8), and live cutover (T10) complete the swap.
- **Placeholder scan:** two implementation-time reconciliations, both flagged inline — the attribution partial's base64 logo + exact `legalPageUrl` (T9, fetched in Step 1) and matching `DigestMail`/`SendTripDigestTest` construction to the existing suite's helpers (T8/T9). All backend logic is complete code.
- **Type consistency:** `WeatherKitToken::bearer(): string`; `DestinationTimezone::resolve(float,float): ?string`; `WeatherProvider::fetchForecast(float,float,?string): Forecast` used identically in WeatherApi/Fake/WeatherKit adapters and the `SendTripDigest` caller; `ConditionCode::label(string): string`; `Trip::$destination_timezone: ?string`.
- **Cross-plan:** `trips.destination_timezone` column shape matches the send-time plan verbatim; its Task 3 + Task 4 are banner-marked superseded (T5 Step 7).

## Execution Handoff

Two options:

1. **Subagent-Driven (recommended)** — a fresh subagent per task, review between tasks, fast iteration.
2. **Inline Execution** — execute in this session with checkpoints.

Which approach?
