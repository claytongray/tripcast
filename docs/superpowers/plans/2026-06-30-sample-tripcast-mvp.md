# Sample Tripcast (MVP) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A public landing-page "Send me a sample" hook that emails a cached-live sample tripcast for Reykjavik, whose "Get started" link is a magic link that creates/confirms the account and lands the visitor on their dashboard — with every request recorded for quantification.

**Architecture:** Reuse the existing weather port, magic-link auth, and digest day-row rendering. A new `SampleForecast` service caches one live Reykjavik forecast per day (static fallback on failure). A new `SampleController` issues a magic link via a refactored `RequestMagicLink::issue()`, records a `sample_requests` row, and queues a new `SampleDigestMail` whose footer CTA is that magic link. The day-row projection logic is extracted into a shared `ForecastRows` class so the sample looks identical to the real digest.

**Tech Stack:** Laravel 13, PHP 8.3, Pest 4, Inertia v3 + Vue 3, Tailwind v4, Wayfinder.

## Global Constraints

- PHP 8.3; curly braces on all control structures; explicit return types and param type hints; constructor property promotion.
- Tests: Pest. Run with `php artisan test --compact` filtered to the file/filter. Every change is test-covered.
- After any PHP change run `vendor/bin/pint --dirty --format agent` before committing.
- No new dependencies. No new base directories.
- All "today"/date math is on the **America/New_York** send clock (AD-7).
- Mail is **queued** on public endpoints (a slow transport must not block/500 the request).
- The sample endpoint shares the **magic-link rate-limiter buckets** (`magic-link:{email}`, `magic-link-ip:{ip}`) so it cannot become an unthrottled way to email login links.
- Copy is calm and lowercase-brand ("tripcast"), matching existing voice.
- Commit after each task with the shown message.

---

### Task 1: Config, `sample_requests` table, model + factory

**Files:**
- Modify: `config/tripcast.php` (add a `sample` block)
- Create: `database/migrations/2026_06_30_000001_create_sample_requests_table.php`
- Create: `app/Models/SampleRequest.php`
- Create: `database/factories/SampleRequestFactory.php`
- Test: `tests/Feature/Sample/SampleRequestModelTest.php`

**Interfaces:**
- Produces: `config('tripcast.sample.destination')` → `array{key:string,label:string,latitude:float,longitude:float}`; `App\Models\SampleRequest` with fillable `['user_id','email','destination']` and `user(): BelongsTo`.

- [ ] **Step 1: Add the config block.** In `config/tripcast.php`, add this top-level key inside the returned array (place it after the `magic_link` block):

```php
    /*
    |--------------------------------------------------------------------------
    | Public sample tripcast (MVP)
    |--------------------------------------------------------------------------
    |
    | One fixed demo destination. Its forecast is fetched live once per day and
    | cached; a baked-in fallback covers a provider outage so the public sample
    | never breaks.
    */
    'sample' => [
        'destination' => [
            'key' => 'reykjavik',
            'label' => 'Reykjavik, Iceland',
            'latitude' => 64.1466,
            'longitude' => -21.9426,
        ],
    ],
```

- [ ] **Step 2: Write the migration.** Create the migration file:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sample_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('email');
            $table->string('destination');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sample_requests');
    }
};
```

- [ ] **Step 3: Write the model.** Create `app/Models/SampleRequest.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One accepted public sample-tripcast request (acquisition tracking). Each send
 * writes a row; "how many sent" = row count, "who asked" = distinct user_id.
 *
 * @property int $id
 * @property int $user_id
 * @property string $email
 * @property string $destination
 */
#[Fillable(['user_id', 'email', 'destination'])]
class SampleRequest extends Model
{
    /** @use HasFactory<\Database\Factories\SampleRequestFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
```

- [ ] **Step 4: Write the factory.** Create `database/factories/SampleRequestFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<\App\Models\SampleRequest>
 */
class SampleRequestFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'email' => fake()->safeEmail(),
            'destination' => 'reykjavik',
        ];
    }
}
```

- [ ] **Step 5: Write the failing test.** Create `tests/Feature/Sample/SampleRequestModelTest.php`:

```php
<?php

use App\Models\SampleRequest;
use App\Models\User;

it('persists a sample request linked to a user', function () {
    $user = User::factory()->create();

    $row = SampleRequest::create([
        'user_id' => $user->id,
        'email' => $user->email,
        'destination' => 'reykjavik',
    ]);

    expect($row->user->is($user))->toBeTrue()
        ->and(SampleRequest::count())->toBe(1)
        ->and($row->destination)->toBe('reykjavik');
});

it('exposes the configured sample destination', function () {
    expect(config('tripcast.sample.destination.key'))->toBe('reykjavik')
        ->and(config('tripcast.sample.destination.latitude'))->toBe(64.1466);
});
```

- [ ] **Step 6: Run the test to verify it fails.**

Run: `php artisan test --compact tests/Feature/Sample/SampleRequestModelTest.php`
Expected: FAIL — table/model missing until migration runs (the test DB migrates fresh per the suite).

- [ ] **Step 7: Run the test to verify it passes.**

Run: `php artisan test --compact tests/Feature/Sample/SampleRequestModelTest.php`
Expected: PASS (2 tests).

- [ ] **Step 8: Pint + commit.**

```bash
vendor/bin/pint --dirty --format agent
git add config/tripcast.php database/migrations/2026_06_30_000001_create_sample_requests_table.php app/Models/SampleRequest.php database/factories/SampleRequestFactory.php tests/Feature/Sample/SampleRequestModelTest.php
git commit -m "feat(sample): config + sample_requests tracking table"
```

---

### Task 2: Extract `ForecastRows` projector from `DigestMail`

Reuse the digest's day-row projection (unit conversion, limited/feels-like/humidity rules) so the sample renders identically. Pure refactor — existing digest tests are the regression guard.

**Files:**
- Create: `app/Digest/ForecastRows.php`
- Modify: `app/Mail/DigestMail.php` (replace the body of `dayRows()` with a delegation)
- Test: `tests/Unit/Digest/ForecastRowsTest.php`

**Interfaces:**
- Produces: `App\Digest\ForecastRows::project(array $snapshot, string $departureDate, string $returnDate, bool $celsius): array` — returns `list<array{label:string,limited:bool,isDeparture:bool,conditionText:?string,emoji:string,precipChance:?int,high:?int,low:?int,humidity:?int,feelsLike:?int}>`.

- [ ] **Step 1: Write the projector class.** Create `app/Digest/ForecastRows.php` by moving the logic currently in `DigestMail::dayRows()` verbatim:

```php
<?php

