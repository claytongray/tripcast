# Timezone-aware 7am Sends Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Send each daily digest at 7am in the timezone applicable to the traveler that day — their home zone before departure, the destination zone after — replacing the single fixed 09:00 America/New_York send.

**Architecture:** Two milestones. **Milestone 1** (independently deployable) moves the one global send hour from 9am to 7am ET via a single config knob and makes the dashboard state the exact send time. **Milestone 2** builds on that knob: it captures a home timezone (browser `Intl`) and a destination timezone (WeatherAPI `location.tz_id`), makes `CadencePredicate` resolve a phase-aware send zone, turns the scheduler hourly, and makes the dashboard show the applicable zone. The cadence predicate is the single timing authority; the send job's `unique(trip_id, send_date)` claim stays the dedup authority.

**Tech Stack:** Laravel 13, PHP 8.3, Pest 4, Inertia v3 + Vue 3, Tailwind v4, Carbon.

## Global Constraints

- **PHP:** curly braces on all control structures; explicit return types and param type hints; constructor property promotion; PHPDoc over inline comments.
- **Send hour source of truth:** `config('tripcast.send.default_hour')` (int, default 7). No literal send hour anywhere else.
- **Idempotency:** never weaken the `unique(trip_id, send_date)` claim in `email_logs`; one send per trip per local calendar day.
- **Cadence authority (AD-11):** all "is a trip due / when next" logic lives in `App\Digest\CadencePredicate`; no second implementation in commands, controllers, or the frontend.
- **Timezones are IANA strings** (e.g. `America/Los_Angeles`, `Europe/London`); validate with Laravel's `timezone` rule.
- **Copy voice:** calm, lowercase "tripcast", month-first dates. Reuse existing microcopy patterns; don't invent new error strings.
- **Tests:** Pest, `php artisan test --compact --filter=...`; pin the clock with `Carbon::setTestNow(...)` (the established pattern). Run `vendor/bin/pint --dirty --format agent` before every commit that touches PHP.
- **Frontend build:** after Vue changes, the user runs `npm run dev`/`npm run build`; note it, don't assume it's live.

---

# Milestone 1 — Global 7am ET send + specific dashboard copy (deployable)

Everyone still sends at one time; that time becomes 7am ET, sourced from a config knob, and the dashboard states it. No timezone capture yet.

## Task 1: Move the global send hour to 7am via a config knob

**Files:**
- Modify: `config/tripcast.php` (the `send` block, ~line 63)
- Modify: `app/Digest/CadencePredicate.php:30` (const → config-reading method) and `:131`
- Modify: `routes/console.php:19`
- Modify: `.env.example` (document `TRIPCAST_SEND_HOUR`)
- Test: `tests/Feature/Digest/CadencePredicateTest.php` (existing boundary tests + one new)

**Interfaces:**
- Produces: `config('tripcast.send.default_hour'): int` (default 7); `CadencePredicate` internally reads it via a private `sendHour(): int`. Public predicate signatures are unchanged in this milestone.

- [ ] **Step 1: Update the two boundary tests to the 7am cutoff and add a knob test**

In `tests/Feature/Digest/CadencePredicateTest.php`, the two "before the send" tests pin `now` to `08:00`, which is now *after* the 7am cutoff. Move them to `06:00` and correct the "9am" wording. Replace the bodies at the two spots:

```php
it('firstSendDate returns today when the window is open and now is before the 7am send', function () {
    Carbon::setTestNow(Carbon::parse('2026-06-29 06:00:00', 'America/New_York'));
    $trip = makeTrip(['departure_date' => '2026-07-02', 'return_date' => '2026-07-09']);

    // window opened 2026-06-25 (before today) and today's 07:00 send is still to come.
    expect(predicate()->firstSendDate($trip, nowEt())->toDateString())->toBe('2026-06-29');
});
```

```php
it('nextSendDate returns today when in window and now is before the 7am send', function () {
    Carbon::setTestNow(Carbon::parse('2026-06-29 06:00:00', 'America/New_York'));

    expect(predicate()->nextSendDate(makeTrip(), nowEt())->toDateString())->toBe('2026-06-29');
});
```

Add a new test asserting the cutoff is 7, not 9 (place it beside the others):

```php
it('firstSendDate treats 07:30 ET as past the send hour', function () {
    Carbon::setTestNow(Carbon::parse('2026-06-29 07:30:00', 'America/New_York'));
    $trip = makeTrip(['departure_date' => '2026-07-02', 'return_date' => '2026-07-09']);

    // 07:30 is past the 07:00 send, so the earliest the traveller receives is tomorrow.
    expect(predicate()->firstSendDate($trip, nowEt())->toDateString())->toBe('2026-06-30');
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact --filter=CadencePredicate`
Expected: FAIL — the two edited tests still see the old `SEND_HOUR = 9` (08:00 → today; 07:30 → today), and the new test fails.

- [ ] **Step 3: Add the config knob**

In `config/tripcast.php`, extend the existing `send` block (keep the existing keys):

```php
    'send' => [
        'stale_lease_minutes' => max(1, (int) env('SEND_STALE_LEASE_MINUTES', 30)),

        'max_delivery_attempts' => max(1, (int) env('SEND_MAX_DELIVERY_ATTEMPTS', 3)),

        // The daily send hour on the America/New_York clock (Milestone 2 makes the
        // *zone* per-trip; the hour stays this one knob). 0–23, floored/capped.
        'default_hour' => min(23, max(0, (int) env('TRIPCAST_SEND_HOUR', 7))),
    ],
```

- [ ] **Step 4: Make the predicate read the knob**

In `app/Digest/CadencePredicate.php`, delete the `SEND_HOUR` constant (lines ~24–30) and replace the usage at line 131. Add a private accessor near the bottom of the class:

Replace `private const SEND_HOUR = 9;` and its docblock with nothing, and change the cutoff line inside `earliestSendFrom`:

```php
        if ($now->hour >= $this->sendHour()) {
            $start = $start->addDay();
        }
```

Add this method (next to `horizonDays()`):

```php
    /**
     * The daily send hour (`tripcast.send.default_hour`, default 7) on the
     * America/New_York clock — the single authority the scheduler and this
     * predicate share. Milestone 2 keeps the hour here and resolves the *zone*
     * per-trip.
     */
    private function sendHour(): int
    {
        return (int) config('tripcast.send.default_hour');
    }
```

Also update the class docblock and the `earliestSendFrom`/`nextSendDate` doc comments that say `09:00` to `07:00`.

- [ ] **Step 5: Point the scheduler at the knob**

In `routes/console.php`, change the digest schedule (and its comment):

```php
// The daily digest run at the configured America/New_York send hour (AD-2, AD-7).
Schedule::command('digests:send')
    ->dailyAt(sprintf('%02d:00', (int) config('tripcast.send.default_hour')))
    ->timezone('America/New_York')
    ->name('send-daily-digests');
```

- [ ] **Step 6: Document the env var**

In `.env.example`, near the other `TRIPCAST_*` keys, add:

