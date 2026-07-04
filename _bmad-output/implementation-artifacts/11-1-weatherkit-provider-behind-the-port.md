---
baseline_commit: ac051fb094c3361b0e11c0beb60b6c38b9104d64
---

# Story 11.1: WeatherKit provider behind the port

Status: done

<!-- Forward-looking story (not a backfill). Authored via the superpowers workflow
(investigate → spec → plan → bmad story) then documented in the house format for the
BMAD paper trail. Design contract + implementation reference live at
_bmad-output/specs/spec-weatherkit-provider-swap/SPEC.md (CAP-1..9) + its companion
weatherkit-integration.md, and docs/superpowers/plans/2026-07-04-weatherkit-provider-swap.md
(Tasks 1,2,3,6,7 map to this story). First story of Epic 11 — registered via this file +
sprint-status.yaml with a non-destructive Epic-List append to epics.md (Epic 10 precedent). -->

## Story

As the tripcast system delivering morning digests (and the traveler who trusts the numbers),
I want the forecast fetched from Apple WeatherKit through the existing `WeatherProvider` port, selected by config,
so that daily highs reflect true air temperature — ending the 5–8°F inflation on hot, humid inland days — with **no change** to how forecasts are rendered.

## Context & Provenance

- **First story of Epic 11 — "Weather provider — Apple WeatherKit migration."** Epic 11 is registered via this story file + `sprint-status.yaml` and a small non-destructive append to the `epics.md` Epic List — the Epic 10 (Story 10.1/10.2) precedent, not an `epics.md` regeneration.
- **Root cause is upstream, not our code (proven).** Investigation showed the rendered high equals `round(day.maxtemp_f)`; `WeatherApiProvider.php:62` reads the daily air-temp field faithfully. The inflation lives inside WeatherAPI's own `maxtemp_f` on extreme-heat days (heat-index bleed: afternoon `temp_f` climbs while `feelslike_f` collapses below it). So the fix belongs at the provider. WeatherKit *is* the Apple Weather data we benchmark against.
- **Live-proven before this story was written:** with real credentials, Jul 4 highs returned **Kennett 97°F / Lewistown 93°F / Dewey 86°F** vs WeatherAPI's inflated **105 / 101 / 87** — CAP-2 confirmed on live data; coastal control intact.
- **Design contract:** `_bmad-output/specs/spec-weatherkit-provider-swap/SPEC.md` (this story delivers **CAP-1…CAP-6**) and its companion `weatherkit-integration.md` (endpoint, JWT shape, field lineage, condition codes). Step-level reference: `docs/superpowers/plans/2026-07-04-weatherkit-provider-swap.md` Tasks **1, 2, 3, 6, 7**.
- **Dependencies / follow-ons:**
  - **Story 11.2** (destination-timezone capture at trip creation → `trips.destination_timezone`, CAP-7/CAP-9). 11.1 does **not** depend on 11.2 to merge: `fetchForecast` gains an *optional* `?string $timezone`; when the caller passes null, the provider resolves the zone itself (cached) or falls back to `config('tripcast.forecast.default_timezone')`. 11.2 later persists the zone so the fallback path is rarely hit.
  - **Story 11.3** (Apple Weather attribution in the digest footer + production cutover, CAP-8).
- **Ships dark.** `config('tripcast.forecast.provider')` defaults to `weatherapi`, so this story merges with zero behavior change until 11.3 flips the flag. `firebase/php-jwt` is already installed; the four `APPLE_WEATHERKIT_*` env vars are set; the `.p8` is a git-ignored path.

## Acceptance Criteria

1. **Given** `config('tripcast.forecast.provider') === 'weatherkit'`, **when** the container resolves `WeatherProvider`, **then** it returns a `WeatherKitProvider`; **given** the value is `weatherapi` (the default), **then** it returns the existing `WeatherApiProvider`. The binding lives in `AppServiceProvider` and constructs `WeatherKitProvider` from `config('services.weatherkit')` (reading the `.p8` via `base_path`).