namespace App\Digest;

use Carbon\CarbonImmutable;

/**
 * Projects a stored weather snapshot into render-ready day rows for the trip's
 * own window [departure, return], in a single temperature unit. Shared by the
 * daily digest and the public sample so both render identically (FR-7): a day
 * missing any core value is `limited` (calm marker, never fabricated); humidity
 * and feels-like show only when the feels-like delta makes them meaningful.
 */
class ForecastRows
{
    /**
     * @param  array{days: list<array<string, mixed>>}  $snapshot
     * @return list<array{label: string, limited: bool, isDeparture: bool, conditionText: ?string, emoji: string, precipChance: ?int, high: ?int, low: ?int, humidity: ?int, feelsLike: ?int}>
     */
    public function project(array $snapshot, string $departureDate, string $returnDate, bool $celsius): array
    {
        $tripDays = array_values(array_filter(
            $snapshot['days'],
            fn (array $day): bool => $day['date'] >= $departureDate && $day['date'] <= $returnDate,
        ));

        return array_map(function (array $day) use ($celsius, $departureDate): array {
            $limited = $day['conditionText'] === null
                || ($day['precipChance'] ?? null) === null
                || ($day['highF'] ?? null) === null
                || ($day['highC'] ?? null) === null
                || ($day['lowF'] ?? null) === null
                || ($day['lowC'] ?? null) === null;

            $high = $celsius ? ($day['highC'] ?? null) : ($day['highF'] ?? null);
            $low = $celsius ? ($day['lowC'] ?? null) : ($day['lowF'] ?? null);
            $feelsLikeHigh = $celsius ? ($day['feelsLikeHighC'] ?? null) : ($day['feelsLikeHighF'] ?? null);

            $highInt = $limited ? null : (int) round((float) $high);
            $feelsLike = $limited || $feelsLikeHigh === null ? null : (int) round((float) $feelsLikeHigh);

            $humidityThreshold = $celsius ? 3 : 5;
            $showHumidity = ! $limited
                && ($day['humidity'] ?? null) !== null
                && ($feelsLike === null || abs($highInt - $feelsLike) >= $humidityThreshold);

            return [
                'label' => CarbonImmutable::parse($day['date'])->format('D M j'),
                'limited' => $limited,
                'isDeparture' => $day['date'] === $departureDate,
                'conditionText' => $day['conditionText'] ?? null,
                'emoji' => $limited ? '' : WeatherEmoji::for($day['conditionText'] ?? null),
                'precipChance' => $limited ? null : (int) $day['precipChance'],
                'high' => $highInt,
                'low' => $limited ? null : (int) round((float) $low),
                'humidity' => $showHumidity ? (int) $day['humidity'] : null,
                'feelsLike' => $feelsLike,
            ];
        }, $tripDays);
    }
}
```

- [ ] **Step 2: Delegate from `DigestMail`.** In `app/Mail/DigestMail.php`, replace the entire body of the private `dayRows(): array` method with:

```php
    private function dayRows(): array
    {
        return app(ForecastRows::class)->project(
            $this->snapshot,
            $this->trip->departure_date->toDateString(),
            $this->trip->return_date->toDateString(),
            $this->trip->user->temperature_unit === User::UNIT_CELSIUS,
        );
    }
```

Add `use App\Digest\ForecastRows;` to the imports. Leave the existing `use App\Digest\WeatherEmoji;` and `use Carbon\CarbonImmutable;` — they are still used elsewhere in the file.

- [ ] **Step 3: Write the projector unit test.** Create `tests/Unit/Digest/ForecastRowsTest.php`:

```php
<?php

use App\Digest\ForecastRows;

function snapshotDay(string $date, array $overrides = []): array
{
    return array_merge([
        'date' => $date,
        'conditionText' => 'Sunny',
        'precipChance' => 10,
        'highC' => 20.0, 'highF' => 68.0,
        'lowC' => 12.0, 'lowF' => 54.0,
        'humidity' => 50,
        'feelsLikeHighC' => 20.0, 'feelsLikeHighF' => 68.0,
    ], $overrides);
}

it('projects only the trip-window days, departure first, in Fahrenheit', function () {
    $snapshot = ['days' => [
        snapshotDay('2026-07-01'), // before window — excluded
        snapshotDay('2026-07-02'),
        snapshotDay('2026-07-03'),
    ]];

    $rows = (new ForecastRows)->project($snapshot, '2026-07-02', '2026-07-03', false);

    expect($rows)->toHaveCount(2)
        ->and($rows[0]['isDeparture'])->toBeTrue()
        ->and($rows[0]['high'])->toBe(68)
        ->and($rows[0]['low'])->toBe(54);
});