```
# Daily digest send hour on the America/New_York clock (0–23; default 7 = 7am ET).
# TRIPCAST_SEND_HOUR=7
```

- [ ] **Step 7: Run the full digest + cadence suite**

Run: `php artisan test --compact --filter=Digest`
Expected: PASS (CadencePredicate, SendDailyDigests, Purge, SendDigestCommand all green).

- [ ] **Step 8: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add config/tripcast.php app/Digest/CadencePredicate.php routes/console.php .env.example tests/Feature/Digest/CadencePredicateTest.php
git commit -m "feat(send): move global digest send hour to 7am ET via config knob"
```

## Task 2: Dashboard states the specific send time

**Files:**
- Modify: `resources/js/pages/Dashboard.vue:122-140` (the `nextSendLine` helper) and the beacon comment at `:448`
- Test: `tests/Feature/Dashboard/*` if a Vue/inertia assertion exists for the line; otherwise a controller test asserting the props already covers data (no server change here).

**Interfaces:**
- Consumes: existing `TripCard` fields `is_sending: boolean`, `days_until_send: number | null`, `next_send_date: string | null` (unchanged this milestone).
- Produces: user-visible copy "Sending today at 7am ET" / "Sending tomorrow at 7am ET" / "First forecast in N days · Mon D · 7am ET".

- [ ] **Step 1: Update the `nextSendLine` helper copy**

In `resources/js/pages/Dashboard.vue`, replace the `nextSendLine` function (lines ~122–141) with:

```ts
// The next-send line beneath the dates. In its send window → the exact clock the
// email goes out ("Sending today/tomorrow at 7am ET"); still before the window →
// an upfront count, date, and the same send time. Milestone 2 swaps "7am ET" for
// the trip's applicable zone. Paused/ended trips carry their own note.
function nextSendLine(trip: TripCard): string | null {
    if (trip.is_sending) {
        const when = trip.days_until_send === 0 ? 'today' : 'tomorrow';

        return `Sending ${when} at 7am ET`;
    }

    if (trip.next_send_date !== null && trip.days_until_send !== null) {
        const noun = trip.days_until_send === 1 ? 'day' : 'days';

        return `First forecast in ${trip.days_until_send} ${noun} · ${formatDay(trip.next_send_date)} · 7am ET`;
    }

    return null;
}
```

- [ ] **Step 2: Fix the stale beacon comment**

In the same file, change the comment at line ~448 from `<!-- Beacon: this trip is sending at the next 9am (Spec B) -->` to:

```html
                            <!-- Beacon: this trip is sending at the next 7am ET (Spec B) -->
```

- [ ] **Step 3: Verify in the browser**

Ask the user to run `npm run dev` (or `composer run dev`) and load the dashboard. Expected: an in-window trip shows "Sending today at 7am ET" or "Sending tomorrow at 7am ET"; a far-off trip shows "First forecast in N days · Mon D · 7am ET".

- [ ] **Step 4: Commit**

```bash
git add resources/js/pages/Dashboard.vue
git commit -m "feat(dashboard): state the exact 7am ET send time on trip cards"
```

**Milestone 1 is now deployable.** Merge/deploy before starting Milestone 2 if you want the quick win live.

---

# Milestone 2 — Phase-aware per-trip send zone

Capture both zones, make the predicate resolve the applicable zone per send date, turn the scheduler hourly, and show the zone on the dashboard.

## Task 3: Add the `destination_timezone` column

> **SUPERSEDED by Story 11.2** (`_bmad-output/implementation-artifacts/11-2-destination-timezone-capture-at-trip-creation.md`, done 2026-07-04). The `trips.destination_timezone` column already exists — created in `database/migrations/2026_07_04_000001_add_destination_timezone_to_trips_table.php` with the identical shape (nullable `string` after `longitude`; `Trip::$destination_timezone` fillable + `@property`). **Skip this task.**

**Files:**
- Create: `database/migrations/2026_07_03_000001_add_destination_timezone_to_trips_table.php`
- Modify: `app/Models/Trip.php` (fillable + `@property`)
- Test: `tests/Feature/Trip/DestinationTimezoneColumnTest.php` (create)

**Interfaces:**
- Produces: `Trip::$destination_timezone` (`?string`, nullable, mass-assignable).

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Trip/DestinationTimezoneColumnTest.php`:

```php
<?php

use App\Models\Trip;

it('persists a nullable destination_timezone', function () {
    $trip = Trip::factory()->create(['destination_timezone' => 'Europe/London']);

    expect($trip->fresh()->destination_timezone)->toBe('Europe/London');
});

it('defaults destination_timezone to null', function () {
    expect(Trip::factory()->create()->destination_timezone)->toBeNull();
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php artisan test --compact --filter=DestinationTimezoneColumn`
Expected: FAIL — column does not exist.

- [ ] **Step 3: Create the migration**

Create `database/migrations/2026_07_03_000001_add_destination_timezone_to_trips_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The IANA zone of the trip's destination (e.g. Europe/London), captured from
     * the weather provider's `location.tz_id`. Nullable: existing trips and any
     * fetch lacking a zone fall back to the owner's home zone (AD-7 successor).
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

In `app/Models/Trip.php`: add `'destination_timezone',` to `$fillable` (after `'longitude'`), and add `@property string|null $destination_timezone` to the class docblock (after the `$longitude` line).

- [ ] **Step 5: Migrate and run the test**

Run: `php artisan migrate && php artisan test --compact --filter=DestinationTimezoneColumn`
Expected: PASS.

- [ ] **Step 6: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add database/migrations app/Models/Trip.php tests/Feature/Trip/DestinationTimezoneColumnTest.php
git commit -m "feat(trip): add nullable destination_timezone column"
```

## Task 4: Capture the destination zone from WeatherAPI

> **SUPERSEDED by Story 11.2** (done 2026-07-04). The destination zone is now captured at **trip creation** via the Google Time Zone API (`App\Services\Weather\DestinationTimezone` resolved in `CreateTrip`, before the DB transaction), **not** from WeatherAPI `location.tz_id` — WeatherKit returns none. `Forecast::$timezone` is not needed. `SendTripDigest` already passes `Trip::$destination_timezone` into `fetchForecast`. **Skip this task**; the phase-aware `CadencePredicate` resolution (Task 7) consumes `Trip::$destination_timezone` unchanged.

**Files:**
- Modify: `app/Services/Weather/Forecast.php` (constructor + toArray/fromArray)
- Modify: `app/Services/Weather/WeatherApiProvider.php:40-71` (read `location.tz_id`)
- Modify: `app/Services/Weather/FakeWeatherProvider.php:12-39` (return a known zone)
- Modify: `app/Jobs/SendTripDigest.php:47-71` (persist the zone once)
- Test: `tests/Feature/Digest/SendTripDigestTest.php` (add a case), `tests/Unit/Weather/ForecastTest.php` (create)

**Interfaces:**
- Consumes: `Trip::$destination_timezone` from Task 3.
- Produces: `Forecast::$timezone` (`?string`); `new Forecast(array $days, ?string $timezone = null)`; `SendTripDigest` sets `trip.destination_timezone` from the fetched forecast when it is currently null.

- [ ] **Step 1: Write the failing Forecast unit test**

Create `tests/Unit/Weather/ForecastTest.php`:

```php
<?php

use App\Services\Weather\Forecast;
use App\Services\Weather\ForecastDay;

it('round-trips the timezone through toArray/fromArray', function () {
    $forecast = new Forecast([new ForecastDay('2026-08-01', 'Sunny', 0, 20.0, 68.0, 12.0, 53.6, 55, 22.0, 71.6)], 'Europe/London');

    expect($forecast->timezone)->toBe('Europe/London')
        ->and($forecast->toArray()['timezone'])->toBe('Europe/London')
        ->and(Forecast::fromArray($forecast->toArray())->timezone)->toBe('Europe/London');
});

it('defaults timezone to null when absent', function () {
    expect((new Forecast([]))->timezone)->toBeNull()
        ->and(Forecast::fromArray(['days' => []])->timezone)->toBeNull();
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php artisan test --compact --filter=ForecastTest`
Expected: FAIL — `Forecast` has no `$timezone`.

- [ ] **Step 3: Extend the Forecast value object**

In `app/Services/Weather/Forecast.php`, change the constructor and the two array methods:

```php
    /**
     * @param  list<ForecastDay>  $days
     */
    public function __construct(public array $days, public ?string $timezone = null) {}
