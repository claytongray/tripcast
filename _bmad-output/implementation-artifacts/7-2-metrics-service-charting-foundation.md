---
baseline_commit: 26251b5
---

# Story 7.2: Metrics service + charting foundation

Status: review

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

As a developer,
I want one aggregation service and reusable chart/tile components,
so that every admin section computes metrics efficiently and renders consistently.

## Acceptance Criteria

**AC1 — `MetricsService` returns tile/series-shaped aggregates over a window** *(FR-22)*
- **Given** a metrics request over a window (**7 / 30 / 90 days**, app tz)
- **When** `MetricsService` computes date-bucketed aggregates
- **Then** it returns **series-shaped** arrays (one `{ date, count }` bucket per day in the window, ascending) and **tile-shaped** arrays (`{ value, previous, delta, delta_pct }`) using **grouped queries** (no N+1, no unbounded scans — every query is bounded by the window's date range and aggregated in SQL), and **empty ranges return safe zero-filled buckets** (a day with no rows is `0`, never missing).

**AC2 — Reusable charting foundation** *(FR-22)*
- **Given** the frontend needs trend graphs
- **When** the charting foundation is added
- **Then** `vue-chartjs` + `chart.js` are installed and wrapped in reusable **`KpiTile`** (number + delta + sparkline) and **`TrendChart`** (simple full-width, mobile-legible line chart) components, styled with the design tokens, ready for Story 7.3+ to consume.

## Tasks / Subtasks

- [x] **Task 1 — `MetricsWindow` value object** (AC: 1)
  - [x] `app/Services/Metrics/MetricsWindow.php` — `final readonly` with `days`, `start`, `end`, `previousStart`, `previousEnd` (CarbonImmutable).
  - [x] `dates(): list<string>` returns the ordered `Y-m-d` zero-fill spine for `[start, end]`.
- [x] **Task 2 — `MetricsService` aggregation primitives** (AC: 1)
  - [x] `app/Services/Metrics/MetricsService.php`, methods take builders so callers scope their own model.
  - [x] `resolveWindow(int $days)` — allowlist `{7,30,90}` (`const ALLOWED_WINDOWS`), throws `InvalidArgumentException` otherwise; anchors on `CarbonImmutable::now(config('app.timezone'))` with the equal-length prior period.
  - [x] `dailyCountsByDate` (date columns) and `dailyCountsByTimestamp` (`DATE(col)` bucketing) — each one grouped query, then zero-filled ascending.
  - [x] `count(...)` bounded `COUNT(*)`, and `tile(current, previous)` → `{value, previous, delta, delta_pct}` (null pct when previous 0).
  - [x] Private `zeroFill()` projects buckets onto `$window->dates()`.
  - [x] **Injection safety:** the raw-expression column params are constrained to union-literal PHPDoc types (`'send_date'`, `'created_at'|'email_verified_at'`) — provably injection-proof *and* preserves larastan's `literal-string` through interpolation (no ignores/casts).
- [x] **Task 3 — Install the charting libraries** (AC: 2)
  - [x] `npm install chart.js@^4 vue-chartjs@^5` (user-approved); `package.json`/`package-lock.json` updated.
  - [x] `resources/js/lib/chart.ts` registers only `CategoryScale/LinearScale/LineElement/PointElement/Filler/Tooltip` (no `registerables`), imported for side effects.
- [x] **Task 4 — `KpiTile.vue`** (AC: 2)
  - [x] `resources/js/components/admin/KpiTile.vue` — typed props `label/value/delta?/series?`; big value + colored delta chip (positive/destructive/"—") + a chrome-free `Line` sparkline (rendered only when `series` non-empty). Bordered card look reused.
- [x] **Task 5 — `TrendChart.vue`** (AC: 2)
  - [x] `resources/js/components/admin/TrendChart.vue` — typed props `title/labels/series[]`; responsive `maintainAspectRatio:false` `Line` in an `h-56` wrapper, capped ticks, brand palette, legend only for >1 series. Purely presentational.
- [x] **Task 6 — TS contracts for metric shapes** (AC: 1, 2)
  - [x] `resources/js/types/metrics.ts` — `MetricPoint`, `MetricSeries`, `KpiTileData`, `TrendSeries` mirroring the service output (no barrel exists; standalone module).
- [x] **Task 7 — Tests** (AC: 1, 2)
  - [x] `tests/Feature/Admin/MetricsServiceTest.php` (Pest, `RefreshDatabase`, `travelTo` to pin now): window resolution (7/30/90 bounds + `dates()` length/order; `45/1/0/-7` throw), `dailyCountsByTimestamp` (zero-fill + out-of-window exclusion + exact series), `dailyCountsByDate` on `email_logs.send_date`, all-zero empty range, `tile` deltas incl. null baseline & decline, and a one-grouped-query (no N+1) assertion via the query log. **14 tests, all pass.**
  - [x] Frontend components guarded by `types:check` (vue-tsc checks all `.vue`) + `build:ssr`; first on-page render/browser smoke deferred to Story 7.3 where they're mounted (noted below).
  - [x] **Gates all green:** pest 334 passed, pint clean, phpstan 0 errors, types:check clean, lint:check clean, build:ssr built.

## Dev Notes

### Scope boundary (read first)
- **Foundation only.** Deliver the reusable `MetricsService` primitives + `MetricsWindow`, the two chart components, and the TS contracts. Do **NOT** build any section's concrete metrics or wire anything onto a page — Overview (7.3), Users (7.4), Emails (7.5), Promos (7.6), Samples (7.7) each call these primitives with their own columns/queries. The chart components stay **unused** on any route until 7.3; that's expected for a foundation story.
- **Read-only** (Epic-7 cross-cutting AC): `MetricsService` only reads/aggregates — no writes. **No migrations** (all tables/columns already exist).

### Architecture (binding)
- **FR-22 (this story = the shared computation + rendering layer):** "one aggregation service and reusable chart/tile components." [Source: _bmad-output/planning-artifacts/epics.md#Story-7.2]
- **Cross-cutting Epic-7 ACs:** phone-first (charts simple, full-width, mobile-legible), read-only, and everything ultimately behind the `admin` Gate (the components/service are Gate-agnostic; the routes that use them, 7.3+, are guarded). [Source: epics.md#Epic-7]
- **Windows are 7/30/90 only** — the allowlist is the "no unbounded scans" guard; arbitrary N is rejected, not clamped. [Source: epics.md#Story-7.2 AC]

### Data model (exact columns to aggregate)
- **`users`** — `created_at` (signups/day), `email_verified_at` nullable (confirmations/day, confirmation rate). [Source: app/Models/User.php; migrations]
- **`trips`** — `created_at` (trips created/day), `status` (`active|paused|completed`), `SoftDeletes`. [Source: app/Models/Trip.php]
- **`email_logs`** — `send_date` (**`date`** column — use `dailyCountsByDate`), `status` (`sending|sent|failed`), `failure_reason`. **No factory** — seed via `$trip->emailLogs()->create([...])`. [Source: app/Models/EmailLog.php; database/migrations/2026_06_29_000003_create_email_logs_table.php]
- **`promo_events`** — `send_date` (**`date`**), `event` (`impression|click`), `user_id`, `trip_id`, `promo_slug`. Seed via `DB::table('promo_events')->insert([...])`. [Source: database/migrations/2026_06_30_000001_create_promo_events_table.php; app/Models/PromoEvent.php]
- **`sample_requests`** — `created_at` (sample requests/day), `user_id`, `email`, `destination`. [Source: database/migrations/2026_06_30_000001_create_sample_requests_table.php; app/Models/SampleRequest.php]
- **App timezone is `UTC`** (`config('app.timezone')`), so `DATE(created_at)` buckets on the true calendar day with no offset. Bucket **date** columns (`send_date`) directly; bucket **timestamp** columns via `DATE(...)`. [Source: config/app.php; php artisan config:show app.timezone]

### Code intel (patterns to match)
- **Service placement/style:** domain services live under `app/Services/<Domain>/` (see `app/Services/Promo`, `app/Services/Sample`); use `app/Services/Metrics/`. Match the codebase style — `final` classes, constructor property promotion where injected, explicit return types, array-shape PHPDoc (`@return list<array{date: string, count: int}>`). [Source: app/Services/Promo/AffiliatePromoProvider.php; CLAUDE.md PHP rules]
- **`now()` is `CarbonImmutable`** globally (`Date::use(CarbonImmutable::class)` in `AppServiceProvider@boot`). Use `now(config('app.timezone'))`; in tests pin with `Carbon::setTestNow()`. Follow `PurgeForecastHistory`'s date-math style (`->subDays()`, `->toDateString()`). [Source: app/Providers/AppServiceProvider.php:91; app/Actions/PurgeForecastHistory.php]
- **Grouped-aggregate query style:** use the query builder with `selectRaw`/`groupBy` and a single `->get()`; do not fetch rows and count in PHP (that's the N+1/unbounded-scan trap the AC forbids). [Source: epics.md#Story-7.2]
- **Frontend components:** shared components live flat in `resources/js/components/*.vue`, primitives in `components/ui/`. Group the Epic-7 chart components under a new `resources/js/components/admin/` subfolder (a subfolder, not a new base dir). Reuse the token classes already used across `Admin/*` pages (`text-ink`, `text-ink-secondary`, `text-title`, `text-meta`, `border-hairline`, `bg-surface-raised`, `text-positive`, `text-destructive`, `text-brand`). [Source: resources/js/components; resources/js/pages/Admin/Monitoring.vue]
- **TS types** live in `resources/js/types/` (e.g. `auth.ts`, `global.d.ts`). Add `metrics.ts` alongside; check for a barrel/`index.ts` before deciding how to export. [Source: resources/js/types/]

### Charting specifics (latest, verify on install)
- Install `chart.js` (^4) + `vue-chartjs` (^5). `vue-chartjs` v5 exposes ready components (`Line`, `Bar`, …) that wrap a `<canvas>`; you must `Chart.register(...)` the controllers/elements/scales you use (v4 is tree-shakeable — nothing auto-registers). Register once in `resources/js/lib/chart.ts` and import it for side effects. [Verify exact export names against the installed version.]
- **SSR safety (important, but not exercised until 7.3):** `chart.js` touches `window`/`canvas`, which don't exist during Inertia SSR. In 7.2 the components aren't mounted on any page, so `build:ssr` won't execute them. When 7.3 first mounts them, ensure they render client-side only (vue-chartjs renders the `<canvas>` element on the server but only *draws* on the client — generally safe; confirm no chart.js code runs at module top-level on the server). Flag this as a 7.3 verification item.
- Keep charts **calm and legible on a phone:** `responsive: true`, `maintainAspectRatio: false` in a fixed-height wrapper; minimal ticks; hide the legend for single-series; sparklines strip all chrome (`scales.x/y.display = false`, `plugins.legend/tooltip = false`, small `borderWidth`, no points).

### Testing standards
- Pest + `RefreshDatabase` (global via `tests/Pest.php`). Pin time with `Carbon::setTestNow(CarbonImmutable::parse('2026-07-01 12:00:00', 'UTC'))` so window bounds and seeded dates are deterministic; clear it in an `afterEach` if not auto-reset. Seed `users` via factory with explicit `created_at`; `email_logs` via `$trip->emailLogs()->create([...])`; `promo_events` via `DB::table(...)->insert([...])`. Assert exact series arrays for small windows (length 7) and use a query-log assertion for the "one grouped query" guarantee. [Source: tests/Pest.php; tests/Feature/Admin/AdminViewTest.php; app/Models/EmailLog.php]
- The two Vue components are typechecked by `vue-tsc` (`npm run types:check`) even though unused; their runtime/visual verification is intentionally deferred to Story 7.3's Overview page (first real mount + browser smoke).

### Project Structure Notes
- **New:** `app/Services/Metrics/MetricsService.php`, `app/Services/Metrics/MetricsWindow.php`, `resources/js/lib/chart.ts`, `resources/js/components/admin/KpiTile.vue`, `resources/js/components/admin/TrendChart.vue`, `resources/js/types/metrics.ts`, `tests/Feature/Admin/MetricsServiceTest.php`.
- **Modified:** `package.json` + `package-lock.json` (chart.js, vue-chartjs).
- **Unchanged:** routes, controllers, existing pages/layouts (7.1's shell is untouched — 7.3 wires these in). **No migrations.**

### Previous story intelligence (7.1 — admin shell)
- 7.1 built the guarded `/admin` group, `AdminLayout` (phone-first tab nav), the `Admin/*` page folder, and placeholder Overview/Users/Emails/Promos/Samples pages. 7.2 adds no routes/pages — it hands 7.3 the tools to fill Overview. Wayfinder regenerates on build (gitignored). Keep everything read-only. Note: 7.1 may be uncommitted in the working tree when this story runs; that's fine — 7.2 doesn't touch 7.1's files. [Source: _bmad-output/implementation-artifacts/7-1-admin-shell-tab-nav-route-group.md]

### References
- [Source: _bmad-output/planning-artifacts/epics.md#Story-7.2] (ACs)
- [Source: _bmad-output/planning-artifacts/epics.md#Epic-7] (cross-cutting ACs, FR-22)
- [Source: app/Actions/PurgeForecastHistory.php] (CarbonImmutable date-math + grouped-query style)
- [Source: app/Providers/AppServiceProvider.php:91] (CarbonImmutable global)
- [Source: config/app.php] (app timezone = UTC)
- [Source: database/migrations/2026_06_29_000003_create_email_logs_table.php; 2026_06_30_000001_create_promo_events_table.php; 2026_06_30_000001_create_sample_requests_table.php] (aggregation columns)
- [Source: resources/js/components; resources/js/types] (component + type conventions)

## Dev Agent Record

### Agent Model Used

claude-opus-4-8 (1M context)

### Debug Log References

- **PHPStan `literal-string` on `selectRaw`/`groupByRaw`** (larastan's SQL-injection guard): interpolating a `string` column into a raw expression yields a non-literal string. Fixed at the cause by narrowing the raw-expression column params to union-literal PHPDoc types (`'send_date'`, `'created_at'|'email_verified_at'`) — this both makes the API provably injection-safe and lets PHPStan preserve `literal-string` through interpolation. No ignores, casts, or baseline entries.

### Completion Notes List

- **MetricsService (AC1):** window allowlist `{7,30,90}` is the "no unbounded scans" guard (arbitrary N throws); `dailyCountsBy{Date,Timestamp}` each run a single grouped SQL aggregate and zero-fill against `MetricsWindow::dates()`, so empty days are `0`, never missing. `tile()` returns a stable `{value, previous, delta, delta_pct}` shape with `delta_pct = null` when there's no prior baseline. All read-only; no migrations.
- **Timezone:** app tz is UTC, so `DATE(created_at)` buckets on the true calendar day; date columns (`send_date`) bucket directly. Test DB is MySQL (matches prod), so `DATE()` is safe.
- **Charting (AC2):** `chart.js`@^4 + `vue-chartjs`@^5 installed (the only new deps, user-approved). `lib/chart.ts` registers just the six pieces the components need (no `registerables`). `KpiTile` (number + delta + optional bare sparkline) and `TrendChart` (calm full-width line, 1–2 series) are purely presentational and consume `resources/js/types/metrics.ts` contracts that mirror the PHP output.
- **Component verification is intentionally deferred to Story 7.3:** they're typechecked by `vue-tsc` (all `.vue` files) and the app builds, but they aren't mounted on any route yet, so `build:ssr` doesn't bundle/execute them. First real render + `assertNoJavaScriptErrors()` browser smoke belongs on the Overview page (7.3). **SSR watch-item for 7.3:** chart.js touches `window`/canvas — confirm client-only drawing when first mounted (vue-chartjs renders the `<canvas>` on the server but only draws on the client).
- **Scope held:** no routes/pages/controllers touched (7.1's shell untouched); the foundation is unused until 7.3 wires it into Overview.
- **Verification:** full suite **334 passed / 1119 assertions** (14 new). pint clean, phpstan 0 errors, types:check + lint:check clean, build:ssr built.

### File List

**New:**
- `app/Services/Metrics/MetricsService.php`
- `app/Services/Metrics/MetricsWindow.php`
- `resources/js/lib/chart.ts`
- `resources/js/components/admin/KpiTile.vue`
- `resources/js/components/admin/TrendChart.vue`
- `resources/js/types/metrics.ts`
- `tests/Feature/Admin/MetricsServiceTest.php`

**Modified:**
- `package.json`, `package-lock.json` (chart.js, vue-chartjs)

**Unchanged:** routes, controllers, existing pages/layouts (7.1 shell). No migrations.

### Change Log

- 2026-07-01 — Implemented Story 7.2: metrics service + charting foundation. Added `MetricsService` + `MetricsWindow` (7/30/90 windows, grouped date-bucketed aggregates with zero-fill, tile deltas — read-only, injection-safe via union-literal column types), installed chart.js + vue-chartjs behind a lean registration module, and shipped reusable `KpiTile`/`TrendChart` components plus TS metric contracts. Foundation only — Stories 7.3–7.7 consume it; component render verification is deferred to 7.3. All gates green (334 tests).