it('marks a day limited when a core value is missing', function () {
    $snapshot = ['days' => [snapshotDay('2026-07-02', ['conditionText' => null])]];

    $rows = (new ForecastRows)->project($snapshot, '2026-07-02', '2026-07-02', false);

    expect($rows[0]['limited'])->toBeTrue()
        ->and($rows[0]['high'])->toBeNull();
});
```

- [ ] **Step 4: Run tests (projector + existing digest regression).**

Run: `php artisan test --compact tests/Unit/Digest/ForecastRowsTest.php tests/Feature/Digest`
Expected: PASS — new projector tests pass AND the existing DigestMail/digest render tests stay green (proves the refactor preserved behavior).

- [ ] **Step 5: Pint + commit.**

```bash
vendor/bin/pint --dirty --format agent
git add app/Digest/ForecastRows.php app/Mail/DigestMail.php tests/Unit/Digest/ForecastRowsTest.php
git commit -m "refactor(digest): extract ForecastRows projector for reuse"
```

---

### Task 3: `SampleForecast` service (cached-live + static fallback)

**Files:**
- Create: `app/Services/Sample/SampleForecast.php`
- Test: `tests/Feature/Sample/SampleForecastTest.php`

**Interfaces:**
- Consumes: `App\Services\Weather\WeatherProvider::fetchForecast(float,float): Forecast` (throws `WeatherProviderFailedException`); `config('tripcast.sample.destination')`.
- Produces: `App\Services\Sample\SampleForecast::forecast(): App\Services\Weather\Forecast`.

- [ ] **Step 1: Write the service.** Create `app/Services/Sample/SampleForecast.php`:

```php
<?php

namespace App\Services\Sample;

use App\Services\Weather\Forecast;
use App\Services\Weather\ForecastDay;
use App\Services\Weather\WeatherProvider;
use App\Services\Weather\WeatherProviderFailedException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * The forecast behind the public sample tripcast: the configured demo city's
 * real forecast, fetched live once per America/New_York day and cached. If the
 * provider is down on a cold cache, a baked-in synthetic forecast is returned
 * (and not cached, so the next request retries live) — the public sample never
 * shows broken or empty weather.
 */
class SampleForecast
{
    public function __construct(private WeatherProvider $weather) {}

    public function forecast(): Forecast
    {
        $destination = config('tripcast.sample.destination');
        $today = CarbonImmutable::now('America/New_York');
        $key = "sample-forecast:{$destination['key']}:{$today->toDateString()}";

        try {
            return Cache::remember(
                $key,
                $today->endOfDay(),
                fn (): Forecast => $this->weather->fetchForecast(
                    (float) $destination['latitude'],
                    (float) $destination['longitude'],
                ),
            );
        } catch (WeatherProviderFailedException) {
            return $this->fallback($today);
        }
    }

    /**
     * A calm, pleasant synthetic forecast spanning today..today+3 so it always
     * covers the sample trip window (tomorrow..tomorrow+2). Both units provided.
     */
    private function fallback(CarbonImmutable $today): Forecast
    {
        $days = [];

        for ($offset = 0; $offset <= 3; $offset++) {
            $days[] = new ForecastDay(
                date: $today->addDays($offset)->toDateString(),
                conditionText: 'Partly cloudy',
                precipChance: 20,
                highC: 9.0,
                highF: 48.0,
                lowC: 3.0,
                lowF: 37.0,
                humidity: 70,
                feelsLikeHighC: 7.0,
                feelsLikeHighF: 45.0,
            );
        }

        return new Forecast($days);
    }
}
```

- [ ] **Step 2: Write the failing test.** Create `tests/Feature/Sample/SampleForecastTest.php`:

```php
<?php

use App\Services\Sample\SampleForecast;
use App\Services\Weather\Forecast;
use App\Services\Weather\ForecastDay;
use App\Services\Weather\WeatherProvider;
use App\Services\Weather\WeatherProviderFailedException;
use Illuminate\Support\Carbon;

afterEach(fn () => Carbon::setTestNow());

it('caches the live forecast per day (a second call does not re-fetch)', function () {
    Carbon::setTestNow(Carbon::parse('2026-06-30 09:00', 'America/New_York'));

    $calls = 0;
    $this->mock(WeatherProvider::class, function ($mock) use (&$calls) {
        $mock->shouldReceive('fetchForecast')->andReturnUsing(function () use (&$calls) {
            $calls++;

            return new Forecast([new ForecastDay(date: '2026-06-30', conditionText: 'Sunny', precipChance: 5, highC: 10.0, highF: 50.0, lowC: 4.0, lowF: 39.0)]);
        });
    });

    $service = app(SampleForecast::class);
    $service->forecast();
    $service->forecast();

    expect($calls)->toBe(1);
});