```

```php
    /**
     * @param  array{days: list<array<string, mixed>>, timezone?: string|null}  $snapshot
     */
    public static function fromArray(array $snapshot): self
    {
        return new self(
            array_map(
                fn (array $day): ForecastDay => ForecastDay::fromArray($day),
                $snapshot['days'],
            ),
            $snapshot['timezone'] ?? null,
        );
    }
```

```php
    /**
     * @return array{days: list<array<string, mixed>>, limited: bool, timezone: string|null}
     */
    public function toArray(): array
    {
        return [
            'days' => array_map(fn (ForecastDay $day): array => $day->toArray(), $this->days),
            'limited' => $this->isLimited(),
            'timezone' => $this->timezone,
        ];
    }
```

- [ ] **Step 4: Run the Forecast test (green) and confirm no snapshot breakage**

Run: `php artisan test --compact --filter=ForecastTest`
Expected: PASS. Then `php artisan test --compact --filter=Digest` — still PASS (the extra `timezone` key is additive; `fromArray` tolerates its absence).

- [ ] **Step 5: Read `location.tz_id` in the real provider**

In `app/Services/Weather/WeatherApiProvider.php`, after `$data = $response->json();` (line 40) capture the zone, and pass it to the constructor at line 71:

```php
        $data = $response->json();
        $forecastDays = $data['forecast']['forecastday'] ?? null;
        $timezone = isset($data['location']['tz_id']) ? (string) $data['location']['tz_id'] : null;
```

```php
        return new Forecast($days, $timezone);
```

- [ ] **Step 6: Give the fake provider a known zone**

In `app/Services/Weather/FakeWeatherProvider.php`, pass a zone on both returns so tests can assert capture. Change line 15's `return new Forecast([` block to end with `], 'Europe/London');` and line 39 to `return new Forecast($days, 'Europe/London');`.

- [ ] **Step 7: Write the failing persist test**

In `tests/Feature/Digest/SendTripDigestTest.php`, add:

```php
it('captures the destination timezone from the forecast when the trip has none', function () {
    $trip = Trip::factory()->create(['destination_timezone' => null]);

    (new SendTripDigest($trip, '2026-07-01'))->handle(app(WeatherProvider::class));

    expect($trip->fresh()->destination_timezone)->toBe('Europe/London');
});

it('does not overwrite an existing destination timezone', function () {
    $trip = Trip::factory()->create(['destination_timezone' => 'Asia/Tokyo']);

    (new SendTripDigest($trip, '2026-07-01'))->handle(app(WeatherProvider::class));

    expect($trip->fresh()->destination_timezone)->toBe('Asia/Tokyo');
});
```

(Match the file's existing imports/setup — it already binds a `WeatherProvider`; ensure it resolves to `FakeWeatherProvider`, per the file's existing pattern.)

- [ ] **Step 8: Run it to verify it fails**

Run: `php artisan test --compact --filter=SendTripDigest`
Expected: FAIL — timezone stays null.

- [ ] **Step 9: Persist the zone in the send job**

In `app/Jobs/SendTripDigest.php`, right after the successful fetch (after line 66, before the snapshot persist), add:

```php
        // Capture the destination zone once, from the first successful fetch (AD-11
        // successor). The first send is always in the home phase (window opens
        // departure − horizon, and departure ≥ today at creation), so the zone is
        // stored well before the destination phase ever needs it. Never overwrite.
        if ($this->trip->destination_timezone === null && $forecast->timezone !== null) {
            $this->trip->forceFill(['destination_timezone' => $forecast->timezone])->save();
        }
```

- [ ] **Step 10: Run the send-job tests + full digest suite**

Run: `php artisan test --compact --filter=Digest`
Expected: PASS.

- [ ] **Step 11: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Services/Weather tests/Unit/Weather app/Jobs/SendTripDigest.php tests/Feature/Digest/SendTripDigestTest.php
git commit -m "feat(weather): capture destination tz_id and persist it on the trip"
```

## Task 5: Capture the home zone at trip creation

**Files:**
- Modify: `resources/js/pages/Landing.vue:35-65` (form + submit)
- Modify: `resources/js/pages/Dashboard.vue:206-240` (add-trip form + submit)
- Modify: `app/Http/Requests/TripSetupRequest.php` (rule + tripDetails)
- Modify: `app/Http/Requests/AddTripRequest.php` (rule + tripDetails)
- Modify: `app/Actions/CreateTrip.php:29-39` (thread timezone into user create-attrs; update the array-shape PHPDoc)
- Test: `tests/Feature/Landing/TripSetupTest.php` and/or `tests/Feature/Trip/*` (assert user.timezone written)

**Interfaces:**
- Consumes: `User::$timezone` (already fillable).
- Produces: `CreateTrip::handle(string $email, array $tripDetails)` reads `$tripDetails['timezone']` (optional) and writes it to the user's create-attributes.

- [ ] **Step 1: Write the failing feature test**

In `tests/Feature/Landing/TripSetupTest.php` (or a focused new file `tests/Feature/Trip/CaptureHomeTimezoneTest.php`), add:

```php
it('stores the submitted home timezone on a new user', function () {
    $this->post('/', [
        'destination' => 'Reykjavik, Iceland',
        'departure_date' => now('America/New_York')->addDays(10)->toDateString(),
        'return_date' => now('America/New_York')->addDays(17)->toDateString(),
        'temperature_unit' => 'fahrenheit',
        'timezone' => 'America/Los_Angeles',
    ]);

    expect(User::first()->timezone)->toBe('America/Los_Angeles');
});

it('falls back to America/New_York when timezone is absent or invalid', function () {
    $this->post('/', [
        'destination' => 'Reykjavik, Iceland',
        'departure_date' => now('America/New_York')->addDays(10)->toDateString(),
        'return_date' => now('America/New_York')->addDays(17)->toDateString(),
        'temperature_unit' => 'fahrenheit',
        'timezone' => 'Not/AZone',
    ]);

    expect(User::first()->timezone)->toBe('America/New_York');
});
```