2. **Given** a WeatherKit request, **when** `WeatherKitProvider` calls the API, **then** it sends `Authorization: Bearer <jwt>` where the JWT is **ES256**, its header carries `alg=ES256`, `kid=<key_id>`, and the non-standard `id=<team_id>.<service_id>`, and its claims carry `iss=<team_id>`, `sub=<service_id>`, `iat`, `exp` (≤ iat+3600). The token is produced by `WeatherKitToken` and **cached** (keyed by `kid`) and reused within its lifetime. A unit test proves header + claims using a throwaway EC P-256 key and verifies the signature with the matching public key. Missing/invalid credentials that break signing or the request surface as `WeatherProviderFailedException`.

3. **Given** a WeatherKit `forecastDaily` payload (metric/SI, 0–1 decimals), **when** the provider maps a day, **then**: `highF`/`lowF` = `round(°C × 9/5 + 32)` from `temperatureMax`/`temperatureMin` (Kennett Jul 4 `36.23°C` → **97°F**, *not* 105); `precipChance` = `round(precipitationChance × 100)` as int; `humidity` = `round(daytimeForecast.humidity × 100)` as int (WeatherKit puts humidity on the day-part, **not** the day root — confirmed on the live payload); the day's `date` is the destination-local calendar date derived from `forecastStart` in the request timezone. A day missing any core value (`conditionText`, `precipChance`, high or low in either unit) stays **limited** via `ForecastDay::isLimited()` — never fabricated (FR-7); `humidity`/feels-like are optional and never make a day limited.