it('falls back to a synthetic forecast when the provider fails, without caching it', function () {
    Carbon::setTestNow(Carbon::parse('2026-06-30 09:00', 'America/New_York'));

    $calls = 0;
    $this->mock(WeatherProvider::class, function ($mock) use (&$calls) {
        $mock->shouldReceive('fetchForecast')->andReturnUsing(function () use (&$calls) {
            $calls++;
            throw new WeatherProviderFailedException('down');
        });
    });

    $service = app(SampleForecast::class);

    $first = $service->forecast();
    $second = $service->forecast();

    expect($first->days)->not->toBeEmpty()
        ->and($calls)->toBe(2); // fallback not cached → retried live
});
```

- [ ] **Step 3: Run the test to verify it fails.**

Run: `php artisan test --compact tests/Feature/Sample/SampleForecastTest.php`
Expected: FAIL — `SampleForecast` class not found (before Step 1 applied) / assertions fail until implemented.

- [ ] **Step 4: Run the test to verify it passes.**

Run: `php artisan test --compact tests/Feature/Sample/SampleForecastTest.php`
Expected: PASS (2 tests).

- [ ] **Step 5: Pint + commit.**

```bash
vendor/bin/pint --dirty --format agent
git add app/Services/Sample/SampleForecast.php tests/Feature/Sample/SampleForecastTest.php
git commit -m "feat(sample): SampleForecast — cached-live forecast with static fallback"
```

---

### Task 4: Refactor `RequestMagicLink` to expose `issue()`

Let callers obtain a magic-link URL without sending the standard `MagicLinkMail`, so the sample can embed it in its own email.

**Files:**
- Modify: `app/Actions/RequestMagicLink.php`
- Test: `tests/Feature/Auth/RequestMagicLinkIssueTest.php`

**Interfaces:**
- Produces: `App\Actions\RequestMagicLink::issue(string $email): array{user: App\Models\User, url: string, expires_at: Carbon\CarbonImmutable, ttl_minutes: int}`. `handle()` keeps its existing return shape plus a `url` key, and still queues `MagicLinkMail`.

- [ ] **Step 1: Refactor.** In `app/Actions/RequestMagicLink.php`, replace the `handle()` method with these two methods (keep `hash()` unchanged):

```php
    /**
     * Issue a single-use magic-link token and return its consume URL WITHOUT
     * sending any email. Create-or-match the user by case-insensitive email and
     * atomically rotate their unconsumed tokens. The raw token never persists.
     *
     * @return array{user: User, url: string, expires_at: CarbonImmutable, ttl_minutes: int}
     */
    public function issue(string $email): array
    {
        $email = Str::lower(trim($email));
        $ttlMinutes = (int) config('tripcast.magic_link.ttl_minutes');

        $user = User::firstOrCreate(['email' => $email]);

        $rawToken = Str::random(64);
        $expiresAt = now()->addMinutes($ttlMinutes);

        DB::transaction(function () use ($user, $rawToken, $expiresAt) {
            $user->loginTokens()->whereNull('consumed_at')->delete();

            $user->loginTokens()->create([
                'token_hash' => self::hash($rawToken),
                'expires_at' => $expiresAt,
            ]);
        });

        return [
            'user' => $user,
            'url' => URL::route('magic.consume', ['token' => $rawToken]),
            'expires_at' => $expiresAt,
            'ttl_minutes' => $ttlMinutes,
        ];
    }

    /**
     * Issue a magic link and email it (the standard login path, AD-6).
     *
     * @return array{user: User, url: string, expires_at: CarbonImmutable, ttl_minutes: int}
     */
    public function handle(string $email): array
    {
        $result = $this->issue($email);

        // Queued so a slow/failing transport can't block the request thread or
        // 500 after the token is persisted — queue retries handle transient
        // delivery failures.
        Mail::to($result['user']->email)->queue(new MagicLinkMail($result['url'], $result['ttl_minutes']));

        return $result;
    }
```

- [ ] **Step 2: Write the failing test.** Create `tests/Feature/Auth/RequestMagicLinkIssueTest.php`:

```php
<?php

use App\Actions\RequestMagicLink;
use App\Mail\MagicLinkMail;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

it('issue() creates the user and a token URL without sending mail', function () {
    Mail::fake();

    $result = app(RequestMagicLink::class)->issue('Sampler@Example.com');

    expect($result['user'])->toBeInstanceOf(User::class)
        ->and($result['user']->email)->toBe('sampler@example.com')
        ->and($result['url'])->toContain('/auth/magic/')
        ->and($result['user']->loginTokens()->count())->toBe(1);

    Mail::assertNothingQueued();
});

it('handle() still issues and queues the magic-link email', function () {
    Mail::fake();

    app(RequestMagicLink::class)->handle('sampler@example.com');

    Mail::assertQueued(MagicLinkMail::class);
});
```

- [ ] **Step 3: Run the test to verify it fails.**

Run: `php artisan test --compact tests/Feature/Auth/RequestMagicLinkIssueTest.php`
Expected: FAIL — `issue()` undefined.

- [ ] **Step 4: Run tests (new + existing magic-link regression).**

Run: `php artisan test --compact tests/Feature/Auth`
Expected: PASS — new tests pass and existing magic-link/login tests stay green.

- [ ] **Step 5: Pint + commit.**

```bash
vendor/bin/pint --dirty --format agent
git add app/Actions/RequestMagicLink.php tests/Feature/Auth/RequestMagicLinkIssueTest.php
git commit -m "refactor(auth): expose RequestMagicLink::issue() (link without sending)"
```

---

### Task 5: `SampleDigestMail` + sample email views

**Files:**
- Create: `app/Mail/SampleDigestMail.php`
- Create: `resources/views/emails/sample-digest.blade.php`
- Create: `resources/views/emails/sample-digest-text.blade.php`
- Test: `tests/Feature/Sample/SampleDigestMailTest.php`

**Interfaces:**
- Consumes: `ForecastRows::project(...)`, `App\Digest\CountdownLine` (`placeShort(Trip): string`, `headerLine(Trip, CarbonInterface): string`, `dateRange(Trip): string`), an unsaved `App\Models\Trip` with its `user` relation set.
- Produces: `new App\Mail\SampleDigestMail(Trip $trip, array $snapshot, string $getStartedUrl)`.

- [ ] **Step 1: Write the mailable.** Create `app/Mail/SampleDigestMail.php`:

```php
<?php

namespace App\Mail;

use App\Digest\CountdownLine;
use App\Digest\ForecastRows;
use App\Models\User;
use App\Models\Trip;
use Carbon\CarbonImmutable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * The public sample tripcast: a real-looking digest for a fixed demo trip, whose
 * footer CTA is a magic link ("Get started"). Unlike the daily digest it carries
 * NO unsubscribe/feedback/promo — a sample is a one-off, user-requested email,
 * not a subscription. Queued by the caller; renders from the passed snapshot.
 */