(This flow may require a magic-link/geocoding stub — mirror the existing passing tests in `TripSetupTest.php` for how they post and how geocoding is faked. Reuse their setup verbatim.)

- [ ] **Step 2: Run it to verify it fails**

Run: `php artisan test --compact --filter=CaptureHomeTimezone` (or the file you added to)
Expected: FAIL — timezone stays at the default.

- [ ] **Step 3: Validate the zone in both requests**

In `app/Http/Requests/TripSetupRequest.php` and `app/Http/Requests/AddTripRequest.php`, add to each `rules()` array:

```php
            'timezone' => ['nullable', 'timezone:all'],
```

In each request's `tripDetails()` method, add the field to the returned array and the `@return` shape:

```php
            'timezone' => $this->filled('timezone') ? (string) $this->string('timezone') : null,
```

(For `TripSetupRequest`, its shape already includes `temperature_unit`; add `timezone` alongside. For `AddTripRequest`, add `timezone` to the shape.)

- [ ] **Step 4: Thread it through CreateTrip**

In `app/Actions/CreateTrip.php`, update the `@param` array-shape to include `timezone?: string|null`, and extend the user create-attributes:

```php
            $user = User::firstOrCreate(['email' => $email], [
                'temperature_unit' => $tripDetails['temperature_unit'] ?? User::UNIT_FAHRENHEIT,
                'timezone' => $this->normalizeTimezone($tripDetails['timezone'] ?? null),
            ]);
```

Add a private helper to the class:

```php
    /**
     * A valid IANA zone or the America/New_York fallback (the column default). The
     * request already validates format; this guards direct/action callers too.
     */
    private function normalizeTimezone(?string $timezone): string
    {
        return $timezone !== null && in_array($timezone, timezone_identifiers_list(), true)
            ? $timezone
            : 'America/New_York';
    }
```

- [ ] **Step 5: Send the zone from both forms**

In `resources/js/pages/Landing.vue`, add `timezone: ''` to the `useForm({...})` object (line ~35), and in the submit handler (line ~65) set it before submitting:

```ts
    form.timezone = Intl.DateTimeFormat().resolvedOptions().timeZone;
    form.submit(store());
```

Do the same in `resources/js/pages/Dashboard.vue`: add `timezone: ''` to the add-trip `useForm({...})` (line ~206) and set `form.timezone = Intl.DateTimeFormat().resolvedOptions().timeZone;` immediately before `form.submit(store())` (line ~240).

- [ ] **Step 6: Run the tests**

Run: `php artisan test --compact --filter=CaptureHomeTimezone` then `php artisan test --compact --filter=Trip`
Expected: PASS.

