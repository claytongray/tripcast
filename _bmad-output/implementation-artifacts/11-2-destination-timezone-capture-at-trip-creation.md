---
baseline_commit: ac051fb094c3361b0e11c0beb60b6c38b9104d64
---

# Story 11.2: Destination timezone capture at trip creation

Status: review

<!-- Forward-looking story. Design contract: _bmad-output/specs/spec-weatherkit-provider-swap/SPEC.md
(CAP-7, CAP-9) + companion weatherkit-integration.md. Implementation reference:
docs/superpowers/plans/2026-07-04-weatherkit-provider-swap.md (Tasks 4, 5, 8). Second story of Epic 11. -->

## Story

As a traveler who signs up by creating a trip,
I want tripcast to figure out my destination's timezone the moment the trip is created and remember it,
so that WeatherKit aligns each daily high to the destination's local day — right from the welcome email's first tripcast — without asking Google again on every send.

## Context & Provenance

- **Second story of Epic 11.** Depends on Story 11.1 (the `WeatherKitProvider` + the minimal `DestinationTimezone` resolver stub). This story makes the resolver real and persists its result.
- **Why WeatherKit needs this (unlike WeatherAPI):** WeatherKit **consumes** a timezone as input (it aligns `forecastDaily` day boundaries to it) and returns **no** `location.tz_id`. Omitting it defaults the daily rollups to GMT and re-misaligns the very daily high Epic 11 fixes. WeatherAPI handed us `location.tz_id` for free; that source is gone.
- **Resolve once, reuse (CAP-9), at trip creation.** The welcome email embeds the first tripcast, so a lazy "resolve on first forecast" would fire the Google call *during signup* or race it. `CreateTrip` is the single creation decision point (AD-10) and already has lat/long from geocoding — resolve there (outside the DB txn, like geocoding) and persist to a new `trips.destination_timezone`. Google is then called **at most once per trip**; every send reads the stored zone.
- **Supersedes the timezone-aware-send-time plan's Task 3 + Task 4.** `docs/superpowers/plans/2026-07-03-timezone-aware-send-time.md` planned to add this column (T3) and capture it from WeatherAPI `location.tz_id` (T4). This story creates the identical column and captures from the Google Time Zone API instead. This story adds a `SUPERSEDED` banner to those two tasks; that plan's phase-aware `CadencePredicate` resolution (its `destination_timezone ?? user.timezone`) consumes this column unchanged.
- **Design contract:** SPEC CAP-7 (resolve) + CAP-9 (persist). Reference: plan Tasks 4, 5, 8. Google Time Zone API is already enabled on `GOOGLE_GEOCODING_KEY` (live-tested `OK`).

## Acceptance Criteria

1. **Given** coordinates, **when** `DestinationTimezone::resolve(lat, lon)` runs, **then** it GETs `maps.googleapis.com/maps/api/timezone/json?location={lat},{lon}&timestamp={now}&key={services.google.geocoding_key}` and returns `timeZoneId` on `status === 'OK'`; on any non-OK status, transport error, or empty id it returns **null** and logs a warning; a successful lookup is **cached** by rounded coordinate so repeat coordinates make no second HTTP call.

2. **Given** the `trips` table, **when** the migration runs, **then** a nullable `string destination_timezone` column exists after `longitude`, and `Trip::$destination_timezone` is mass-assignable (`$fillable`) with a `?string` `@property`. Shape is identical to what the timezone-aware-send-time plan expected.

3. **Given** a trip is created via `CreateTrip::handle`, **when** it runs, **then** the destination zone is resolved **before** the `DB::transaction` (an external call stays outside the txn, matching the geocode/mail contract) and written to `trips.destination_timezone`, so the value is non-null before the welcome email renders (happy path). **Given** resolution fails, **then** the column is left null (logged) and creation still succeeds.

4. **Given** `SendTripDigest` sends a trip, **when** it fetches the forecast, **then** it passes `$this->trip->destination_timezone` as the `fetchForecast(..., $timezone)` argument; WeatherAPI ignores it, WeatherKit uses it (falling back internally to a fresh resolve or `config('tripcast.forecast.default_timezone')` when the stored value is null).