class SampleDigestMail extends Mailable
{
    public function __construct(
        public Trip $trip,
        public array $snapshot,
        public string $getStartedUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your sample tripcast — '.$this->trip->canonical_place_name,
        );
    }

    public function content(): Content
    {
        $countdown = app(CountdownLine::class);
        $today = CarbonImmutable::now('America/New_York')->startOfDay();

        $days = app(ForecastRows::class)->project(
            $this->snapshot,
            $this->trip->departure_date->toDateString(),
            $this->trip->return_date->toDateString(),
            $this->trip->user->temperature_unit === User::UNIT_CELSIUS,
        );

        return new Content(
            view: 'emails.sample-digest',
            text: 'emails.sample-digest-text',
            with: [
                'placeShort' => $countdown->placeShort($this->trip),
                'headerLine' => $countdown->headerLine($this->trip, $today),
                'dateRange' => $countdown->dateRange($this->trip),
                'days' => $days,
                'getStartedUrl' => $this->getStartedUrl,
            ],
        );
    }
}
```

- [ ] **Step 2: Write the HTML view.** Create `resources/views/emails/sample-digest.blade.php`. It mirrors the digest's card + day rows, then a "Get started" CTA footer (no unsubscribe/feedback):

```blade
<!DOCTYPE html>
<html lang="en" dir="ltr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light dark">
    <meta name="supported-color-schemes" content="light dark">
    <title>{{ $placeShort }} — {{ $headerLine }}</title>
    <style>
        :root { color-scheme: light dark; supported-color-schemes: light dark; }
        @media (prefers-color-scheme: dark) {
            .tc-body { background: #0E1822 !important; }
            .tc-card { background: #16232F !important; }
            .tc-ink { color: #E8EEF4 !important; }
            .tc-ink-secondary { color: #9FB0BF !important; }
            .tc-divider { border-color: #24313D !important; }
        }
    </style>
</head>
<body class="tc-body" style="margin:0; padding:0; background:#F6F9FC;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#F6F9FC;" class="tc-body">
        <tr>
            <td align="center" style="padding:24px;">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px; width:100%;">
                    <tr>
                        <td class="tc-card" style="background:#FFFFFF; border-radius:14px; padding:32px 32px 36px;">

                            <p class="tc-ink-secondary" style="margin:0 0 12px; font-family:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:12px; line-height:16px; letter-spacing:0.06em; text-transform:uppercase; color:#51616E;">Sample tripcast</p>

                            <h1 class="tc-ink" style="margin:0 0 6px; font-family:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:26px; line-height:32px; font-weight:600; color:#16202B;">
                                {{ $placeShort }}
                            </h1>
                            <p class="tc-ink" style="margin:0 0 2px; font-family:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:19px; line-height:26px; font-weight:600; color:#16202B;">
                                {{ $headerLine }}
                            </p>
                            <p class="tc-ink-secondary" style="margin:0 0 28px; font-family:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:14px; line-height:20px; color:#51616E;">
                                {{ $dateRange }}
                            </p>

                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                                @foreach ($days as $day)
                                    <tr>
                                        <td class="tc-divider" style="padding:16px 0; border-top:1px solid #E3EAF1; font-family:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;">
                                            @if ($day['isDeparture'])
                                                <p class="tc-ink" style="margin:0 0 8px; font-size:14px; line-height:20px; font-weight:600; color:#16202B;">The start of your trip!</p>
                                            @endif
                                            <p class="tc-ink-secondary" style="margin:0 0 4px; font-size:14px; line-height:20px; color:#51616E;">{{ $day['label'] }}</p>
                                            @if ($day['limited'])
                                                <p class="tc-ink-secondary" style="margin:0; font-size:16px; line-height:24px; color:#51616E;">Limited data</p>
                                            @else
                                                <p class="tc-ink" style="margin:0 0 4px; font-size:17px; line-height:24px; color:#16202B; font-variant-numeric:tabular-nums;">{{ $day['emoji'] }} {{ $day['high'] }}° / {{ $day['low'] }}°{{ $day['feelsLike'] !== null ? ' • feels like '.$day['feelsLike'].'°' : '' }}</p>
                                                <p class="tc-ink-secondary" style="margin:0; font-size:14px; line-height:20px; color:#51616E;">{{ $day['conditionText'] }} • {{ $day['precipChance'] }}% precipitation{{ $day['humidity'] !== null ? ' • '.$day['humidity'].'% humidity' : '' }}</p>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </table>

                            {{-- Sample CTA: the link doubles as the account verify/login (magic link) --}}
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-top:32px;">
                                <tr>
                                    <td class="tc-divider" style="border-top:1px solid #E3EAF1; padding-top:28px;" align="center">
                                        <p class="tc-ink" style="margin:0 0 16px; font-family:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:16px; line-height:24px; color:#16202B;">Ready to create your own?</p>
                                        <a href="{{ $getStartedUrl }}" style="display:inline-block; background:#2563A6; color:#FFFFFF; font-family:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:16px; line-height:20px; font-weight:600; text-decoration:none; padding:12px 24px; border-radius:8px;">Get started &rarr;</a>
                                    </td>
                                </tr>
                            </table>

                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
```

- [ ] **Step 3: Write the text view.** Create `resources/views/emails/sample-digest-text.blade.php`:

```blade
Sample tripcast — {{ $placeShort }}
{{ $headerLine }}
{{ $dateRange }}

@foreach ($days as $day)
{{ $day['label'] }}
@if ($day['limited'])
Limited data
@else
{{ $day['high'] }}° / {{ $day['low'] }}°{{ $day['feelsLike'] !== null ? ' (feels like '.$day['feelsLike'].'°)' : '' }} — {{ $day['conditionText'] }}, {{ $day['precipChance'] }}% precipitation
@endif

@endforeach
Ready to create your own? Get started:
{{ $getStartedUrl }}
```

- [ ] **Step 4: Write the failing test.** Create `tests/Feature/Sample/SampleDigestMailTest.php`:

```php
<?php

use App\Mail\SampleDigestMail;
use App\Models\Trip;
use App\Models\User;
use Illuminate\Support\Carbon;

afterEach(fn () => Carbon::setTestNow());

function sampleTrip(): Trip
{
    Carbon::setTestNow(Carbon::parse('2026-06-30 09:00', 'America/New_York'));

    $user = new User(['email' => 'sampler@example.com', 'temperature_unit' => User::UNIT_FAHRENHEIT]);
    $user->id = 90001;
    $user->plan = User::PLAN_FREE;

    $trip = new Trip([
        'destination_raw' => 'Reykjavik, Iceland',
        'canonical_place_name' => 'Reykjavik, Iceland',
        'latitude' => 64.1466,
        'longitude' => -21.9426,
        'departure_date' => '2026-07-01',
        'return_date' => '2026-07-03',
        'status' => Trip::STATUS_ACTIVE,
    ]);
    $trip->id = 80001;
    $trip->setRelation('user', $user);

    return $trip;
}

function sampleSnapshot(): array
{
    return ['days' => [
        ['date' => '2026-07-01', 'conditionText' => 'Cloudy', 'precipChance' => 30, 'highC' => 9.0, 'highF' => 48.0, 'lowC' => 3.0, 'lowF' => 37.0, 'humidity' => 70, 'feelsLikeHighC' => 7.0, 'feelsLikeHighF' => 45.0],
        ['date' => '2026-07-02', 'conditionText' => 'Sunny', 'precipChance' => 10, 'highC' => 11.0, 'highF' => 52.0, 'lowC' => 4.0, 'lowF' => 39.0, 'humidity' => 60, 'feelsLikeHighC' => 11.0, 'feelsLikeHighF' => 52.0],
        ['date' => '2026-07-03', 'conditionText' => 'Rain', 'precipChance' => 80, 'highC' => 8.0, 'highF' => 46.0, 'lowC' => 3.0, 'lowF' => 37.0, 'humidity' => 85, 'feelsLikeHighC' => 6.0, 'feelsLikeHighF' => 43.0],
    ]];
}

it('renders the Get started CTA with the magic-link url', function () {
    $mail = new SampleDigestMail(sampleTrip(), sampleSnapshot(), 'https://tripcast.test/auth/magic/abc123');

    $mail->assertSeeInHtml('Get started');
    $mail->assertSeeInHtml('https://tripcast.test/auth/magic/abc123');
    $mail->assertSeeInHtml('Reykjavik, Iceland');
});

it('omits unsubscribe and feedback (a sample is not a subscription)', function () {
    $mail = new SampleDigestMail(sampleTrip(), sampleSnapshot(), 'https://tripcast.test/auth/magic/abc123');

    $mail->assertDontSeeInHtml('Unsubscribe');
    $mail->assertDontSeeInHtml('unsubscribe');
});
```

- [ ] **Step 5: Run the test to verify it fails, then passes.**

Run: `php artisan test --compact tests/Feature/Sample/SampleDigestMailTest.php`
Expected: first FAIL (class/view missing), then PASS (2 tests) once Steps 1–3 are in place.

- [ ] **Step 6: Pint + commit.**

```bash
vendor/bin/pint --dirty --format agent
git add app/Mail/SampleDigestMail.php resources/views/emails/sample-digest.blade.php resources/views/emails/sample-digest-text.blade.php tests/Feature/Sample/SampleDigestMailTest.php
git commit -m "feat(sample): SampleDigestMail + sample-digest views with Get started CTA"
```

---

### Task 6: `SampleController`, shared throttle trait, form request, route

**Files:**
- Create: `app/Http/Controllers/Concerns/ThrottlesMagicLink.php`
- Modify: `app/Http/Controllers/Auth/MagicLinkController.php` (use the trait; delete its now-duplicated `ensureNotThrottled`/`throttle`)
- Create: `app/Http/Requests/SendSampleRequest.php`
- Create: `app/Http/Controllers/SampleController.php`
- Modify: `routes/web.php` (add `POST /sample`)
- Test: `tests/Feature/Sample/SampleEndpointTest.php`

**Interfaces:**
- Consumes: `RequestMagicLink::issue()`, `SampleForecast::forecast()`, `SampleDigestMail`, `SampleRequest` model, `config('tripcast.sample.destination')`, `config('tripcast.magic_link.throttle.*')`.
- Produces: route `sample.store` (`POST /sample`); trait method `ensureNotThrottled(Request $request, string $email): void`.

- [ ] **Step 1: Extract the throttle trait.** Create `app/Http/Controllers/Concerns/ThrottlesMagicLink.php` by moving the two protected methods currently on `MagicLinkController` verbatim:

```php
<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Shared per-email + per-IP throttle for endpoints that issue a magic link
 * (login and the public sample). Both share the same buckets so the sample can't
 * be used to bypass the login-link send limit.
 */
trait ThrottlesMagicLink
{
    protected function ensureNotThrottled(Request $request, string $email): void
    {
        $decaySeconds = (int) config('tripcast.magic_link.throttle.decay_minutes') * 60;

        $this->throttle(
            'magic-link:'.Str::lower($email),
            (int) config('tripcast.magic_link.throttle.max_attempts'),
            $decaySeconds,
        );

        $this->throttle(
            'magic-link-ip:'.$request->ip(),
            (int) config('tripcast.magic_link.throttle.ip_max_attempts'),
            $decaySeconds,
        );
    }

    protected function throttle(string $key, int $maxAttempts, int $decaySeconds): void
    {
        if (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
            $minutes = max(1, (int) ceil(RateLimiter::availableIn($key) / 60));

            throw ValidationException::withMessages([
                'email' => "Too many requests. Try again in {$minutes} minute".($minutes === 1 ? '' : 's').'.',
            ]);
        }

        RateLimiter::hit($key, $decaySeconds);
    }
}
```

- [ ] **Step 2: Use the trait in `MagicLinkController`.** In `app/Http/Controllers/Auth/MagicLinkController.php`: add `use App\Http\Controllers\Concerns\ThrottlesMagicLink;` to the imports, add `use ThrottlesMagicLink;` inside the class body (top), and **delete** the now-duplicated `ensureNotThrottled()` and `throttle()` methods from the class. Remove the now-unused `RateLimiter`, `Str`, and `ValidationException` imports if PHPStan/Pint flag them as unused.

- [ ] **Step 3: Write the form request.** Create `app/Http/Requests/SendSampleRequest.php`:

```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the public "send me a sample" form. Email only — the destination is
 * fixed server-side (config) in this MVP.
 */
class SendSampleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.required' => 'Enter your email and we’ll send a sample.',
            'email.email' => 'That email doesn’t look right.',
        ];
    }
}
```

- [ ] **Step 4: Write the controller.** Create `app/Http/Controllers/SampleController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Actions\RequestMagicLink;
use App\Http\Controllers\Concerns\ThrottlesMagicLink;
use App\Http\Requests\SendSampleRequest;
use App\Mail\SampleDigestMail;
use App\Models\SampleRequest;
use App\Models\Trip;
use App\Services\Sample\SampleForecast;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Mail;

/**
 * The public "send me a sample" endpoint. Issues a magic link (the email's
 * "Get started" CTA), queues the sample digest for the fixed demo destination,
 * and records the request for acquisition tracking.
 */
class SampleController extends Controller
{
    use ThrottlesMagicLink;

    public function store(SendSampleRequest $request, RequestMagicLink $magicLink, SampleForecast $sampleForecast): RedirectResponse
    {
        $email = $request->validated()['email'];

        $this->ensureNotThrottled($request, $email);

        $issued = $magicLink->issue($email);
        $destination = config('tripcast.sample.destination');

        $trip = $this->sampleTrip($destination, $issued['user']);
        $snapshot = $sampleForecast->forecast()->toArray();

        Mail::to($email)->queue(new SampleDigestMail($trip, $snapshot, $issued['url']));

        SampleRequest::create([
            'user_id' => $issued['user']->id,
            'email' => $email,
            'destination' => $destination['key'],
        ]);

        return back()->with('sample_sent', $email);
    }

    /**
     * An unsaved demo trip (no DB writes) for the fixed destination, windowed
     * tomorrow..tomorrow+1 so the ~3-day live forecast (today..today+2) fully
     * covers it. The user relation drives the render's temperature unit.
     *
     * @param  array{key:string,label:string,latitude:float,longitude:float}  $destination
     */
    private function sampleTrip(array $destination, \App\Models\User $user): Trip
    {
        $today = CarbonImmutable::now('America/New_York');

        $trip = new Trip([
            'destination_raw' => $destination['label'],
            'canonical_place_name' => $destination['label'],
            'latitude' => $destination['latitude'],
            'longitude' => $destination['longitude'],
            'departure_date' => $today->addDay()->toDateString(),
            'return_date' => $today->addDays(2)->toDateString(),
            'status' => Trip::STATUS_ACTIVE,
        ]);
        $trip->setRelation('user', $user);

        return $trip;
    }
}
```

- [ ] **Step 5: Add the route.** In `routes/web.php`, add `use App\Http\Controllers\SampleController;` to the imports and this guest-facing route near the public landing routes (outside the `auth` group):

```php
// Public sample tripcast (MVP): emails a sample whose "Get started" CTA is a
// magic link. Throttled in-controller, sharing the magic-link buckets.
Route::post('sample', [SampleController::class, 'store'])->name('sample.store');
```

- [ ] **Step 6: Write the failing test.** Create `tests/Feature/Sample/SampleEndpointTest.php`:

```php
<?php

use App\Mail\SampleDigestMail;
use App\Models\SampleRequest;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;

use function Pest\Laravel\post;

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-06-30 09:00', 'America/New_York'));
    RateLimiter::clear('magic-link:sampler@example.com');
    RateLimiter::clear('magic-link-ip:127.0.0.1');
});

