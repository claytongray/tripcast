---
baseline_commit: 18c03edbe5d3f72cf0dd7621bfb2b515e55db57c
---

# Story 2.1: Weather provider port + fresh forecast fetch by coordinates

Status: review

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

As the system,
I want a swappable weather port that fetches a fresh 7-day forecast by coordinates,
so that digests use current data and the provider can be swapped without touching call sites.

## Acceptance Criteria

**AC1 — Fresh forecast by coordinates only, faithful to the provider** *(FR-11, AD-1, AD-7)*
- **Given** a `WeatherProvider` interface with a `WeatherApiProvider` adapter (vendor HTTP only in the adapter)
- **When** a forecast is requested for a Trip
- **Then** it is fetched **fresh** (not pre-cached), **by coordinates only** (latitude/longitude — no geocoding dependency on the weather provider), and is **faithful to the provider response**: each day keyed to the **destination-local calendar date** exactly as the provider returns it, carrying high/low in **both °C and °F** (as provided — conversion is a render concern, AD-7) plus condition text and precip probability.

**AC2 — Partial data yields a "limited data" marker, never fabricated values** *(FR-7, UX-DR15)*
- **Given** partial provider data (fewer than 7 days, or a day missing values)
- **When** the forecast is assembled
- **Then** the gap is represented as **limited** (the day carries a "limited" marker / null values, or the forecast has fewer days) rather than fabricated figures.

**AC3 — Failures are typed and isolated to the adapter** *(AD-1)*
- **Given** an HTTP error, non-200, or unparseable/empty body
- **When** the adapter runs
- **Then** it throws a typed `WeatherProviderFailedException` (the vendor exception never leaks past the adapter); the caller decides what to do (Epic 2 send job → log + skip, never a broken digest, AD-4).

## Tasks / Subtasks

- [x] **Task 1 — `WeatherProvider` port + forecast DTOs + typed failure** (AC: 1, 2, 3)
  - [x] `app/Services/Weather/WeatherProvider.php` — `fetchForecast(float $latitude, float $longitude): Forecast` (coordinates only)
  - [x] `app/Services/Weather/Forecast.php` — `list<ForecastDay> $days`; `isLimited()` (< 7 days, or any day limited)
  - [x] `app/Services/Weather/ForecastDay.php` — `date`, `conditionText`, `precipChance`, `highC/highF/lowC/lowF` (all nullable); `isLimited()`
  - [x] `app/Services/Weather/WeatherProviderFailedException.php`
- [x] **Task 2 — `WeatherApiProvider` adapter + `FakeWeatherProvider` + binding** (AC: 1, 2, 3)
  - [x] `WeatherApiProvider` — `forecast.json` via `Http` (vendor only here); `q={lat},{lng}`, `days=7`, `aqi=no`, `alerts=no`; faithful map; missing fields → null; non-OK/empty/no-forecast → `WeatherProviderFailedException`
  - [x] `FakeWeatherProvider` — deterministic 7-day; sentinel `latitude 0.0` → partial forecast
  - [x] `config/services.php` `weatherapi.key`; `WEATHERAPI_KEY` in `.env` (gitignored) / `.env.example` (empty)
  - [x] Bound in `AppServiceProvider`: real adapter when keyed, else fake; **production fail-fast** when missing
- [x] **Task 3 — Tests** (AC: 1, 2, 3)
  - [x] Adapter full payload → 7 `ForecastDay`s (dates/both-unit temps/precip/condition); `Http::assertSent` confirms `q={lat},{lng}` + `days=7`
  - [x] Short forecast (3 days) → `isLimited()`; day missing `maxtemp_c/f` → null + `isLimited()`, others intact (not invented)
  - [x] Non-200 and no-`forecast` body → `WeatherProviderFailedException`
  - [x] `FakeWeatherProvider` deterministic complete / sentinel limited; bound by default when key empty
  - [x] Network-free (`Http::fake` / fake provider); no DB writes

## Dev Notes