- [ ] **Step 7: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Requests/TripSetupRequest.php app/Http/Requests/AddTripRequest.php app/Actions/CreateTrip.php resources/js/pages/Landing.vue resources/js/pages/Dashboard.vue tests/Feature
git commit -m "feat(trip): capture the traveler's home timezone at trip creation"
```

## Task 6: Let users edit their home timezone in settings

**Files:**
- Modify: `app/Http/Controllers/SettingsController.php:22-42` (pass + persist `timezone`)
- Modify: `app/Http/Requests/UpdateSettingsRequest.php:24-32` (rule)
- Modify: `resources/js/pages/Settings.vue` (props + a zone select)
- Test: `tests/Feature/Settings/*` (assert update persists timezone)

**Interfaces:**
- Consumes: `User::$timezone`.
- Produces: `PATCH /settings` accepts `timezone`; Settings page exposes a home-timezone control.

- [ ] **Step 1: Write the failing test**

In the existing settings feature test file (mirror its style), add:

```php
it('updates the home timezone', function () {
    $user = User::factory()->create(['timezone' => 'America/New_York']);

    $this->actingAs($user)->patch('/settings', [
        'temperature_unit' => 'fahrenheit',
        'timezone' => 'America/Los_Angeles',
    ])->assertRedirect();

    expect($user->fresh()->timezone)->toBe('America/Los_Angeles');
});

it('rejects an invalid timezone', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->patch('/settings', [
        'temperature_unit' => 'fahrenheit',
        'timezone' => 'Not/AZone',
    ])->assertSessionHasErrors('timezone');
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php artisan test --compact --filter=Settings`
Expected: FAIL — `timezone` not accepted/persisted.

- [ ] **Step 3: Add the validation rule**

In `app/Http/Requests/UpdateSettingsRequest.php`, add to `rules()`:

```php
            'timezone' => ['required', 'timezone:all'],
```

- [ ] **Step 4: Pass + persist it in the controller**

In `app/Http/Controllers/SettingsController.php`, add `timezone` to the `edit()` Inertia props (`'timezone' => $request->user()->timezone`) and include it in the `update()` fill (mirror how `temperature_unit` is applied — the validated array now carries `timezone`).

- [ ] **Step 5: Add the control to the page**

In `resources/js/pages/Settings.vue`, add `timezone: string` to the `defineProps<{...}>()` and render a labeled `<select>` populated from the browser's zone list, auto-saving like the existing temperature toggle:

```ts
const zones =
    typeof Intl.supportedValuesOf === 'function'
        ? Intl.supportedValuesOf('timeZone')
        : [props.timezone];
```

```html
<label class="block text-meta text-ink-secondary" for="timezone">Home timezone</label>
<select id="timezone" v-model="form.timezone" @change="form.patch(update().url)">
    <option v-for="z in zones" :key="z" :value="z">{{ z }}</option>
</select>
```

(Match the file's existing `useForm`/auto-save pattern and design tokens; the snippet shows intent, not final styling.)

- [ ] **Step 6: Run the tests**

Run: `php artisan test --compact --filter=Settings`
Expected: PASS.

- [ ] **Step 7: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Controllers/SettingsController.php app/Http/Requests/UpdateSettingsRequest.php resources/js/pages/Settings.vue tests/Feature/Settings
git commit -m "feat(settings): edit home timezone"
```

## Task 7: Phase-aware zone resolution + instant-based due logic (the core)

**Files:**
- Modify: `app/Digest/CadencePredicate.php` (add zone resolution + `dueSendDate` + `candidatesNear`; make `earliestSendFrom` zone-aware)
- Test: `tests/Feature/Digest/CadencePredicateTest.php` (new zone + due-at cases)

**Interfaces:**
- Consumes: `Trip::$destination_timezone`, `User::$timezone`, `config('tripcast.send.default_hour')`.
- Produces:
  - `timezoneForSendDate(Trip $trip, string $sendDate): string` — home zone when `$sendDate <= departure_date`, else `destination_timezone ?? user.timezone`.
  - `dueSendDate(Trip $trip, CarbonInterface $now): ?string` — the local send-date string if the trip is due to send at instant `$now` (eligible, `now`-in-applicable-zone ≥ send hour, within `[departure − horizon, return]` on that local date), else `null`.
  - `candidatesNear(CarbonInterface $now): Collection<int, Trip>` — the SQL prefilter (eligible + window plausibly open within ±1 day of `$now`), eager-loading `user`.

- [ ] **Step 1: Write the failing zone/due tests**

Add to `tests/Feature/Digest/CadencePredicateTest.php` (these need a helper that builds a trip with an explicit `destination_timezone` and a user `timezone`; reuse/extend the file's `makeTrip`):

```php
it('resolves the home zone on or before departure and the destination zone after', function () {
    $trip = makeTrip([
        'departure_date' => '2026-08-01',
        'return_date' => '2026-08-10',
        'destination_timezone' => 'Europe/London',
    ]);
    $trip->user->update(['timezone' => 'America/Los_Angeles']);

    expect(predicate()->timezoneForSendDate($trip, '2026-07-30'))->toBe('America/Los_Angeles')
        ->and(predicate()->timezoneForSendDate($trip, '2026-08-01'))->toBe('America/Los_Angeles')
        ->and(predicate()->timezoneForSendDate($trip, '2026-08-02'))->toBe('Europe/London');
});

it('is due at 7am in the home zone pre-trip', function () {
    $trip = makeTrip([
        'departure_date' => '2026-08-01',
        'return_date' => '2026-08-10',
        'destination_timezone' => 'Europe/London',
    ]);
    $trip->user->update(['timezone' => 'America/Los_Angeles']);

    // 2026-07-28 07:00 America/Los_Angeles == 14:00Z. In window (departure − 7 = 07-25).
    $now = Carbon::parse('2026-07-28 14:00:00', 'UTC');
    expect(predicate()->dueSendDate($trip, $now))->toBe('2026-07-28');

    // 06:59 local → not yet.
    expect(predicate()->dueSendDate($trip, Carbon::parse('2026-07-28 13:59:00', 'UTC')))->toBeNull();
});

it('is due at 7am in the destination zone during the trip', function () {
    $trip = makeTrip([
        'departure_date' => '2026-08-01',
        'return_date' => '2026-08-10',
        'destination_timezone' => 'Europe/London',
    ]);
    $trip->user->update(['timezone' => 'America/Los_Angeles']);

    // 2026-08-05 07:00 Europe/London == 06:00Z.
    $now = Carbon::parse('2026-08-05 06:00:00', 'UTC');
    expect(predicate()->dueSendDate($trip, $now))->toBe('2026-08-05');
});

it('falls back to the home zone when destination_timezone is null', function () {
    $trip = makeTrip(['departure_date' => '2026-08-01', 'return_date' => '2026-08-10', 'destination_timezone' => null]);
    $trip->user->update(['timezone' => 'America/Los_Angeles']);

    expect(predicate()->timezoneForSendDate($trip, '2026-08-05'))->toBe('America/Los_Angeles');
});
```

- [ ] **Step 2: Run to verify failure**

Run: `php artisan test --compact --filter=CadencePredicate`
Expected: FAIL — the new methods don't exist.

- [ ] **Step 3: Implement zone resolution + due logic**

In `app/Digest/CadencePredicate.php`, add these public methods (keep the existing `isDue`/`dueOn`/`dueCountOn` for now; Task 8 rewires callers):

```php
    /**
     * The zone that governs a given send date for a trip: the traveler's home zone
     * through departure day, the destination zone after (the phase switch is
     * `departure_date`). Falls back to the home zone when the destination zone is
     * not yet captured.
     */
    public function timezoneForSendDate(Trip $trip, string $sendDate): string
    {
        if ($sendDate <= $trip->departure_date->toDateString()) {
            return $trip->user->timezone;
        }

        return $trip->destination_timezone ?? $trip->user->timezone;
    }

    /**
     * The local send-date string if this trip is due to send at instant $now, else
     * null. Due ⟺ eligible (active, not deleted, owner confirmed & not opted out)
     * AND `$now` in the applicable zone is at/after the send hour AND that local
     * date is within [departure − horizon, return]. The phase (which zone) is keyed
     * off the home-zone calendar date, so a trip switches to destination time once
     * home has passed departure; the unique (trip, send_date) claim guarantees no
     * double-send or skipped day across the one transition.
     */
    public function dueSendDate(Trip $trip, CarbonInterface $now): ?string
    {
        if ($trip->status !== Trip::STATUS_ACTIVE || $trip->deleted_at !== null) {
            return null;
        }

        $user = $trip->user;

        if ($user->email_verified_at === null || $user->email_opted_out) {
            return null;
        }

        $homeToday = CarbonImmutable::instance($now)->setTimezone($user->timezone)->toDateString();
        $zone = $this->timezoneForSendDate($trip, $homeToday);
        $local = CarbonImmutable::instance($now)->setTimezone($zone);

        if ($local->hour < $this->sendHour()) {
            return null;
        }

        $sendDate = $local->toDateString();
        $windowOpen = CarbonImmutable::parse($trip->departure_date->toDateString())
            ->subDays($this->horizonDays())
            ->toDateString();
        $windowClose = $trip->return_date->toDateString();

        if ($sendDate < $windowOpen || $sendDate > $windowClose) {
            return null;
        }

        return $sendDate;
    }

    /**
     * The eligible trips whose send window could plausibly be open within ±1 day of
     * $now — the SQL prefilter the hourly selector refines in PHP (a per-row zone
     * cannot be converted in SQL). The ±1-day slack covers any timezone offset.
     *
     * @return Collection<int, Trip>
     */
    public function candidatesNear(CarbonInterface $now): Collection
    {
        $lo = CarbonImmutable::instance($now)->subDay()->toDateString();
        $hi = CarbonImmutable::instance($now)->addDay()->toDateString();
        $windowOpenBy = CarbonImmutable::parse($hi)->addDays($this->horizonDays())->toDateString();

        return Trip::query()
            ->where('status', Trip::STATUS_ACTIVE)
            ->whereDate('departure_date', '<=', $windowOpenBy)
            ->whereDate('return_date', '>=', $lo)
            ->whereHas('user', function ($query): void {
                $query->whereNotNull('email_verified_at')->where('email_opted_out', false);
            })
            ->with('user')
            ->get();
    }
```

- [ ] **Step 4: Make the display cutoff zone-aware**

In `earliestSendFrom`, replace the fixed-hour comparison so the cutoff uses the applicable zone for "now". Change the method body to:

```php
    private function earliestSendFrom(Trip $trip, CarbonInterface $now): CarbonImmutable
    {
        $zone = $this->timezoneForSendDate($trip, CarbonImmutable::instance($now)->toDateString());
        $local = CarbonImmutable::instance($now)->setTimezone($zone);
        $start = CarbonImmutable::parse($local->toDateString());

        if ($local->hour >= $this->sendHour()) {
            $start = $start->addDay();
        }

        $windowOpen = CarbonImmutable::parse($trip->departure_date->toDateString())
            ->subDays($this->horizonDays());

        return $start->greaterThan($windowOpen) ? $start : $windowOpen;
    }
