---
baseline_commit: f5d93e1
---

# Story 9.5: Sample digest shows the full 7-day forecast

Status: review

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

As a curious visitor,
I want the sample email to show a whole week of forecast,
so that I see the product at full strength, not a two-day sliver.

## Acceptance Criteria

**AC1 — Seven forecast days render from the cached live fetch** *(FR-25, AD-1, AD-7)*
- **Given** a sample request
- **When** the sample digest is built
- **Then** the demo trip window is sized so **seven forecast days render** (today `SampleController` windows tomorrow..tomorrow+1), all rows from the cached live fetch.

**AC2 — The synthetic fallback spans the same full window** *(FR-25)*
- **Given** a provider outage on a cold cache
- **When** the fallback forecast is used
- **Then** the synthetic fallback spans the same full window (today it covers only four days in `SampleForecast`).

## Tasks / Subtasks

- [x] **Task 1 — Resize the demo trip window** (AC: 1)
  - [x] `app/Http/Controllers/SampleController.php::sampleTrip()` (lines 57–73, window at 66–67): `departure_date` stays `$today->addDay()` (tomorrow); `return_date` becomes **`$today->addDays(7)`** (tomorrow+6). Why this exact reach: the live fetch requests `horizon_days + 1 = 8` days (`WeatherApiProvider.php:28`, today..today+7 — day 1 is today), and `ForecastRows::project()` clips to `[departure, return]` — so tomorrow..today+7 intersects to **exactly 7 rows**, all from the fetch, none limited. Do not go wider — an 8-day window's last day falls beyond the fetch and **silently drops from the render** (`ForecastRows` omits days outside the snapshot; the sample template has no beyond-horizon line).
  - [x] Update the `sampleTrip()` docblock — it currently says "windowed tomorrow..tomorrow+1 so the configured forecast horizon (8+ days) fully covers it"; rewrite to name the 7-row intent (FR-25: the sample shows the product at full strength) and the horizon math. This consciously reverses Story 6.4's short-window choice ("fits the live reach" — the original constraint was the WeatherAPI **free** tier's ~3-day forecast; the configured plan **must** serve the full 8-day request — the daily digest assumes it too, but this is **unvalidated in production**, see the verification bullet in Dev Notes).
- [x] **Task 2 — Full-window synthetic fallback** (AC: 2)
  - [x] `app/Services/Sample/SampleForecast.php::fallback()` (lines ~43–67): extend the loop from `$offset <= 3` to **`$offset <= 7`** (today..today+7 — the same shape as the live 8-day fetch, so the clip yields the same 7 rows). Keep the calm identical baked-in values; optionally vary nothing (the fallback is a graceful stand-in, not a showcase).
  - [x] Update its docblock ("spanning today..today+3 so it always covers the sample trip window (tomorrow..tomorrow+1)") to the new window. Note: 8 fallback days also keeps `Forecast::isLimited()` false (< 7 days would flag limited).
- [x] **Task 3 — Tests** (AC: 1, 2)
  - [x] `tests/Feature/Sample/SampleEndpointTest.php` (extend): assert the queued `SampleDigestMail`'s trip is windowed **tomorrow..tomorrow+6** — under the file's `beforeEach` clock (2026-06-30 09:00 ET) that is departure `2026-07-01`, return `2026-07-07` (`Mail::assertQueued(fn (SampleDigestMail $mail) => $mail->trip->departure_date->toDateString() === '2026-07-01' && $mail->trip->return_date->toDateString() === '2026-07-07')` — date casts work on the unsaved Trip). **Do not** add the assertion to the existing TTL test — it re-pins the clock mid-file.
  - [x] `tests/Feature/Sample/SampleForecastTest.php` (extend): on provider failure, the fallback `Forecast` has **8 days** spanning today..today+7 (assert first/last dates on the pinned clock) and `isLimited()` is false.
  - [x] `tests/Feature/Sample/SampleDigestMailTest.php` (update fixtures + extend): widen `sampleTrip()` to a 7-day window (e.g. 2026-07-01..2026-07-07 on the 2026-06-30 pinned clock) and `sampleSnapshot()` to 7 matching days; add an assertion that **seven day-rows render** — copy `DigestMailTest`'s row-count pattern (`expect(substr_count($mail->render(), '% precip'))->toBe(7)`). Existing assertions (CTA, footer links, no-unsubscribe guard) must stay green.
  - [x] **Gates:** `php artisan test --compact`, `vendor/bin/pint --dirty --format agent`, `./vendor/bin/phpstan analyse`. (No frontend changes — no build needed, but `npm run build:ssr` is cheap; skip unless files changed.)

## Dev Notes

