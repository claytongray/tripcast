# Story 2.1: Weather provider port + fresh forecast fetch by coordinates

Status: ready-for-dev

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

- [ ] **Task 1 — `WeatherProvider` port + forecast DTOs + typed failure** (AC: 1, 2, 3)
  - [ ] `app/Services/Weather/WeatherProvider.php` — interface: `fetchForecast(float $latitude, float $longitude): Forecast` (capability-noun port, AD-1; **coordinates only**)
  - [ ] `app/Services/Weather/Forecast.php` — readonly DTO holding `list<ForecastDay> $days`; `isLimited(): bool` (fewer than 7 days, or any day limited)
  - [ ] `app/Services/Weather/ForecastDay.php` — readonly DTO: `date` (Y-m-d, destination-local), `conditionText` (?string), `precipChance` (?int, %), `highC`/`highF`/`lowC`/`lowF` (?float); `isLimited(): bool` (any core value null)
  - [ ] `app/Services/Weather/WeatherProviderFailedException.php` — thrown on transport/non-OK/unparseable; caught at the Job/Action boundary (Epic 2), never leaks the vendor error
- [ ] **Task 2 — `WeatherApiProvider` adapter + `FakeWeatherProvider` + binding** (AC: 1, 2, 3)
  - [ ] `app/Services/Weather/WeatherApiProvider.php` — calls WeatherAPI.com `forecast.json` via Laravel `Http` (vendor HTTP **only here**); `q={lat},{lng}`, `days=7`, `aqi=no`, `alerts=no`; maps each `forecast.forecastday[]` → `ForecastDay` (date; `day.maxtemp_c/f`, `day.mintemp_c/f`, `day.daily_chance_of_rain`, `day.condition.text`); missing fields → null (limited), **never fabricated**; non-OK/empty → `WeatherProviderFailedException`
  - [ ] `app/Services/Weather/FakeWeatherProvider.php` — deterministic 7-day forecast for local dev (no key) + tests; supports a sentinel coordinate (or input) that returns a **partial** forecast to exercise the limited-data path
  - [ ] `config/services.php` — `weatherapi.key` from `WEATHERAPI_KEY`; add the var to `.env` / `.env.example` (empty for now)
  - [ ] Bind `WeatherProvider` in `AppServiceProvider`: **`WeatherApiProvider` when the key is set, else `FakeWeatherProvider`**, and **fail-fast in production when the key is missing** (mirror the `Geocoder` binding + the Epic 1 review fix — never silently serve fake forecasts in prod)
- [ ] **Task 3 — Tests** (AC: 1, 2, 3)
  - [ ] Adapter (`Http::fake()`): a full 7-day WeatherAPI payload → `Forecast` with 7 `ForecastDay`s, correct dates/temps (both units)/precip/condition; **`Http::assertSent`** that the request used `q={lat},{lng}` (coordinates, not a name) and `days=7`
  - [ ] Adapter partial payload (e.g. 3 days, or a day missing `maxtemp_c`) → `Forecast::isLimited()` true; the missing values are **null**, not invented
  - [ ] Adapter on non-200 / empty body → throws `WeatherProviderFailedException` (vendor error not leaked)
  - [ ] `FakeWeatherProvider` returns a deterministic complete forecast for normal coords and a limited one for the sentinel; bound by default when the key is empty
  - [ ] No network in tests (`Http::fake` for the adapter; `FakeWeatherProvider` for the bound port); no DB writes (this story persists nothing)

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

### Debug Log References

### Completion Notes List

### File List