```

Note: the existing `firstSendDate`/`nextSendDate` tests pass `nowEt()` (an ET instant); with the default trip's home zone = `America/New_York`, their expectations are unchanged.

- [ ] **Step 5: Run the cadence suite**

Run: `php artisan test --compact --filter=CadencePredicate`
Expected: PASS (new zone/due tests green; existing ET-based display tests still green).

- [ ] **Step 6: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Digest/CadencePredicate.php tests/Feature/Digest/CadencePredicateTest.php
git commit -m "feat(cadence): phase-aware send-zone resolution and instant-based due logic"
```

## Task 8: Hourly scheduler, selector-driven command, `--now`, and purge split

**Files:**
- Modify: `app/Console/Commands/SendDailyDigests.php` (use `candidatesNear` + `dueSendDate`; drop the purge block; add `--now`)
- Modify: `routes/console.php` (digest → `->hourly()`; add a daily `forecast:purge` schedule)
- Create: `app/Console/Commands/PurgeForecastHistoryCommand.php` (thin wrapper over the existing `PurgeForecastHistory` action)
- Test: `tests/Feature/Digest/SendDailyDigestsTest.php` (rewrite for hourly/zone), `tests/Feature/Digest/PurgeForecastHistoryTest.php` (unchanged action; add command test)

**Interfaces:**
- Consumes: `CadencePredicate::candidatesNear`, `CadencePredicate::dueSendDate`.
- Produces: `digests:send [--now=]` dispatches one `SendTripDigest($trip, $localSendDate)` per due trip at the given/real instant; `forecast:purge` runs the retention sweep; `digests:send --now=` is refused in production.

- [ ] **Step 1: Write the failing hourly/zone command tests**

Rewrite the relevant assertions in `tests/Feature/Digest/SendDailyDigestsTest.php` to freeze an instant and assert dispatch by zone. Add:

```php
it('dispatches a due trip with its home-zone local send date pre-trip', function () {
    Bus::fake();
    Carbon::setTestNow(Carbon::parse('2026-07-28 14:00:00', 'UTC')); // 07:00 LA
    $user = User::factory()->create(['email_verified_at' => now(), 'timezone' => 'America/Los_Angeles']);
    $trip = Trip::factory()->for($user)->create([
        'departure_date' => '2026-08-01', 'return_date' => '2026-08-10', 'destination_timezone' => 'Europe/London',
    ]);

    $this->artisan('digests:send')->assertExitCode(0);

    Bus::assertDispatched(SendTripDigest::class, fn ($job) => $job->trip->is($trip) && $job->sendDate === '2026-07-28');
});

it('does not dispatch before 7am local', function () {
    Bus::fake();
    Carbon::setTestNow(Carbon::parse('2026-07-28 13:00:00', 'UTC')); // 06:00 LA
    $user = User::factory()->create(['email_verified_at' => now(), 'timezone' => 'America/Los_Angeles']);
    Trip::factory()->for($user)->create(['departure_date' => '2026-08-01', 'return_date' => '2026-08-10', 'destination_timezone' => 'Europe/London']);

    $this->artisan('digests:send')->assertExitCode(0);

    Bus::assertNotDispatched(SendTripDigest::class);
});

it('refuses --now in production', function () {
    app()->detectEnvironment(fn () => 'production');
    $this->artisan('digests:send', ['--now' => '2026-07-28 14:00:00 UTC'])->assertExitCode(1);
});
```