4. **Given** the `forecastHourly` payload, **when** the provider computes feels-like, **then** `feelsLikeHighC/F` = the **peak** hourly `temperatureApparent` for that destination-local date, converted to both units (mirrors today's `WeatherApiProvider::peakFeelsLike`). When no hour carries `temperatureApparent`, feels-like is null and the day is not made limited.

5. **Given** a WeatherKit `conditionCode` (PascalCase enum, e.g. `PartlyCloudy`, `ScatteredThunderstorms`, `Thunderstorms`), **when** the provider sets `conditionText`, **then** it uses `ConditionCode::label()` to produce a spaced human label ("Partly Cloudy", "Scattered Thunderstorms") that feeds the **existing** `WeatherEmoji` unchanged; `WeatherEmoji` gains keywords so the WeatherKit vocabulary is fully covered (`breez`, `hurricane`, `tropical`, `hail`), and a label with no keyword still renders text with an empty emoji.

6. **Given** the swap, **then** the `Forecast` / `ForecastDay` shape, the `email_logs.weather_snapshot` serialization, `ForecastRows`, and the email templates are **unchanged**; the only port change is the additive optional `?string $timezone = null` on `WeatherProvider::fetchForecast` (existing `WeatherApiProvider` / `FakeWeatherProvider` accept and ignore it). With the default flag (`weatherapi`), every existing weather/digest test stays green — no behavior change.

7. **Given** completion, **then** all verification gates pass: `php artisan test --compact`, `vendor/bin/pint --dirty --format agent`, `./vendor/bin/phpstan analyse`, `npm run types:check`, `npm run lint:check`, `npm run build:ssr`.

## Tasks / Subtasks

- [x] **Task 1: Config, credentials, and the provider flag (AC: 1, 6)** — plan Task 1
  - [x] `config/services.php`: add a `weatherkit` block (`team_id`, `service_id`, `key_id`, `private_key_path`) from the `APPLE_WEATHERKIT_*` env vars
  - [x] `config/tripcast.php`: extend the `forecast` block with `provider` (`env('TRIPCAST_WEATHER_PROVIDER','weatherapi')`) and `default_timezone` (`env('TRIPCAST_FALLBACK_TIMEZONE','America/New_York')`)
  - [x] `.env.example`: document `TRIPCAST_WEATHER_PROVIDER` and the four `APPLE_WEATHERKIT_*` keys (private key documented as a path)
  - [x] TDD: `tests/Unit/Weather/WeatherKitConfigTest.php` asserts the keys resolve and the provider defaults to `weatherapi`

- [x] **Task 2: `WeatherKitToken` — cached ES256 JWT (AC: 2)** — plan Task 2
  - [x] Create `app/Services/Weather/WeatherKit/WeatherKitToken.php`: `bearer(): string` via `firebase/php-jwt` `JWT::encode(...)` with the `['id' => "<team>.<service>"]` extra header; cache keyed by `kid` for < 3600s
  - [x] TDD: `tests/Unit/Weather/WeatherKitTokenTest.php` mints with a throwaway `openssl_pkey_new` P-256 key; asserts header `alg/kid/id` + claims `iss/sub/exp`; verifies signature with the public key; asserts the cached token is reused

- [x] **Task 3: `ConditionCode` label + `WeatherEmoji` keyword gaps (AC: 5)** — plan Task 3
  - [x] Create `app/Services/Weather/WeatherKit/ConditionCode.php`: `label(string): string` splitting interior capitals (`preg_replace('/(?<!^)(?=[A-Z])/', ' ', $code)`)
  - [x] `app/Digest/WeatherEmoji.php`: add `hurricane`/`tropical` to the thunder line, `hail` to the snow/ice line, `breez` to the wind line (additive)
  - [x] TDD: `tests/Unit/Weather/ConditionCodeTest.php` (labels) + emoji resolution for `PartlyCloudy/ScatteredThunderstorms/HeavyRain/MostlyClear/Breezy/Hurricane`; existing `WeatherEmoji` tests stay green

- [x] **Task 4: `WeatherKitProvider` + the optional timezone port param (AC: 3, 4, 6)** — plan Task 6
  - [x] `app/Services/Weather/WeatherProvider.php`: add `?string $timezone = null` to `fetchForecast`; update `WeatherApiProvider` + `FakeWeatherProvider` signatures (bodies unchanged — they ignore it)
  - [x] Create `app/Services/Weather/WeatherKit/WeatherKitProvider.php`: resolve zone (`$timezone ?? resolve() ?? config default`), `Http::withToken($token->bearer())` GET `weatherkit.apple.com/api/v1/weather/en/{lat}/{lon}?dataSets=forecastDaily,forecastHourly&timezone={zone}`; map each day (C→F, 0–1→%, `ConditionCode::label`, `peakApparentByDate`); `->failed()`/throwable → `WeatherProviderFailedException`
  - [x] Fixture `tests/Fixtures/weatherkit/kennett.json` (trimmed from the verified live payload: `temperatureMax 36.23`, `temperatureMin 23.41`, `precipitationChance 0.52`, `conditionCode Thunderstorms`, `daytimeForecast.humidity 0.51`, peak `temperatureApparent 37.9`)
  - [x] TDD: `tests/Feature/Weather/WeatherKitProviderTest.php` — asserts `highF === 97`, `precipChance === 52`, `humidity === 51`, `conditionText === 'Thunderstorms'`, `feelsLikeHighF === 100`, `isLimited() === false`; asserts bearer header + `timezone` query param sent; asserts `WeatherProviderFailedException` on a 401

- [x] **Task 5: Bind the provider by config flag (AC: 1, 6)** — plan Task 7
  - [x] `app/Providers/AppServiceProvider.php`: make the `WeatherProvider` binding flag-aware — build `WeatherKitProvider` (with a `WeatherKitToken` from `config('services.weatherkit')` reading the `.p8` via `base_path`, and `DestinationTimezone`) when `provider === 'weatherkit'`, else `WeatherApiProvider`
  - [x] Commit a **throwaway** `tests/Fixtures/weatherkit/throwaway.p8` (`git add -f`; not a real key) for the binding test
  - [x] TDD: `tests/Feature/Weather/ProviderBindingTest.php` — flag `weatherkit` → `WeatherKitProvider`; default → `WeatherApiProvider`; then `php artisan test --compact` full suite green on the default flag

- [x] **Task 6: Verification gates (AC: 7)**
  - [x] `php artisan test --compact` · `vendor/bin/pint --dirty --format agent` · `./vendor/bin/phpstan analyse` · `npm run types:check` · `npm run lint:check` · `npm run build:ssr`

> Note: `DestinationTimezone` (the resolver injected into `WeatherKitProvider` and referenced in Task 5) is fully built in **Story 11.2**. To keep 11.1 self-contained and testable, create a minimal `DestinationTimezone` with the `resolve(float,float): ?string` signature here (Google Time Zone API + cache; return null on failure) — 11.2 extends it and adds the `trips.destination_timezone` persistence. If 11.1 and 11.2 are built together, fold the resolver into 11.2's first task and inject it here.

### Review Findings

_Adversarial review 2026-07-04 (Blind Hunter + Edge Case Hunter + Acceptance Auditor). Outcome: Changes Requested → resolved. 1 decision (deferred to 11.2), 9 patch (all applied), 1 additional defer (resolve-per-send → 11.2) — **2 deferrals total** — 6 dismissed. Gates after fixes: 579/579 tests, Pint, PHPStan 0._

- [x] [Review][Decision→Defer] Hardcoded `America/New_York` fallback is a US-wide landmine — on a tz-resolution failure a far-west/international trip rolls daily highs on ET boundaries (~3h shift) [blind+edge] [WeatherKitProvider.php] — **deferred to Story 11.2 per Clayton**: keep the logged ET fallback; 11.2 resolves+persists `trips.destination_timezone` at trip creation, making the send-path fallback near-impossible. 11.3 cutover must not precede 11.2.
- [x] [Review][Patch] **HIGH** Mapping-phase exceptions escape the port — `CarbonImmutable::parse(...)->setTimezone(...)` and `peakApparentByDate()` run outside the try; a bad `forecastStart`/zone throws a raw Carbon exception, not `WeatherProviderFailedException`, so `SendTripDigest` (catches only that) dies with the `email_logs` row stuck `sending` [edge] [WeatherKitProvider.php:363-435]
- [x] [Review][Patch] Empty-string fallback zone defeats the `??` chain — `??` only guards null; `TRIPCAST_FALLBACK_TIMEZONE=''` → `setTimezone('')` throws [edge] [WeatherKitProvider.php:358-360]
- [x] [Review][Patch] Blank/whitespace `conditionCode` → `''` (not null) escapes the FR-7 limited marker — day renders "complete" with empty condition text [edge] [WeatherKitProvider.php:405]
- [x] [Review][Patch] Forecast horizon not honored — no `dailyStart/dailyEnd`, maps ~10 days vs the WeatherAPI path's `horizon_days + 1`; snapshot day-count diverges [auditor] [WeatherKitProvider.php:363-368]
- [x] [Review][Patch] Phoenix guardrail test is synthetic — reuses the ET-aligned fixture, so it proves Carbon math, not a real no-DST payload; feels-like goes silently null in that branch (unasserted) [blind+edge] [WeatherKitProviderTest.php:664]
- [x] [Review][Patch] `ConditionCode::label()` typed `: string` but `preg_replace` can return null [blind] [ConditionCode.php:19]
- [x] [Review][Patch] Bearer test asserts header presence, not the token value/`Bearer ` prefix — wouldn't catch a malformed-auth regression [blind] [WeatherKitProviderTest.php:657]
- [x] [Review][Patch] Binding validates only the `.p8` file, not the three IDs, and mishandles an absolute key path (`base_path` prepend → silent Fake in dev) [blind+edge] [AppServiceProvider.php:184-198]
- [x] [Review][Patch] Coverage gaps — `DestinationTimezone::resolve()` + the config-default fallback branch, and a WeatherKit limited-day (FR-7), are untested [auditor]
- [x] [Review][Defer] Resolve-on-every-send + 11.2-before-11.3 sequencing — WeatherKit resolves Google per send until 11.2 persists `trips.destination_timezone`; deferred to Story 11.2 (which owns the persistence), and 11.3 cutover must not precede it
- Dismissed (6): day dropped on empty `forecastStart` (real WeatherKit always includes it); tz cache-key ~110m granularity; JWT cache key omits team/service (single-tenant); negative-cache doc nit; prod missing-cred → `RuntimeException` (matches the fail-loud WeatherAPI binding pattern); tautological config credential assertion (real wiring covered by the binding test)

### Review Findings — Round 2 (cross-model, Fable 5)

_Re-review 2026-07-04 on the post-fix code (Blind + Edge + Acceptance, all Fable 5). Verified all 9 round-1 fixes correct + 579/579. Live payload **refuted** the "multi-day feels-like null" HIGH — the real REST `forecastHourly` default returns ~250 hours over 11 days, so feels-like is populated across the horizon. Found net-new robustness issues; all applied. Gates: 583/583, Pint, PHPStan 0._

- [x] [Review][Patch] Unguarded `resolve()` — a Cache/Redis (predis) failure escaped `fetchForecast` raw (the zone resolves *before* the try; the inner try guarded only HTTP), stranding the send like the round-1 HIGH. Wrapped the whole `Cache::remember` in try→null+log [edge] [DestinationTimezone.php]
- [x] [Review][Patch] Round-1 HIGH fix over-caught — a malformed/blank hourly `forecastStart` aborted the whole send, but feels-like is optional enrichment (FR-7). `peakApparentByDate` now guards blank (`Carbon::parse('')` = now) and skips malformed hours [edge+blind] [WeatherKitProvider.php]
- [x] [Review][Patch] Binding used `is_file` not `is_readable`, and `file_get_contents === false` was unhandled (Forge release-perms/TOCTOU) — read the key at bind time; unreadable → unconfigured [edge+blind] [AppServiceProvider.php]
- [x] [Review][Patch] Empty `days: []` rendered zero rows (inherited WeatherAPI's deferred bug) → now throws `WeatherProviderFailedException` [edge] [WeatherKitProvider.php]
- [x] [Review][Patch] Slice trusted Apple's ordering → sort by date before slicing [edge] [WeatherKitProvider.php]
- [x] [Review][Patch] Coverage — added multi-day slice (count + ordering + multi-day feels-like), config-default timezone fallback, skip-malformed-hour, and binding-unconfigured tests [blind+edge+auditor]
- Dismissed (3): window-pinning (live payload confirms Apple's ~10-day default covers the horizon; graceful null-degradation if it ever shrank); midnight fall-back DST duplicate dates (exotic zones); `bind()` re-reads the `.p8` per resolve (operational)

## Dev Notes

### Critical guardrails (read first)

- **The bug is upstream; do not "correct" the number in our code.** The rendered high must be WeatherKit's `temperatureMax` (air temp), converted °C→°F. Never derive the high from `temperatureApparent`/heat-index — that reintroduces the exact class of bug we are removing. Live truth: `36.23°C → 97°F`.
- **Adapter-only swap (AD-1).** `WeatherKitProvider` is the *only* place WeatherKit's HTTP/JSON contract may appear (mirrors the `WeatherApiProvider` docblock). Do not leak WeatherKit shapes into `Forecast`/`ForecastDay`, the snapshot, `ForecastRows`, or templates.
- **Frozen response contract.** `Forecast`/`ForecastDay` fields and `email_logs.weather_snapshot` serialization are unchanged. The single allowed port change is the additive `?string $timezone = null`. Because it defaults to null, existing callers (`SendTripDigest`, `SampleForecast`) compile unchanged — but every *implementer* (`WeatherApiProvider`, `FakeWeatherProvider`, `WeatherKitProvider`) must declare the third param or PHP fatals on an incompatible signature.
- **The `id` header is the #1 failure mode.** WeatherKit rejects a JWT that lacks the non-standard `id` header (`<team>.<service>`). `firebase/php-jwt`'s 5th `JWT::encode` arg injects it; most libraries won't add it for you.
- **Units: metric only, no unit param.** WeatherKit returns °C, mm, km/h, and 0–1 decimals; `metadata.units` reports `"m"`. Convert in the adapter. WeatherAPI handed us both units for free — this is the real behavioral difference.
- **Humidity is on the day-part.** `days[].daytimeForecast.humidity` (0–1), **absent** at the day root — confirmed on the live payload. Fallback (if ever absent) = hourly-average humidity; humidity is optional enrichment (never limits a day).
- **Ships dark.** Keep the default flag `weatherapi`; the whole story is inert in production until 11.3 flips it. Do not change `.env`'s live provider in this story.
- **Secrets.** Credentials come only from env; `APPLE_WEATHERKIT_PRIVATE_KEY` is a **path** (`*.p8` git-ignored). Never log or echo the key or a minted token.

### Existing patterns to copy (file:line)

| What | Where |
| --- | --- |
| Adapter shape, error mapping, `peakFeelsLike` hourly scan | app/Services/Weather/WeatherApiProvider.php (`fetchForecast`, `peakFeelsLike`) |
| The port to implement (add the optional `$timezone`) | app/Services/Weather/WeatherProvider.php |
| DTO fields + `isLimited()` + `toArray`/`fromArray` (do NOT change) | app/Services/Weather/ForecastDay.php, Forecast.php |
| Keyword→emoji map to extend | app/Digest/WeatherEmoji.php (`MAP`) |
| Container binding to make flag-aware | app/Providers/AppServiceProvider.php (existing `WeatherProvider` bind) |
| Provider failure exception | app/Services/Weather/WeatherProviderFailedException.php |
| HTTP + `Http::fake` test style, Forecast/ForecastDay in tests | tests/Feature/Digest/SendTripDigestTest.php |

### Architecture & stack constraints

- Laravel 13 / PHP 8.3, Pest 4. Strict types + return types; constructor property promotion; curly braces always [Source: CLAUDE.md php rules]. `firebase/php-jwt` ^7.1 (already installed — approved dependency; no others).
- **Never cache PHP objects in Redis** [Source: docs/deployment.md]. `WeatherKitToken` caches only the JWT **string** (keyed by `kid`), never an object.
- Endpoint/auth/field details are the contract in `_bmad-output/specs/spec-weatherkit-provider-swap/weatherkit-integration.md` — follow it verbatim (base URL, `dataSets`, `timezone`, ES256 claims, 500k/mo free tier).
- No migration and no schema change in this story (the `trips.destination_timezone` column is Story 11.2).
- **PHPStan (larastan):** dynamic array access on the WeatherKit JSON is `mixed` — narrow before use (`isset(...) ? (float) ... : null`), don't assume shapes [Source: project-context.md].

### What NOT to do

- Do not map the high from `temperatureApparent`, `heatindex`, or any feels-like field.
- Do not change `Forecast`/`ForecastDay`/snapshot/`ForecastRows`/templates, or make the `$timezone` param required.
- Do not flip the production provider flag (that's 11.3) or touch `.env`'s live value.
- Do not add a second dependency; do not hand-roll ES256 (the DER→JOSE signature footgun) — use `firebase/php-jwt`.
- Do not fabricate values to avoid a "limited" day — preserve FR-7.

### Previous story intelligence

- Story 2.1 built the `WeatherProvider` port + `WeatherApiProvider` (fetch-by-coordinates, both units, `peakFeelsLike`). This story adds a second adapter behind the same port and generalizes selection — reuse its structure and error semantics rather than inventing new ones.
- Epic 10 precedent (Stories 10.1/10.2): a new epic is registered via the story file + `sprint-status.yaml`, not by regenerating `epics.md`. Followed here for Epic 11.
- The timezone-aware-send-time plan (`docs/superpowers/plans/2026-07-03-timezone-aware-send-time.md`) is coordinated: its Task 3 (add `destination_timezone`) and Task 4 (capture from WeatherAPI `location.tz_id`) are **superseded** by Story 11.2 (capture at creation via Google) — WeatherKit returns no `tz_id`.

### Testing standards

- Pest 4 feature/unit tests, `RefreshDatabase` where the DB is touched (not needed for the pure adapter/token/condition tests). `Http::fake()` **before** acting; never hit Apple/Google. Assert conversions with exact ints (97, 52, 51, 100) against the fixture built from the verified live payload.
- The `WeatherProviderFailedException`-on-401 test and the `highF === 97` assertion are load-bearing — a failure there is a real defect (auth or the temperature bug), not a test to relax.
- Full suite must stay green on the **default** flag (`weatherapi`) — proof the swap is inert until cutover.

### Project Structure Notes

- New files: `app/Services/Weather/WeatherKit/{WeatherKitToken,WeatherKitProvider,ConditionCode}.php`, `app/Services/Weather/DestinationTimezone.php` (minimal here; extended in 11.2), `tests/Fixtures/weatherkit/{kennett.json,throwaway.p8}`, and the unit/feature tests above. Modified: `config/services.php`, `config/tripcast.php`, `.env.example`, `app/Services/Weather/WeatherProvider.php`, `WeatherApiProvider.php`, `FakeWeatherProvider.php`, `app/Digest/WeatherEmoji.php`, `app/Providers/AppServiceProvider.php`.

## Dev Agent Record

### Completion Notes

- **All 9 ACs satisfied; all tasks complete.** Provider swap is live behind the flag (default `weatherapi`) — full suite green with zero behavior change. CAP-2 proven in the fixture test: `36.23°C → 97°F` (not 105).
- **Deviations from the plan's suggested test locations (project convention):** `tests/Pest.php` binds the Laravel `TestCase` to `Feature/` only, so anything using `config()`/`Cache`/`Http` must live in `Feature`. Moved `WeatherKitConfigTest` and `WeatherKitTokenTest` to `tests/Feature/Weather/`; kept the pure `ConditionCodeTest` in `tests/Unit/Weather/`.
- **Temps stored as raw floats, not pre-rounded** (matches `WeatherApiProvider`): the renderer rounds for display and `NarrationDiffer` compares stored values. `toF()` returns a float; only the integer percentages (precip, humidity) are rounded in the adapter.
- **Added a Phoenix / no-DST guardrail test** (Clayton's question during the run): proves the local date is derived from the passed IANA zone (tzdata DST rules applied), not a fixed offset. Verified live that Google returns `America/Phoenix` (constant −07:00) year-round and Carbon honors it — so no-DST cities are correct, no 1-hour error. The only zone risk is unrelated to DST: the `America/New_York` fallback (on a Google resolution failure) would be geographically wrong for a far-west trip — rare path, non-systematic max-temp error; revisit fallback quality in 11.2 if desired.
- **DestinationTimezone is intentionally minimal** here (resolve + cache + null-on-failure). Story 11.2 adds the `trips.destination_timezone` column, the CreateTrip persistence, and the resolver's dedicated test suite.
- **Ships dark:** production flag stays `weatherapi` until Story 11.3 cutover. No `.env` live value changed.

### File List

- **New:** `app/Services/Weather/WeatherKit/WeatherKitToken.php`, `app/Services/Weather/WeatherKit/ConditionCode.php`, `app/Services/Weather/WeatherKit/WeatherKitProvider.php`, `app/Services/Weather/DestinationTimezone.php`, `tests/Feature/Weather/WeatherKitConfigTest.php`, `tests/Feature/Weather/WeatherKitTokenTest.php`, `tests/Unit/Weather/ConditionCodeTest.php`, `tests/Feature/Weather/WeatherKitProviderTest.php`, `tests/Feature/Weather/ProviderBindingTest.php`, `tests/Fixtures/weatherkit/kennett.json`, `tests/Fixtures/weatherkit/throwaway.p8`
- **Modified:** `config/services.php`, `config/tripcast.php`, `.env.example`, `app/Services/Weather/WeatherProvider.php`, `app/Services/Weather/WeatherApiProvider.php`, `app/Services/Weather/FakeWeatherProvider.php`, `app/Digest/WeatherEmoji.php`, `app/Providers/AppServiceProvider.php`

### Change Log

- 2026-07-04 — Implemented Story 11.1 (WeatherKit provider behind the port). New `WeatherKitProvider` (ES256 JWT via `WeatherKitToken`, metric→imperial, `conditionCode`→label, feels-like peak), flag-based binding, minimal `DestinationTimezone`. Added a Phoenix/no-DST guardrail. Gates: `php artisan test` 573/573, Pint clean, PHPStan 0 errors, types/lint/build:ssr clean. Status → review.
- 2026-07-04 — Addressed adversarial code review (3 layers): fixed 9 patches incl. the HIGH (mapping-phase exceptions now wrap as `WeatherProviderFailedException` via a `mapDays` helper), empty-string zone guard (`?:` + UTC), blank `conditionCode`→null (FR-7), horizon slice to `horizon_days + 1`, binding hardening (absolute path + all-4-IDs), `ConditionCode` null-return guard, stronger bearer assertion, honest Phoenix fixture, and limited-day + resolver-fallback + malformed-payload coverage. The ET-fallback decision deferred to 11.2. Gates: 579/579 tests, Pint, PHPStan 0. Status → done.
- 2026-07-04 — Cross-model re-review (Fable 5, 3 layers) on the post-fix code: verified all 9 fixes; live payload refuted a "multi-day feels-like null" HIGH. Applied 6 net-new robustness fixes — guarded `resolve()` against cache/Redis failures (was escaping the port like the round-1 HIGH), made `peakApparentByDate` skip malformed/blank hours instead of aborting the send, binding `is_readable` + `file_get_contents` guard, throw on empty `days`, sort-before-slice, and 4 coverage tests. Gates: 583/583 tests, Pint, PHPStan 0. Status stays done.