### Scope boundary (read first)
- This story is **only** the weather port + adapter + the in-memory `Forecast` DTO. **No persistence** — storing the snapshot on `email_logs` is **Story 2.3** (the table doesn't exist yet; do not create it). **No rendering** — the °F/°C digest rows + the "limited data" *line* are **Story 2.4**. **No scheduling/cadence** — that's 2.2. 2.1 just fetches a faithful structure by coordinates and marks partial data. [Source: epics.md#Story-2.3, #Story-2.4, #Story-2.2]
- This story does **not** wire the provider into any send pipeline yet; it's the standalone, swappable boundary the later stories consume.

### Architecture (binding)
- **AD-1 — ports for external I/O:** the `WeatherProvider` **interface** is the seam; WeatherAPI HTTP appears **only** in `WeatherApiProvider`. Code depends on the interface. Port = capability noun (`WeatherProvider`); adapter = vendor-prefixed (`WeatherApiProvider`). Bind port→adapter in a ServiceProvider. **Weather is requested by coordinates only — no geocoding dependency on the weather provider** (the two ports are independent). [Source: ARCHITECTURE-SPINE.md#AD-1]
- **AD-7 — time frames & temperatures:** the **7-day forecast rows render in the destination's local calendar days, exactly as WeatherAPI returns them** — so take `forecastday[].date` as-is (no timezone conversion in this layer). Store/compute provider values; render **both °F and °C** with conversion *at render* (AD-7 / Conventions "Temperatures"). WeatherAPI conveniently returns both `_c` and `_f`, so the DTO carries both faithfully; no conversion happens here. Scheduling math (`send_date`) is America/New_York but is **not** this story's concern. [Source: ARCHITECTURE-SPINE.md#AD-7, #Consistency-Conventions "Temperatures", "Dates & times"]
- **AD-9 (forward ref):** the fetched snapshot will later be persisted once on the claimed `email_logs` row before delivery (Story 2.3); forecasts are cached **nowhere else** and fetched **fresh every morning**. 2.1 must therefore return a self-contained, serializable structure (plain DTO) suitable for snapshotting later. [Source: ARCHITECTURE-SPINE.md#AD-9]
- **AD-4 (forward ref):** the send Job will catch a `WeatherProviderFailedException` and **send nothing rather than a broken digest**; keep the failure typed and the fetch side-effect-free. [Source: ARCHITECTURE-SPINE.md#AD-4; EXPERIENCE.md State Patterns "Weather API down → send nothing"]
- **Naming/structure:** `app/Services/Weather/` (port + adapter), bound in `AppServiceProvider`/a provider; vendor key in `.env`. [Source: ARCHITECTURE-SPINE.md#Structural-Seed, #Consistency-Conventions "Config & secrets"]

### WeatherAPI.com specifics (verify field names against current docs when wiring the real key)
- Endpoint: `GET https://api.weatherapi.com/v1/forecast.json` · params: `key`, `q` = `"{lat},{lng}"`, `days=7`, `aqi=no`, `alerts=no`. Use Laravel `Http` with a timeout. [Source: ARCHITECTURE-SPINE.md#Stack "WeatherAPI.com, Starter plan"]
- Response shape (stable): `forecast.forecastday[]`, each with `date` (Y-m-d, location-local) and `day { maxtemp_c, maxtemp_f, mintemp_c, mintemp_f, daily_chance_of_rain, condition { text, code } }`. Map to `ForecastDay`; treat any missing field as `null` (limited), never invent.
- **Plan note:** the **Starter plan returns ≥7 forecast days**; a free key returns fewer (~3). The limited-data marker (AC2) makes the adapter robust to whichever the configured key allows — fewer days ⇒ `isLimited()` true, no fabrication. Capture the resolved plan/behavior when the real key is added.
- Treat a non-`200`, a transport exception, or a body without `forecast.forecastday` as `WeatherProviderFailedException`.

### `FakeWeatherProvider` (local dev + tests)
- Deterministic: return 7 `ForecastDay`s starting "today" (or a fixed base date) with plausible, **fixed** values (both °C/°F, precip, condition), so dev/CI run before the real key exists and tests are stable. Provide a sentinel (e.g. a specific latitude like `0.0` or a flag) that returns a **partial** forecast (e.g. 3 days, or one day with nulls) so the limited-data path is exercisable without the real API. Keep it dependency-free.
- Binding rule (in `AppServiceProvider`): `WeatherApiProvider` when `services.weatherapi.key` is set, else `FakeWeatherProvider`; in `app()->isProduction()` with no key → **throw** (never fake forecasts in prod). This is the same shape as the `Geocoder` binding (Story 1.3 + the Epic 1 review hardening).

### Testing standards
- Pest tests under `tests/Feature/Weather/` (so the Laravel app + `Http` facade are available). `Http::fake([...])` with a representative full payload, a partial payload, and an error response; assert mapping, `isLimited()`, the thrown exception, and (via `Http::assertSent`) that the outgoing request used the coordinates and `days=7`. **Never call the real API in tests.** No DB. [Source: 1-3 GoogleGeocoderTest pattern]
- Gates before "done": `./vendor/bin/pest`, `./vendor/bin/pint`, `./vendor/bin/phpstan analyse`, `npm run types:check`, `npm run lint:check`, `npm run build:ssr` (frontend unchanged, but keep the suite green).

### Project Structure Notes
- New only: `app/Services/Weather/{WeatherProvider,Forecast,ForecastDay,WeatherApiProvider,FakeWeatherProvider,WeatherProviderFailedException}.php`, tests; **modified:** `app/Providers/AppServiceProvider.php` (binding), `config/services.php`, `.env`/`.env.example`. No migrations, routes, models, or frontend. [Source: ARCHITECTURE-SPINE.md#Structural-Seed "Services/Weather"]

### Previous story intelligence (Story 1.3 — the directly analogous port)
- **Copy the geocoding pattern verbatim in shape:** `Geocoder` (interface) + `GeocodeResult` (readonly DTO) + `GoogleGeocoder` (HTTP adapter, `Http::timeout`, typed `GeocodingFailedException`, vendor errors wrapped) + `FakeGeocoder` + the **conditional bind** in `AppServiceProvider` (`register()`), with **production fail-fast** when the key is empty (added in the Epic 1 review). Mirror all of it for weather. [Source: 1-3 app/Services/Geocoding/*; app/Providers/AppServiceProvider.php]
- The adapter test pattern (`Http::fake` + `Http::assertSent`, map a sample payload, assert the thrown exception on `ZERO_RESULTS`/error) is established in `tests/Feature/Geocoding/GoogleGeocoderTest.php` — follow it. [Source: 1-3 test]
- `config/services.php` already has a `google` block; add a sibling `weatherapi` block. `phpunit.xml` sets `GOOGLE_GEOCODING_KEY=""` so the fake binds in tests; add `WEATHERAPI_KEY=""` the same way (so `FakeWeatherProvider` binds and no network is hit). [Source: 1-3 config/services.php, phpunit.xml]
- **Real key:** Clayton provided the Google key on request; a WeatherAPI.com key will be needed for the real adapter (`.env`, gitignored). The story is fully buildable + testable now via the fake; the real adapter is verifiable once the key lands. Recommend restricting/rotating the key as with Google.
- Quality lessons carried: run **PHPStan**; isolate vendor code to the adapter; readonly DTOs; deterministic, network-free tests.

### References
- [Source: _bmad-output/planning-artifacts/epics.md#Story-2.1] (+ #Story-2.2/2.3/2.4 for scope boundaries)
- [Source: _bmad-output/planning-artifacts/architecture/architecture-tripcast-2026-06-28/ARCHITECTURE-SPINE.md#AD-1, #AD-7, #AD-9, #AD-4, #Structural-Seed, #Consistency-Conventions, #Stack]
- [Source: _bmad-output/planning-artifacts/ux-designs/ux-tripcast-2026-06-28/EXPERIENCE.md#State-Patterns ("Limited weather data", "Weather API down")]
- [Source: _bmad-output/specs/spec-tripcast/SPEC.md#FR-11, #FR-7]

## Dev Agent Record

### Agent Model Used

Amelia (Senior Software Engineer) — claude-opus-4-8[1m]

### Debug Log References

- TDD via `Http::fake` adapter tests + fake-provider tests (8 new). Full suite: `./vendor/bin/pest` 65 passed / 254 assertions. PHPStan 0, Pint, vue-tsc, build green.

### Completion Notes List

- `WeatherProvider` port (`fetchForecast(lat, lng): Forecast`) — coordinates only (FR-11, AD-1). `WeatherApiProvider` is the only place WeatherAPI HTTP appears; `FakeWeatherProvider` for dev/CI. Mirrors the Story 1.3 `Geocoder` shape exactly.
- `Forecast` / `ForecastDay` readonly DTOs carry the provider's destination-local dates and **both °C and °F** as given (AD-7 — no conversion in this layer); missing fields are **null** and surfaced via `isLimited()` (FR-7), never fabricated.
- Adapter requests `forecast.json?q={lat},{lng}&days=7&aqi=no&alerts=no`; maps `forecast.forecastday[].day.{maxtemp_c/f,mintemp_c/f,daily_chance_of_rain,condition.text}`. Non-200 / transport error / body without `forecast.forecastday` → typed `WeatherProviderFailedException` (vendor error wrapped, not leaked).
- Binding in `AppServiceProvider`: `WeatherApiProvider` when `services.weatherapi.key` set, else `FakeWeatherProvider`; **prod fail-fast** when the key is missing (same hardening as the geocoder). `phpunit.xml` sets `WEATHERAPI_KEY=""` so tests use the fake (no network).
- **Scope held:** no persistence (snapshot on `email_logs` is Story 2.3), no render (°F/°C rows + "limited data" line is Story 2.4), no scheduling (2.2). No DB/migrations/routes/frontend.
- **Needs a key for the real adapter:** a WeatherAPI.com key in `.env` (gitignored). Buildable + tested now via the fake; verify the live adapter (and the plan's day count) once the key lands.

### File List

**Created**
- `app/Services/Weather/WeatherProvider.php` · `Forecast.php` · `ForecastDay.php` · `WeatherApiProvider.php` · `FakeWeatherProvider.php` · `WeatherProviderFailedException.php`
- `tests/Feature/Weather/WeatherApiProviderTest.php` · `tests/Feature/Weather/WeatherProviderBindingTest.php`

**Modified**
- `app/Providers/AppServiceProvider.php` — `WeatherProvider` binding
- `config/services.php` — `weatherapi.key`
- `.env.example` — empty `WEATHERAPI_KEY` (real key in gitignored `.env`)
- `phpunit.xml` — empty `WEATHERAPI_KEY` (fake in tests)

### Change Log

| Date | Change |
| --- | --- |
| 2026-06-29 | Story 2.1 implemented: `WeatherProvider` port + `WeatherApiProvider`/`FakeWeatherProvider` (AD-1), fresh fetch by coordinates (FR-11), faithful destination-local days + both units (AD-7), limited-data marker (FR-7), typed failure. 8 new tests (65 total). Epic 2 begins. Status → review. |