afterEach(fn () => Carbon::setTestNow());

it('queues a sample, creates the user, and records one request row', function () {
    Mail::fake();

    post(route('sample.store'), ['email' => 'sampler@example.com'])
        ->assertRedirect()
        ->assertSessionHas('sample_sent', 'sampler@example.com');

    Mail::assertQueued(SampleDigestMail::class);
    expect(User::where('email', 'sampler@example.com')->exists())->toBeTrue()
        ->and(SampleRequest::where('email', 'sampler@example.com')->count())->toBe(1);
});

it('writes a second row for a repeat request', function () {
    Mail::fake();

    post(route('sample.store'), ['email' => 'sampler@example.com']);
    post(route('sample.store'), ['email' => 'sampler@example.com']);

    expect(SampleRequest::where('email', 'sampler@example.com')->count())->toBe(2);
});

it('rejects an invalid email and records nothing', function () {
    Mail::fake();

    post(route('sample.store'), ['email' => 'not-an-email'])
        ->assertSessionHasErrors('email');

    Mail::assertNothingQueued();
    expect(SampleRequest::count())->toBe(0);
});

it('throttles after the configured per-email attempts', function () {
    Mail::fake();
    config(['tripcast.magic_link.throttle.max_attempts' => 2]);

    post(route('sample.store'), ['email' => 'sampler@example.com']);
    post(route('sample.store'), ['email' => 'sampler@example.com']);
    post(route('sample.store'), ['email' => 'sampler@example.com'])
        ->assertSessionHasErrors('email');

    expect(SampleRequest::where('email', 'sampler@example.com')->count())->toBe(2);
});
```

- [ ] **Step 7: Run tests (endpoint + magic-link regression for the trait move).**

Run: `php artisan test --compact tests/Feature/Sample/SampleEndpointTest.php tests/Feature/Auth`
Expected: PASS — endpoint tests pass and the existing magic-link throttle tests stay green after the trait extraction.

- [ ] **Step 8: Pint + commit.**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Controllers/Concerns/ThrottlesMagicLink.php app/Http/Controllers/Auth/MagicLinkController.php app/Http/Requests/SendSampleRequest.php app/Http/Controllers/SampleController.php routes/web.php tests/Feature/Sample/SampleEndpointTest.php
git commit -m "feat(sample): public POST /sample endpoint (magic-link CTA + tracking)"
```