### Scope boundary (read first)
- **In scope:** the two window sizes + docblocks + tests. **Out of scope:** the sample pipeline itself (throttle, magic link, `sample_requests` — Story 6.4, done), the WeatherAPI plan/horizon config (`tripcast.forecast.horizon_days` stays 7 — it drives the *real* digest fetch too; do NOT touch it for this story), the sample template (already renders whatever rows project), caching strategy (key/TTL unchanged — the day-keyed cache means the first request after midnight refetches). [Source: epics.md#Story-9.5 lines 711–725; SampleForecast.php]

### Architecture / window math (binding)
- **The one clip that decides row count:** `ForecastRows::project()` filters snapshot days to `[departure_date, return_date]` (`ForecastRows.php:22–25`). Rows = intersection of the trip window with the fetched days. Live fetch = `days: horizon_days + 1 = 8` → today..today+7 (`WeatherApiProvider.php:24–31`; `config/tripcast.php:87` horizon 7). **tomorrow..today+7 ∩ today..today+7 = 7 days.** [Source: app/Digest/ForecastRows.php; app/Services/Weather/WeatherApiProvider.php]
- **AD-7:** the window is computed on `CarbonImmutable::now('America/New_York')` (`SampleController.php:59`) — keep it; tests pin this clock.
- **Fallback contract:** same shape as the live fetch (today..today+7) so outage behavior is indistinguishable in the rendered email; not cached (retried live next call) — that behavior stays. [Source: app/Services/Sample/SampleForecast.php:43–67]
- **Verification (live plan reach — no test can catch this):** the fake provider keeps every test green regardless of the real plan; confirm the live WeatherAPI key actually returns 8 `forecast.forecastday` entries for `days=8` (one curl with the real key, or the WeatherAPI dashboard). If it returns fewer, seven rows will NOT render in production — report it to the user; either way this check belongs on Story 9.6's env checklist.
- **`FakeWeatherProvider` returns exactly 7 days with fixed dates 2026-07-01..07** (`FakeWeatherProvider.php:24`) — fine for the endpoint tests pinned to 2026-06-30 (trip 07-01..07-07 ∩ those 7 days = 7 rows); don't modify the fake.

### Code intel
- The current 2-day window was sized for the WeatherAPI **free** tier (~3-day forecast — see `PreviewDigests.php:20–28` docblock); the docblock in `SampleController` was already softened in commit `355a246` to "configured forecast horizon (8+ days) fully covers it" but the window itself was never widened — this story finishes that thought. [Source: app/Console/Commands/PreviewDigests.php; git 355a246]
- **No existing test asserts the current window or fallback length** — fixtures hard-code a 3-day trip for convenience only; nothing breaks by widening, but the three new/updated assertions above are what pins FR-25. [Source: tests/Feature/Sample/* recon 2026-07-01]
- `SampleDigestMail` is queued with the in-memory trip (`SampleController.php:39`) — `Mail::assertQueued` can inspect `$mail->trip` directly (public property).

### Testing standards
- Pest, pinned ET clocks per file (`SampleEndpointTest` uses its own `Carbon::setTestNow` — check the pinned date before hard-coding expected window dates). Row-count via `substr_count($mail->render(), '% precip')` — the established `DigestMailTest.php:300` pattern. [Source: tests/Feature/Digest/DigestMailTest.php:300]

### Project Structure Notes
- **Modified only:** `app/Http/Controllers/SampleController.php`, `app/Services/Sample/SampleForecast.php`, `tests/Feature/Sample/SampleEndpointTest.php`, `SampleForecastTest.php`, `SampleDigestMailTest.php`. No new files.

### Previous story intelligence (9.1–9.4)
- 9.1 added the legal footer to the sample email — `SampleDigestMailTest` now has footer-link assertions plus the no-unsubscribe guard (lines ~50–55 + the 9.1 addition); widening the fixture window must keep them green (they don't touch dates).
- The sample digest screenshot on the landing page (9.2, `public/images/digest-sample.png`) is a *digest* render, not the sample email — unaffected.
- Pipeline habit: run the full suite (sample tests interact with throttling — `Cache::flush` in Pest.php resets buckets between tests).

### References
- [Source: _bmad-output/planning-artifacts/epics.md#Story-9.5 (lines 711–725), #FR-25 (line 46)]
- [Source: app/Http/Controllers/SampleController.php:50–73; app/Services/Sample/SampleForecast.php:26–67; app/Digest/ForecastRows.php:20–27; app/Services/Weather/WeatherApiProvider.php:21–31; config/tripcast.php:41–51, :87]
- [Source: tests/Feature/Sample/*; tests/Feature/Digest/DigestMailTest.php:300]

## Dev Agent Record

### Agent Model Used

claude-fable-5

### Debug Log References

- None. Red→green clean: the two new behavior tests failed on the old window/fallback sizes, passed after the two one-line changes.

### Completion Notes List

- **Window (AC1):** `SampleController::sampleTrip()` return_date `addDays(2)` → `addDays(7)` (tomorrow..tomorrow+6 = 7 days, exactly the live fetch's reach minus today); docblock rewritten to name the FR-25 intent and the don't-go-wider silent-drop hazard — consciously reversing 6.4's free-tier-sized window.
- **Fallback (AC2):** `SampleForecast::fallback()` loop `<= 3` → `<= 7` (today..today+7, same shape as the live fetch; `isLimited()` stays false); docblock updated.
- **Tests:** endpoint test pins the queued mail's window (2026-07-01..07-07 on the pinned clock); fallback test pins 8 days spanning today..today+7 + not-limited; `SampleDigestMailTest` fixtures widened to the 7-day window with a 7-day varied snapshot + a seven-day-rows render assertion; all existing sample assertions (CTA, footer links, no-unsubscribe guard) stayed green.
- **Live plan verification (the check no test can make):** curled the real WeatherAPI key with `days=8` for the Reykjavik coordinates — **8 `forecastday` entries returned** (2026-07-02..07-09), so the configured plan serves the full request and seven rows will render in production. Noted for Story 9.6's env checklist.
- **Verification:** sample suite 19 passed; full suite **338 passed** (1144 assertions); pint clean; phpstan 0 errors. No frontend changes.

### File List

**Modified:**
- `app/Http/Controllers/SampleController.php` (window + docblock)
- `app/Services/Sample/SampleForecast.php` (fallback span + docblock)
- `tests/Feature/Sample/SampleEndpointTest.php`, `SampleForecastTest.php`, `SampleDigestMailTest.php` (new assertions + widened fixtures)

### Change Log

- 2026-07-01 — Implemented Story 9.5: sample demo trip windowed tomorrow..tomorrow+6 so the sample email renders the full 7-day forecast from the cached live fetch; synthetic outage fallback widened to the same reach. Live WeatherAPI plan verified to serve the 8-day request. 3 new/updated tests; full suite 338 passed.