5. **Given** the timezone-aware-send-time plan, **then** its Task 3 and Task 4 carry a `SUPERSEDED` banner pointing here; nothing in this story implements the send scheduler, home-zone capture, or `CadencePredicate` zone resolution (that remains that feature's scope).

6. **Given** completion, **then** all verification gates pass (`php artisan test --compact`, `pint`, `phpstan`, `types:check`, `lint:check`, `build:ssr`), and **`php artisan migrate` is run on the dev DB** (a green `RefreshDatabase` suite can hide a pending migration — project-context.md).

## Tasks / Subtasks

- [x] **Task 1: Flesh out `DestinationTimezone` (AC: 1)** — plan Task 4
  - [x] `app/Services/Weather/DestinationTimezone.php`: `resolve(float,float): ?string` via Google Time Zone API; `Cache::remember` by `round(lat,3),round(lon,3)`; null + `Log::warning` on non-OK/error
  - [x] TDD: `tests/Feature/Weather/DestinationTimezoneTest.php` — `Http::fake` OK→zone, `ZERO_RESULTS`→null+warning, repeat coords → `Http::assertSentCount(1)`

- [x] **Task 2: `trips.destination_timezone` column + model (AC: 2)** — plan Task 5
  - [x] Migration `2026_07_04_000001_add_destination_timezone_to_trips_table.php`: `string('destination_timezone')->nullable()->after('longitude')`
  - [x] `app/Models/Trip.php`: add to `$fillable` + `@property string|null`
  - [x] TDD: persists a set value; defaults to null

- [x] **Task 3: Resolve + persist in `CreateTrip` (AC: 3)** — plan Task 5
  - [x] Inject `DestinationTimezone`; resolve **before** `DB::transaction` from `$tripDetails['latitude'|'longitude']`; add `'destination_timezone' => $zone` to the `trips()->create([...])`; extend the `@param` array shape
  - [x] TDD: `tests/Feature/Trip/DestinationTimezoneCaptureTest.php` — `Http::fake` google → creation stores the zone; failure → null; both succeed

- [x] **Task 4: Pass the stored zone from the send job (AC: 4)** — plan Task 8
  - [x] `app/Jobs/SendTripDigest.php`: `fetchForecast($lat, $lon, $this->trip->destination_timezone)`
  - [x] TDD: `SendTripDigestTest` asserts the third arg equals the trip's `destination_timezone`

- [x] **Task 5: Banner the superseded send-time tasks (AC: 5)**
  - [x] Add the `SUPERSEDED by 11.2` note under Task 3 and Task 4 headings in `docs/superpowers/plans/2026-07-03-timezone-aware-send-time.md`

- [x] **Task 6: Verification gates + dev-DB migrate (AC: 6)**

## Dev Notes

### Critical guardrails (read first)

- **Resolve at creation, not on first forecast.** The welcome email's first tripcast must find `destination_timezone` already set. `CreateTrip` is the one creation decision point every add path routes through (AD-10) — do it there.
- **External calls stay OUT of the DB transaction.** `CreateTrip`'s docblock is explicit: mail/geocode run outside; the txn is DB-only. Resolve the zone **before** `DB::transaction(...)`, exactly like geocoding runs before creation (AD-8).
- **Null is a valid stored value.** A Google failure at creation leaves the column null; the resolver returns null (never a bogus zone), so the send-time predicate's `destination_timezone ?? user.timezone` fallback and WeatherKit's per-fetch fallback both behave. Never store the config fallback *as if* it were the real destination zone.
- **Column shape must match the send-time plan verbatim** (`string`, nullable, after `longitude`; fillable + `@property`) so that plan's consumers resolve against it with no remapping.
- **Never cache PHP objects in Redis** [docs/deployment.md] — the resolver caches only the zone **string**.

### Existing patterns to copy (file:line)

| What | Where |
| --- | --- |
| Single creation decision point; external calls before the txn | app/Actions/CreateTrip.php (`handle`) |
| Google HTTP client style (same key) | app/Services/Geocoding/GoogleGeocoder.php |
| `fetchForecast(..., ?string $timezone)` signature (from 11.1) | app/Services/Weather/WeatherProvider.php, WeatherKitProvider.php |
| Send job fetch call site to update | app/Jobs/SendTripDigest.php (~line 57) |
| Migration `->after(...)` on trips | database/migrations/2026_06_29_000002_create_trips_table.php |

### Architecture & stack constraints

- Laravel 13 / PHP 8.3, Pest 4. Strict types; constructor property promotion; curly braces always.
- Uses the **existing** `GOOGLE_GEOCODING_KEY`; the Google **Time Zone API** is enabled on it (live-verified). No new key, no new dependency.
- `CreateTrip` gains a constructor dependency (`DestinationTimezone`) — resolve via the container; the welcome-email dependency stays.
- Adds one migration → **run `php artisan migrate` on the dev DB** after pulling (project-context.md: a green suite hides a pending migration).

### What NOT to do

- Do not resolve the zone lazily on first forecast, inside the DB transaction, or inside `WeatherKitProvider` as the primary path.
- Do not store the config fallback zone as the persisted `destination_timezone`.
- Do not build the send scheduler / home-zone capture / `CadencePredicate` zone resolution — that stays the timezone-aware-send-time feature's job (this only fills the column it reads).
- Do not change the `Forecast`/`ForecastDay` contract.

### Previous story intelligence

- Story 11.1 introduced a minimal `DestinationTimezone` (signature + Google call) and the `?string $timezone` port param — this story fleshes the resolver out and wires persistence. If 11.1 and 11.2 are built together, fold 11.1's resolver stub into this story's Task 1.
- Story 1.3/1.4 established geocode-once-then-`CreateTrip`; this story adds a second creation-time external lookup alongside geocoding, same "outside the txn" rule.

### Testing standards

- Pest 4, `RefreshDatabase` for the column/creation/job tests. `Http::fake('maps.googleapis.com/*' => ...)` before acting; never hit Google. Assert the cache dedup with `Http::assertSentCount(1)`.
- The creation-stores-the-zone test is load-bearing for CAP-9 — a failure means the welcome email could race Google.

### Project Structure Notes

- New: migration `2026_07_04_000001_add_destination_timezone_to_trips_table.php`, `tests/Feature/Trip/DestinationTimezoneCaptureTest.php`, `tests/Feature/Weather/DestinationTimezoneTest.php`. Modified: `app/Services/Weather/DestinationTimezone.php`, `app/Models/Trip.php`, `app/Actions/CreateTrip.php`, `app/Jobs/SendTripDigest.php`, `docs/superpowers/plans/2026-07-03-timezone-aware-send-time.md`.

## Dev Agent Record

### Completion Notes

- **All 6 ACs satisfied.** `trips.destination_timezone` is resolved once at trip creation (in `CreateTrip`, **before** the DB transaction) and persisted; `SendTripDigest` passes it into `fetchForecast`. This **retires both of Story 11.1's deferrals** — the resolve-on-every-send call and (by populating the zone at creation) the ET-fallback landmine, which now only bites if Google fails at creation *and* stays null.
- **Real issue found + fixed during implementation:** `CreateTrip` now calls `DestinationTimezone::resolve()` on every trip create, so without a guard every un-faked trip-creation test would fire a real Google HTTP call (the test env sets `GOOGLE_GEOCODING_KEY=""`, and 8s timeouts × many tests). Added a **no-key short-circuit** to `resolve()` (returns null when the key is blank), mirroring the `FakeGeocoder` fake-in-dev discipline. Keyed the resolver-exercising tests explicitly. Suite runtime stayed ~3.9s (no stray network).
- **`DestinationTimezone` was created in Story 11.1** (resolve + cache + cache-failure guard); this story added the no-key guard, the cache-dedup test, and the persistence wiring — it did not recreate the class.
- **Supersession recorded:** Tasks 3 & 4 of `docs/superpowers/plans/2026-07-03-timezone-aware-send-time.md` carry a `SUPERSEDED` banner; that feature now consumes `Trip::$destination_timezone` instead of deriving zones from WeatherAPI `location.tz_id`.
- **Dev DB migrated** (`php artisan migrate`) per project-context (a green `RefreshDatabase` suite hides a pending migration).

### File List

- **New:** `database/migrations/2026_07_04_000001_add_destination_timezone_to_trips_table.php`, `tests/Feature/Trip/DestinationTimezoneCaptureTest.php`
- **Modified:** `app/Services/Weather/DestinationTimezone.php`, `app/Models/Trip.php`, `app/Actions/CreateTrip.php`, `app/Jobs/SendTripDigest.php`, `tests/Feature/Weather/DestinationTimezoneTest.php`, `tests/Feature/Weather/WeatherKitProviderTest.php`, `tests/Feature/Digest/SendTripDigestTest.php`, `docs/superpowers/plans/2026-07-03-timezone-aware-send-time.md`

### Change Log

- 2026-07-04 — Implemented Story 11.2 (destination-timezone capture at trip creation). New `trips.destination_timezone` column + `Trip` property/fillable; `CreateTrip` resolves the zone before the DB txn and persists it (CAP-9); `SendTripDigest` passes it into `fetchForecast`. Added a no-key guard + cache-dedup test to `DestinationTimezone`. Bannered the superseded send-time plan tasks. Retires Story 11.1's two deferrals. Gates: 588/588 tests, Pint, PHPStan 0, types/lint/build:ssr clean. Status → review.