---

### Task 7: Landing "Send me a sample" modal

**Files:**
- Modify: `resources/js/pages/Landing.vue`
- Test: covered by the backend `SampleEndpointTest`; this task adds the UI and verifies the production build/lint.

**Interfaces:**
- Consumes: Wayfinder `store` from `@/routes/sample` (generated once the route exists), the `Dialog` UI component, Inertia `useForm`.

- [ ] **Step 1: Generate Wayfinder helpers.** Ensure the `sample.store` route helper exists:

Run: `php artisan wayfinder:generate`
Expected: `resources/js/routes/sample/index.ts` now exports `store`.

- [ ] **Step 2: Add the modal to `Landing.vue`.** In `resources/js/pages/Landing.vue`:

Add to the `<script setup>` imports and state (after the existing imports):

```ts
import { ref } from 'vue';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { store as sampleStore } from '@/routes/sample';

const showSample = ref(false);
const sampleSent = ref<string | null>(null);
const sampleForm = useForm({ email: '' });

function openSample(): void {
    sampleForm.clearErrors();
    sampleForm.reset();
    sampleSent.value = null;
    showSample.value = true;
}

function submitSample(): void {
    sampleForm.submit(sampleStore(), {
        preserveScroll: true,
        onSuccess: () => {
            sampleSent.value = sampleForm.email;
            sampleForm.reset();
        },
    });
}
```