(Keep/adapt the file's existing liveness-snapshot assertions; the snapshot still records per-run `due`/`dispatched`.)

- [ ] **Step 2: Run to verify failure**

Run: `php artisan test --compact --filter=SendDailyDigests`
Expected: FAIL — command still uses `dueOn($today)` and has no `--now`.

- [ ] **Step 3: Rewrite the command to the hourly selector + `--now`**

In `app/Console/Commands/SendDailyDigests.php`: change the signature to accept the option, drop the `PurgeForecastHistory` dependency/block, and select via the predicate. Replace the `#[Signature]` and `handle` selection:

```php
#[Signature('digests:send {--now= : ISO datetime (with zone) to evaluate as; non-production only, for testing}')]
```

```php
    public function handle(CadencePredicate $cadence): int
    {
        if (! $this->applyNowOverride()) {
            return self::FAILURE;
        }

        $now = now();
        $startedAt = now();
        $dueCount = 0;
        $dispatched = 0;

        try {
            foreach ($cadence->candidatesNear($now) as $trip) {
                $sendDate = $cadence->dueSendDate($trip, $now);

                if ($sendDate === null) {
                    continue;
                }

                $dueCount++;
                SendTripDigest::dispatch($trip, $sendDate);
                $dispatched++;
            }
        } catch (Throwable $e) {
            $this->recordRun($dueCount, $dispatched, false, $startedAt, $e->getMessage());
            $this->emitHeartbeat(false);
            $this->error("Digest run failed: {$e->getMessage()}");

            return self::FAILURE;
        }

        $healthy = ! ($dueCount > 0 && $dispatched === 0);
        $this->recordRun($dueCount, $dispatched, $healthy, $startedAt);
        $this->emitHeartbeat($healthy);
        $this->info("Dispatched {$dispatched} digest job(s) at {$now->toIso8601String()}.");

        return $healthy ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Apply a `--now` test clock, refused in production. Returns false (fail the
     * run) on an unparseable value or a production attempt.
     */
    private function applyNowOverride(): bool
    {
        $raw = $this->option('now');

        if ($raw === null) {
            return true;
        }

        if (app()->environment('production')) {
            $this->error('--now is not allowed in production.');

            return false;
        }

        try {
            \Illuminate\Support\Carbon::setTestNow(\Illuminate\Support\Carbon::parse((string) $raw));
        } catch (Throwable $e) {
            $this->error("Unparseable --now value: {$e->getMessage()}");

            return false;
        }

        return true;
    }
```

Remove the now-unused `PurgeForecastHistory` import and the purge `try/catch` block from `handle`. Update the class docblock (it no longer runs the purge sweep).

- [ ] **Step 4: Create the purge command**

Create `app/Console/Commands/PurgeForecastHistoryCommand.php`:

```php
<?php

namespace App\Console\Commands;

use App\Actions\PurgeForecastHistory;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

#[Signature('forecast:purge')]
#[Description('Retention sweep (AD-16): null weather_snapshot past the retention horizon.')]
class PurgeForecastHistoryCommand extends Command
{
    public function handle(PurgeForecastHistory $purge): int
    {
        $purged = $purge->handle(now('America/New_York'));
        Log::info('digests:purge', ['purged' => $purged]);
        $this->info("Purged {$purged} snapshot(s).");

        return self::SUCCESS;
    }
}
```

- [ ] **Step 5: Reschedule in `routes/console.php`**

Change the digest command to hourly and add the purge schedule:

```php
// Timezone-aware send: run every hour; the predicate decides who has hit 7am in
// their applicable zone (AD-2, AD-11). Zone logic is per-row, so the schedule
// itself is zone-agnostic.
Schedule::command('digests:send')
    ->hourly()
    ->name('send-daily-digests');

// Forecast-history retention sweep (AD-16), split out of the hourly send so it
// runs once a day rather than 24×.
Schedule::command('forecast:purge')
    ->dailyAt('03:00')
    ->timezone('America/New_York')
    ->name('purge-forecast-history');
```

- [ ] **Step 6: Add a purge command test**

Create `tests/Feature/Digest/PurgeForecastHistoryCommandTest.php`:

```php
<?php

use App\Models\EmailLog;
use App\Models\Trip;
use Illuminate\Support\Carbon;

it('nulls snapshots past the retention horizon', function () {
    Carbon::setTestNow(Carbon::parse('2026-06-30 03:00', 'America/New_York'));
    $trip = Trip::factory()->create(['return_date' => '2026-04-01']);
    $log = EmailLog::factory()->for($trip)->create([
        'send_date' => '2026-04-01', 'status' => 'sent', 'weather_snapshot' => ['days' => []],
    ]);

    $this->artisan('forecast:purge')->assertExitCode(0);

    expect($log->fresh()->weather_snapshot)->toBeNull();
});
```

(Match `PurgeForecastHistoryTest.php` for the exact retention math and factory states.)

- [ ] **Step 7: Run the digest + purge suites**

Run: `php artisan test --compact --filter=Digest`
Expected: PASS.

- [ ] **Step 8: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Console/Commands routes/console.php tests/Feature/Digest
git commit -m "feat(scheduler): hourly zone-aware selector, --now test clock, split purge to daily"
```

## Task 9: Dashboard shows the applicable send zone

**Files:**
- Modify: `app/Http/Controllers/DashboardController.php:27-58` (instant-based; add `send_zone_abbr` + `is_sending`)
- Modify: `resources/js/pages/Dashboard.vue:33-36,122-140` (type + copy)
- Test: `tests/Feature/Dashboard/*` (controller props reflect the zone)

**Interfaces:**
- Consumes: `CadencePredicate::dueSendDate`, `CadencePredicate::nextSendDate`, `CadencePredicate::timezoneForSendDate`.
- Produces: each upcoming card carries `send_zone_abbr: string` (e.g. `BST`, `PDT`, `EDT`) for the next send date; the dashboard line reads "Sending today at 7am {abbr}".

- [ ] **Step 1: Write the failing controller test**

In the dashboard controller test file, add:

```php
it('labels the next send in the trip destination zone once in the destination phase', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-05 06:00:00', 'UTC')); // 07:00 BST
    $user = User::factory()->create(['email_verified_at' => now(), 'timezone' => 'America/Los_Angeles']);
    Trip::factory()->for($user)->create([
        'departure_date' => '2026-08-01', 'return_date' => '2026-08-10', 'destination_timezone' => 'Europe/London',
    ]);

    $this->actingAs($user)->get('/dashboard')
        ->assertInertia(fn ($page) => $page->where('upcomingTrips.0.send_zone_abbr', 'BST'));
});
```

- [ ] **Step 2: Run to verify failure**

Run: `php artisan test --compact --filter=Dashboard`
Expected: FAIL — no `send_zone_abbr`; `is_sending` uses the old date-based `isDue`.

- [ ] **Step 3: Make the controller instant + zone-aware**

In `app/Http/Controllers/DashboardController.php`, use a real instant and derive the zone abbreviation for the next send date:

```php
        $now = Carbon::now();

        // SoftDeletes scope excludes deleted trips automatically.
        $trips = $request->user()->trips()->orderBy('departure_date')->get();

        $cards = $trips->map(function (Trip $trip) use ($cadence, $now): array {
            $nextSend = $cadence->nextSendDate($trip, $now);
            $sendZoneAbbr = null;

            if ($nextSend !== null) {
                $zone = $cadence->timezoneForSendDate($trip, $nextSend->toDateString());
                $sendZoneAbbr = CarbonImmutable::parse($nextSend->toDateString(), $zone)->format('T');
            }

            return [
                'id' => $trip->id,
                'destination' => $trip->canonical_place_name !== '' ? $trip->canonical_place_name : $trip->destination_raw,
                'departure_date' => $trip->departure_date->toDateString(),
                'return_date' => $trip->return_date->toDateString(),
                'status' => $trip->status,
                'days_until_departure' => $cadence->daysUntilDeparture($trip, $now),
                'next_send_date' => $nextSend?->toDateString(),
                'days_until_send' => $nextSend !== null
                    ? (int) CarbonImmutable::parse($now->copy()->setTimezone($cadence->timezoneForSendDate($trip, $nextSend->toDateString()))->toDateString())->diffInDays($nextSend, false)
                    : null,
                'is_sending' => $cadence->dueSendDate($trip, $now) !== null,
                'send_zone_abbr' => $sendZoneAbbr,
            ];
        });
```

(Keep the `Inertia::render` block below unchanged.)

- [ ] **Step 4: Use the abbreviation in the dashboard copy**

In `resources/js/pages/Dashboard.vue`, add `send_zone_abbr: string | null;` to the `TripCard` type (near line 36), and update `nextSendLine` to prefer it, falling back to "7am ET":

```ts
function nextSendLine(trip: TripCard): string | null {
    const zone = trip.send_zone_abbr ?? 'ET';

    if (trip.is_sending) {
        const when = trip.days_until_send === 0 ? 'today' : 'tomorrow';

        return `Sending ${when} at 7am ${zone}`;
    }

    if (trip.next_send_date !== null && trip.days_until_send !== null) {
        const noun = trip.days_until_send === 1 ? 'day' : 'days';

        return `First forecast in ${trip.days_until_send} ${noun} · ${formatDay(trip.next_send_date)} · 7am ${zone}`;
    }

    return null;
}
```

- [ ] **Step 5: Run the dashboard suite**

Run: `php artisan test --compact --filter=Dashboard`
Expected: PASS.

- [ ] **Step 6: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Controllers/DashboardController.php resources/js/pages/Dashboard.vue tests/Feature/Dashboard
git commit -m "feat(dashboard): show the trip's applicable send zone (e.g. 7am BST)"
```

## Task 10: Integration guards — idempotency, transition day, DST

**Files:**
- Test: `tests/Feature/Digest/TimezoneSendIntegrationTest.php` (create)

**Interfaces:**
- Consumes: everything above. No production code unless a test surfaces a real defect (if it does, return to systematic-debugging — do not weaken a test to pass).

- [ ] **Step 1: Write the idempotency test (hourly re-runs send once)**

Create `tests/Feature/Digest/TimezoneSendIntegrationTest.php`:

```php
<?php

use App\Models\Trip;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;

it('dispatches at most one job per local day across hourly runs', function () {
    Bus::fake();
    $user = User::factory()->create(['email_verified_at' => now(), 'timezone' => 'America/Los_Angeles']);
    $trip = Trip::factory()->for($user)->create([
        'departure_date' => '2026-08-01', 'return_date' => '2026-08-10', 'destination_timezone' => 'Europe/London',
    ]);

    foreach (['13:00', '14:00', '15:00', '16:00'] as $utcHour) { // 06:00–09:00 LA
        Carbon::setTestNow(Carbon::parse("2026-07-28 {$utcHour}:00", 'UTC'));
        $this->artisan('digests:send')->assertExitCode(0);
    }

    // 06:00 LA run is pre-7am (no dispatch); 07/08/09:00 LA all map to the SAME
    // local send_date 2026-07-28. The command dispatches each hour it's ≥7am, but
    // the SendTripDigest claim dedups — assert the local send_date is stable.
    Bus::assertDispatched(SendTripDigest::class, fn ($job) => $job->sendDate === '2026-07-28');
    Bus::assertDispatchedTimes(SendTripDigest::class, 3);
});
```

Note: `Bus::fake()` bypasses the DB claim, so the command dispatches once per ≥7am hour (3×). Add a second test *without* `Bus::fake()` to prove the claim collapses those to one `email_logs` row:

```php
it('writes exactly one email_logs row per local day despite hourly runs', function () {
    $user = User::factory()->create(['email_verified_at' => now(), 'timezone' => 'America/Los_Angeles']);
    $trip = Trip::factory()->for($user)->create([
        'departure_date' => '2026-08-01', 'return_date' => '2026-08-10', 'destination_timezone' => 'Europe/London',
    ]);

    foreach (['14:00', '15:00', '16:00'] as $utcHour) {
        Carbon::setTestNow(Carbon::parse("2026-07-28 {$utcHour}:00", 'UTC'));
        $this->artisan('digests:send')->assertExitCode(0);
    }

    expect(\App\Models\EmailLog::where('trip_id', $trip->id)->where('send_date', '2026-07-28')->count())->toBe(1);
});
```

- [ ] **Step 2: Write the transition-day test (consecutive dates, no dup/skip)**

Add:

```php
it('produces consecutive send dates across the departure transition with no duplicate', function () {
    $user = User::factory()->create(['email_verified_at' => now(), 'timezone' => 'America/Los_Angeles']);
    $trip = Trip::factory()->for($user)->create([
        'departure_date' => '2026-08-01', 'return_date' => '2026-08-04', 'destination_timezone' => 'Europe/London',
    ]);

    // Walk real send moments: 07:00 LA each pre/departure day, then 07:00 London after.
    $moments = [
        '2026-07-31 14:00', // 07:00 LA, home phase
        '2026-08-01 14:00', // 07:00 LA, departure day (home phase: date <= departure)
        '2026-08-02 06:00', // 07:00 London, destination phase
        '2026-08-03 06:00', // 07:00 London
        '2026-08-04 06:00', // 07:00 London (return day)
    ];
    foreach ($moments as $m) {
        Carbon::setTestNow(Carbon::parse($m, 'UTC'));
        $this->artisan('digests:send')->assertExitCode(0);
    }

    $dates = \App\Models\EmailLog::where('trip_id', $trip->id)->orderBy('send_date')->pluck('send_date')
        ->map(fn ($d) => \Illuminate\Support\Carbon::parse($d)->toDateString())->all();

    expect($dates)->toBe(['2026-07-31', '2026-08-01', '2026-08-02', '2026-08-03', '2026-08-04'])
        ->and($dates)->toHaveCount(count(array_unique($dates))); // no duplicate day
});
```

- [ ] **Step 3: Write the DST test (sends exactly once on the boundary)**

Add (US spring-forward 2026-03-08, America/Los_Angeles skips 02:00→03:00; the 7am send is unaffected but proves the local-hour rule holds across the boundary date):

```php
it('sends exactly once on a DST boundary date', function () {
    $user = User::factory()->create(['email_verified_at' => now(), 'timezone' => 'America/Los_Angeles']);
    $trip = Trip::factory()->for($user)->create([
        'departure_date' => '2026-03-10', 'return_date' => '2026-03-20', 'destination_timezone' => 'Europe/London',
    ]);

    // 2026-03-08 is spring-forward in LA. 07:00 PDT that day == 14:00Z.
    foreach (['13:00', '14:00', '15:00'] as $utcHour) {
        Carbon::setTestNow(Carbon::parse("2026-03-08 {$utcHour}:00", 'UTC'));
        $this->artisan('digests:send')->assertExitCode(0);
    }

    expect(\App\Models\EmailLog::where('trip_id', $trip->id)->where('send_date', '2026-03-08')->count())->toBe(1);
});
```

- [ ] **Step 4: Run the integration suite**

Run: `php artisan test --compact --filter=TimezoneSendIntegration`
Expected: PASS. If any fails, STOP and debug the root cause (systematic-debugging) — the claim/zone logic has a real bug; do not relax the assertion.

- [ ] **Step 5: Full suite + commit**

Run: `php artisan test --compact`
Expected: PASS (whole suite).

```bash
git add tests/Feature/Digest/TimezoneSendIntegrationTest.php
git commit -m "test(send): idempotency, transition-day, and DST guards for zone-aware sends"
```

## Task 11: Docs, env, and manual verification

**Files:**
- Modify: `.env.example` (heartbeat cadence note)
- Modify: `docs/commands.md` / `docs/deployment.md` if they document the scheduler cadence or the old 9am send

- [ ] **Step 1: Update operational docs**

In `.env.example`, near `TRIPCAST_HEARTBEAT_URL`, add a note that the digest run is now **hourly** (the dead-man's-switch monitor should expect an hourly ping, not daily). If `docs/deployment.md` or `docs/commands.md` mention "9am"/"daily send", update them to "hourly selector; 7am in each trip's applicable zone".

- [ ] **Step 2: Manual end-to-end via the test clock**

Ask the user to run locally (with `MAIL_MAILER=log`):

```bash
php artisan digests:send --now="2026-08-05 07:05 Europe/London"
```

Expected: a trip whose destination is `Europe/London` and is in its window dispatches; the rendered email lands in `storage/logs/laravel.log`. Confirm `email_logs.send_date` is the London local date.

- [ ] **Step 3: Commit any doc changes**

```bash
git add .env.example docs
git commit -m "docs(send): hourly scheduler + per-zone 7am send notes"
```

---

## Self-Review (completed while writing)

- **Spec coverage:** config knob (T1) · phase-aware zone resolution (T7) · `send_date` = local date (T7/T8) · hourly scheduler (T8) · SQL prefilter + PHP refine (T7 `candidatesNear` / T8) · purge split (T8) · heartbeat cadence note (T11) · predicate signature change (T7) · display methods + dashboard zone (T9) · home tz capture (T5) + settings edit (T6) · destination tz capture + backfill-by-fallback (T4) · `--now` prod-guarded flag (T8) · full test matrix incl. DST/transition/idempotency (T10). All spec sections map to a task.
- **Placeholder scan:** frontend snippets in T6 (settings select) and the T5 test assertion explicitly say "match the file's existing pattern" rather than inventing the file's private conventions blind — these are the two spots to reconcile against the real file during execution; all backend code is complete.
- **Type consistency:** `dueSendDate(): ?string`, `timezoneForSendDate(): string`, `candidatesNear(): Collection`, `Forecast(array $days, ?string $timezone = null)`, card field `send_zone_abbr` — used consistently across T7/T8/T9.