Add a "Send me a sample" trigger directly under the existing trip-setup form's submit button (after the closing `</form>` of the setup form, still inside the `max-w-[720px]` wrapper):

```vue
                <p class="text-center text-meta text-ink-secondary">
                    Not ready yet?
                    <button
                        type="button"
                        class="font-medium text-brand hover:text-brand-hover"
                        @click="openSample"
                    >
                        Send me a sample
                    </button>
                </p>
```

Add the dialog before the final closing `</div>` of the template root:

```vue
        <Dialog
            :open="showSample"
            @update:open="(open: boolean) => { if (!open) showSample = false; }"
        >
            <DialogContent>
                <template v-if="sampleSent === null">
                    <DialogHeader>
                        <DialogTitle>See a sample tripcast</DialogTitle>
                        <DialogDescription>
                            Enter your email and we’ll send a sample forecast straight to your
                            inbox.
                        </DialogDescription>
                    </DialogHeader>
                    <form class="space-y-4" @submit.prevent="submitSample">
                        <div class="space-y-2">
                            <Label for="sample-email">Email</Label>
                            <Input
                                id="sample-email"
                                v-model="sampleForm.email"
                                type="email"
                                name="email"
                                placeholder="you@example.com"
                                :aria-invalid="Boolean(sampleForm.errors.email)"
                            />
                            <InputError :message="sampleForm.errors.email" />
                        </div>
                        <DialogFooter class="gap-2">
                            <Button
                                type="button"
                                variant="ghost"
                                @click="showSample = false"
                            >
                                Cancel
                            </Button>
                            <Button type="submit" :disabled="sampleForm.processing">
                                {{ sampleForm.processing ? 'Sending…' : 'Send my sample' }}
                            </Button>
                        </DialogFooter>
                    </form>
                </template>
                <template v-else>
                    <DialogHeader>
                        <DialogTitle>Your sample is on its way.</DialogTitle>
                        <DialogDescription>
                            Check {{ sampleSent }} — the email has a link to create your own when
                            you’re ready.
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button type="button" @click="showSample = false">Done</Button>
                    </DialogFooter>
                </template>
            </DialogContent>
        </Dialog>
```

(`Input`, `Label`, `InputError`, `Button` are already imported in `Landing.vue`.)

- [ ] **Step 3: Verify the build and lint are clean.**

Run: `npm run build`
Expected: builds with no errors; a `Landing-*.js` chunk is emitted.

Run: `npm run lint`
Expected: no errors.

- [ ] **Step 4: Run the full backend suite (nothing regressed).**

Run: `php artisan test --compact`
Expected: PASS (all tests, including the new Sample suite).

- [ ] **Step 5: Pint + commit.**

```bash
vendor/bin/pint --dirty --format agent
git add resources/js/pages/Landing.vue resources/js/routes/sample
git commit -m "feat(sample): landing 'Send me a sample' modal"
```

---

## Self-Review

**Spec coverage:**
- Landing modal + email field → Task 7. ✓
- `POST /sample` validation + throttle (shared magic-link buckets) → Task 6 (trait, controller, form request, route). ✓
- Cached-live Reykjavik forecast + static fallback → Task 3. ✓
- Short window starting tomorrow → Task 6 `sampleTrip()` (demo trip window `today+1 .. today+2`, inside the live reach `today..today+2`; the fallback snapshot spans `today..today+3`, clipped to the window by `ForecastRows`). ✓
- "Get started" link = magic link via `issue()` → Task 4 + used in Task 6. ✓
- Consume → confirm + login + dashboard → unchanged existing behavior (no task needed; covered by existing magic-link consume tests). ✓
- `SampleDigestMail` + view reusing day rows, no unsubscribe/feedback/promo → Tasks 2 + 5. ✓
- `sample_requests` tracking (user_id, email, destination), one row per accepted request, repeat = multiple rows → Tasks 1 + 6 (tested). ✓

**Placeholder scan:** none — every code/test step shows full content.

**Type consistency:** `issue()` returns `{user, url, expires_at, ttl_minutes}` (Task 4), consumed as `$issued['user']`, `$issued['url']` (Task 6). `ForecastRows::project($snapshot, $departureDate, $returnDate, $celsius)` defined in Task 2, called identically in Tasks 2 (DigestMail) and 5 (SampleDigestMail). `SampleForecast::forecast(): Forecast` (Task 3) → `->toArray()` in Task 6. `SampleRequest` fillable `['user_id','email','destination']` (Task 1) matches `create([...])` in Task 6. `SendSampleRequest` (form request) is distinct from `SampleRequest` (model) — no name clash. ✓

**Note for the implementer:** Task 6 Step 2 deletes methods from `MagicLinkController`; rely on `tests/Feature/Auth` as the regression guard that the trait move preserved throttle behavior.
