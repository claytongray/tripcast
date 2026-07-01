---
baseline_commit: 26251b5
---

# Story 7.3: Overview dashboard

Status: review

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

As the builder,
I want a single overview of the key signals,
so that I can gauge product health at a glance on my phone.

## Acceptance Criteria

**AC1 — `/admin/overview` shows the key KPIs (each with a sparkline) + trend charts, matching the data** *(FR-22)*
- **Given** an admin opens `/admin/overview`
- **When** it renders
- **Then** KPI tiles show **signups**, **confirmation rate**, **trips created**, **active-trip status mix**, **sends today + success rate**, **promo CTR**, and **sample requests** — each with a sparkline — plus **trend charts** for **signups/day**, **sends & failures/day**, **CTR/day**, and **samples/day**, and every number/series **matches the underlying data**.

**AC2 — Window selection (7/30/90), phone-first, read-only, guarded** *(FR-22, Epic-7 cross-cutting)*
- **Given** the overview
- **When** the admin picks a window (7 / 30 / 90 days, default 30) via a selector
- **Then** all tiles and charts recompute over that window (an invalid/absent `days` param falls back to the default, never errors); the layout **stacks to one column on a phone**, the page is **read-only**, and the route stays behind the `admin` Gate (guest → login, non-admin → 403).

## Tasks / Subtasks

- [x] **Task 1 — `OverviewMetrics` builder** (AC: 1, 2)
  - [x] Create `app/Services/Metrics/OverviewMetrics.php`. Constructor-inject `MetricsService`. One public method `build(MetricsWindow $window): array` returning the full overview payload (shape below). Keep the controller thin — all composition lives here.
  - [x] **Signups** — `dailyCountsByTimestamp(User::query(), 'created_at', $window)`; sparkline = the daily counts; tile current = sum of the series, previous = `count(User::query(), 'created_at', previousStart, previousEnd)`; `tile(current, previous)`.
  - [x] **Confirmation rate** — a *rate* tile: `confirmed / signups` within the window, as a percentage (0 when no signups). Confirmed = users whose `email_verified_at` falls in the window (`dailyCountsByTimestamp(User::whereNotNull('email_verified_at'), 'email_verified_at', $window)`); sparkline = daily confirmations. Delta = **percentage-point** change vs the previous period's rate (null when the previous period had no signups).
  - [x] **Trips created** — `dailyCountsByTimestamp(Trip::query(), 'created_at', $window)` (SoftDeletes default scope excludes trashed); tile + sparkline like signups.
  - [x] **Active-trip status mix** — current snapshot (not windowed): counts of trips by `status` via **one** grouped query (`Trip::query()->groupBy('status')->selectRaw('status, count(*) as aggregate')->pluck('aggregate','status')`), projected to `{ active, paused, completed }` (missing statuses → 0). Uses `Trip::STATUS_*` constants.
  - [x] **Sends today + success rate** — `today = $window->end->toDateString()`: `total` = `email_logs` with `send_date = today`; `sent`/`failed` = by `status`; `success_rate` = `sent/total` % (null when `total = 0`). Sparkline = daily total sends over the window (`dailyCountsByDate(EmailLog::query(), 'send_date', $window)`).
  - [x] **Promo CTR** — a *rate* tile: `clicks / impressions` % over the window (0 when no impressions), from `promo_events` (`event` = `PromoEvent::EVENT_CLICK` / `EVENT_IMPRESSION`, filtered on `send_date` within the window). Include raw `clicks` + `impressions`. Sparkline = daily clicks. Delta = pp change vs the previous period (null when previous impressions = 0).
  - [x] **Sample requests** — `dailyCountsByTimestamp(SampleRequest::query(), 'created_at', $window)`; tile + sparkline.
  - [x] **Charts** (all share the window's `dates()` as x labels): `signups` = `[{label:'Signups', data: dailySignups}]`; `sends` = `[{label:'Sent', data: dailySent}, {label:'Failed', data: dailyFailed}]` (two `dailyCountsByDate` calls scoping `EmailLog::where('status', …)`); `ctr` = `[{label:'CTR %', data: dailyCtr}]` where `dailyCtr[i] = impressions[i] ? round(clicks[i]/impressions[i]*100, 1) : 0` (daily clicks & impressions series); `samples` = `[{label:'Samples', data: dailySamples}]`.
  - [x] Return payload shape (mirror in TS — Task 4):
    ```
    {
      window: int, windows: [7,30,90], dates: string[],
      kpis: {
        signups: { value:int, delta_pct:float|null, series:int[] },
        confirmation_rate: { value:float, delta_pp:float|null, series:int[] },
        trips_created: { value:int, delta_pct:float|null, series:int[] },
        status_mix: { active:int, paused:int, completed:int },
        sends_today: { total:int, sent:int, failed:int, success_rate:float|null, series:int[] },
        promo_ctr: { value:float, delta_pp:float|null, clicks:int, impressions:int, series:int[] },
        sample_requests: { value:int, delta_pct:float|null, series:int[] },
      },
      charts: {
        signups: TrendSeries[], sends: TrendSeries[], ctr: TrendSeries[], samples: TrendSeries[],
      },
    }
    ```
  - [x] **Injection-safety:** reuse `MetricsService` primitives (already column-constrained). The one new raw expression here — the status-mix `selectRaw('status, count(*) as aggregate')` — is a **literal** string; keep it literal (no interpolation). Bounded, grouped queries only — no per-row loops.
- [x] **Task 2 — Wire `AdminController@overview`** (AC: 1, 2)
  - [x] Replace the placeholder body: read the window from `?days=` — accept only `{7,30,90}`, default **30** on absent/invalid (do **not** let a bad param throw; validate before calling `resolveWindow`). Resolve the window, call `OverviewMetrics::build($window)` (inject via method or `app()`), and `Inertia::render('Admin/Overview', $payload)`.
  - [x] Keep it read-only and thin; no writes.
- [x] **Task 3 — Build `Admin/Overview.vue`** (AC: 1, 2)
  - [x] Replace the placeholder. Typed props from the payload (Task 4 types). Header: title + a **window selector** (7/30/90) — three `<Link>`s to `overview` with a `days` query param (Wayfinder helper; active = current `window`), phone-friendly.
  - [x] KPI grid: `grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4` (stacks to one column on a phone). Render `KpiTile` for signups / trips_created / sample_requests (value = count, `delta` = `delta_pct`, `series`), and for the two **rate** tiles confirmation_rate / promo_ctr (value = formatted `"{n}%"` string, `delta` = `delta_pp`, `series`). Render **sends_today** as a small card (total + "sent/failed" + success-rate %, with its sparkline) and **status_mix** as a small card showing the three counts with a simple proportion bar (a point-in-time distribution — a bar, not a time sparkline; note this in Dev Notes). Handle `null` rates/deltas as "—".
  - [x] Trend charts (full-width, one per row, using `dates` as labels): `TrendChart` for signups, sends (2 series), ctr, samples. Give each a short empty-safe title.
  - [x] Reuse the `Admin/*` token classes; single root element.
- [x] **Task 4 — TS payload contracts** (AC: 1)
  - [x] Create `resources/js/types/overview.ts` exporting the payload types (`OverviewPayload`, `OverviewKpi`, `OverviewRateKpi`, `StatusMix`, `SendsToday`) built on `MetricSeries`/`TrendSeries`/`KpiTileData` from `@/types/metrics`. Type `Overview.vue`'s props with `OverviewPayload`.
- [x] **Task 5 — Tests** (AC: 1, 2)
  - [x] Create `tests/Feature/Admin/OverviewTest.php` (Pest, `RefreshDatabase`, `travelTo` to pin now). Seed a known fixture across days: several `users` (some confirmed via `email_verified_at`), `trips` in each status, `email_logs` (`sent` + `failed`) incl. some dated **today**, `promo_events` (impressions + clicks), and `sample_requests` — some **inside** and some **outside** the default 30-day window.
  - [x] Assert as an admin (`assertInertia`): `component('Admin/Overview')`; `kpis.signups.value` and `series` length = window length; `confirmation_rate.value` equals confirmed/signups %; `status_mix` counts; `sends_today.total/sent/failed/success_rate` for today; `promo_ctr.value/clicks/impressions`; `sample_requests.value`; and `charts.sends` has two series with the right daily `sent`/`failed` arrays. Verify **out-of-window rows are excluded**.
  - [x] Window param: default (no `days`) → `window` 30; `?days=7` → `window` 7 and series length 7; `?days=45` and `?days=abc` → fall back to `window` 30 (no error).
  - [x] Authz (belt-and-suspenders on the real payload): guest `/admin/overview` → redirect login; non-admin → 403.
  - [x] **Runtime chart smoke (the 7.2 SSR flag):** no browser-test plugin is installed, so this is a **manual** verification step — after `composer run dev`, load `/admin/overview` as the admin and confirm the page renders with **no console/JS errors** and the charts draw (chart.js must run client-only under Inertia SSR; vue-chartjs defers to `onMounted`, so SSR emits the `<canvas>` and the client draws). Record the result in Completion Notes. (A Pest v4 browser test is a future add once the plugin is set up.)
  - [x] **Gates (all green):** `php artisan test --compact`, `vendor/bin/pint --dirty --format agent`, `./vendor/bin/phpstan analyse`, `npm run types:check`, `npm run lint:check`, `npm run build:ssr`.

## Dev Notes

### Scope boundary (read first)
- **Overview only.** This is the first section to consume the 7.2 foundation. Build the Overview payload + page; do **not** touch Users/Emails/Promos/Samples (7.4–7.7) or Monitoring. **Read-only**, **no migrations** (all data exists). Reuse `MetricsService`/`MetricsWindow` (7.2) and `KpiTile`/`TrendChart` (7.2) — do not add new chart components or re-implement bucketing.

### Architecture (binding)
- **FR-22 (overview):** "KPI tiles show signups, confirmation rate, trips created, active-trip status mix, sends today + success rate, promo CTR, and sample requests, each with a sparkline, plus trend charts … all matching the underlying data." [Source: _bmad-output/planning-artifacts/epics.md#Story-7.3]
- **Cross-cutting Epic-7 ACs:** phone-first (KPI tiles stack to one column, charts simple full-width), read-only, `admin`-Gate-guarded. [Source: epics.md#Epic-7]
- **AD-9 (email_logs source of truth):** send health reads `email_logs` (`sent`/`failed`/`sending`). **AD-18 (promo_events):** CTR reads `impression`/`click` rows. [Source: epics.md#Epic-7 anchors]

### Data model (exact fields)
- **`users`** — `created_at` (signups), `email_verified_at` nullable (confirmations; `User::isConfirmed()` = non-null). [Source: app/Models/User.php:60-65]
- **`trips`** — `created_at`, `status` (`Trip::STATUS_ACTIVE|STATUS_PAUSED|STATUS_COMPLETED`), SoftDeletes (default scope hides trashed — correct for "active-trip status mix"). [Source: app/Models/Trip.php:39-43]
- **`email_logs`** — `send_date` (**date**), `status` (`EmailLog::STATUS_SENDING|STATUS_SENT|STATUS_FAILED`). No factory — seed with `$trip->emailLogs()->create([...])`. [Source: app/Models/EmailLog.php:26-30]
- **`promo_events`** — `send_date` (**date**), `event` (`PromoEvent::EVENT_IMPRESSION|EVENT_CLICK`), `user_id`, `trip_id`, `promo_slug`. Seed via `DB::table('promo_events')->insert([...])` (respect the unique key `[trip_id, send_date, promo_slug, event]`). [Source: app/Models/PromoEvent.php:23-25; migration]
- **`sample_requests`** — `created_at`. [Source: app/Models/SampleRequest.php]
- **App tz = UTC**; "today" for sends = `$window->end` date. [Source: config/app.php]

### Code intel (patterns to match)
- **`MetricsService` API (7.2):** `resolveWindow(7|30|90)`, `dailyCountsByDate($q,'send_date',$w)`, `dailyCountsByTimestamp($q,'created_at'|'email_verified_at',$w)`, `count($q,$col,$from,$to,$isDate)`, `tile($cur,$prev)` → `{value,previous,delta,delta_pct}`. Column params are union-literal-typed — pass exactly those literals. [Source: app/Services/Metrics/MetricsService.php]
- **Rate tiles** aren't `MetricsService::tile()` (that's integer counts). Compute the percentage and pp-delta inline in `OverviewMetrics`; keep the returned shape distinct (`value` float, `delta_pp`) so the frontend can format it. Round rates to 1 decimal.
- **Controller render:** thin Inertia controller like `AdminController@monitoring`/`DashboardController` — `Inertia::render('Admin/Overview', $payload)`. Read query param with `$request->integer('days')` / `$request->query('days')`, validate against `{7,30,90}`, default 30. Do not use a FormRequest (a bad value should degrade to default, not 422). [Source: app/Http/Controllers/AdminController.php; app/Http/Controllers/PromoRedirect.php:29]
- **Chart components (7.2):** `KpiTile` props `label/value/delta?/series?`; `TrendChart` props `title/labels/series: TrendSeries[]`. Already registered via `@/lib/chart`. [Source: resources/js/components/admin/KpiTile.vue; TrendChart.vue]
- **Window selector links:** Wayfinder `overview` helper accepts query options — `overview({ query: { days: 7 } })` for the `:href` (confirm the generated signature after `wayfinder:generate`; fall back to `overview.url({ query: { days: 7 } })`). Active state compares to the `window` prop. [Source: resources/js/routes/admin/index.ts]

### SSR / chart safety (the 7.2 flag — verify here)
- Inertia SSR is **enabled** (`config/inertia.php` `ssr.enabled = true`; the SSR server runs under `composer run dev`), so Overview is server-rendered. `chart.js` touches `window`/canvas; `vue-chartjs` v5 creates the Chart in `onMounted`, which does **not** run during SSR — the server emits the `<canvas>` element and the client draws. This should be safe, but **must be verified at runtime** (load the page, check for console/JS errors) since there's no browser-test harness. If SSR ever errors on the chart, guard with a client-only wrapper (`<ClientOnly>`-style `v-if="mounted"`), but try without first.

### Testing standards
- Pest + `RefreshDatabase`; pin time with `$this->travelTo('2026-07-01 12:00:00')` so window bounds and seeded dates are deterministic. Seed `email_logs`/`promo_events` on specific `send_date`s (incl. today = `2026-07-01`) and some outside the 30-day window to prove exclusion. Assert exact `series` arrays where small, and tile/rate values against hand-computed expectations. Use `->assertInertia(fn ($page) => $page->component('Admin/Overview')->where('kpis.signups.value', N)->where('charts.sends.1.data', [...])...)`. Authz mirrors `AdminShellTest`. [Source: tests/Feature/Admin/MetricsServiceTest.php; AdminShellTest.php]
- The chart **rendering** (not data) is verified manually in the browser this story; a Pest v4 browser smoke (`visit('/admin/overview')->assertNoJavaScriptErrors()`) is the future automated form once a browser plugin is installed.

### Project Structure Notes
- **New:** `app/Services/Metrics/OverviewMetrics.php`, `resources/js/types/overview.ts`, `tests/Feature/Admin/OverviewTest.php`.
- **Modified:** `app/Http/Controllers/AdminController.php` (`overview()` body), `resources/js/pages/Admin/Overview.vue` (placeholder → real).
- **Unchanged:** `MetricsService`/`MetricsWindow`, `KpiTile`/`TrendChart`, routes (the `admin.overview` route already exists from 7.1), other sections. **No migrations.**

### Previous story intelligence (7.1, 7.2)
- 7.1 gave the guarded route + `AdminLayout` tab shell + the `Admin/Overview.vue` placeholder (now replaced). 7.2 gave `MetricsService`/`MetricsWindow` (grouped, zero-filled, injection-safe) + `KpiTile`/`TrendChart` + `types/metrics.ts`, all **green but unmounted** — 7.3 is their first real use, so the chart-SSR check lands here. Keep everything read-only; regenerate Wayfinder on build (gitignored). 7.1/7.2 may be uncommitted in the working tree; 7.3 only adds/edits its own files + the `overview()` method + the Overview page. [Source: _bmad-output/implementation-artifacts/7-1-*.md, 7-2-*.md]

### References
- [Source: _bmad-output/planning-artifacts/epics.md#Story-7.3] (AC)
- [Source: _bmad-output/planning-artifacts/epics.md#Epic-7] (cross-cutting ACs, AD-9/AD-18)
- [Source: app/Services/Metrics/MetricsService.php; MetricsWindow.php] (foundation API)
- [Source: resources/js/components/admin/KpiTile.vue; TrendChart.vue; resources/js/types/metrics.ts] (components + contracts)
- [Source: app/Models/User.php; Trip.php; EmailLog.php; PromoEvent.php; SampleRequest.php] (fields/constants)
- [Source: config/inertia.php] (SSR enabled)

## Dev Agent Record

### Agent Model Used

claude-opus-4-8 (1M context)

### Debug Log References

- **Test fixture drift:** an extra inline trip created for the "today failed" send inflated `trips_created`/status mix — reused the paused trip instead (unique key is `[trip_id, send_date]`, so a paused trip can carry a today log).
- **Float serialization:** a whole-number rate (`50.0`) is JSON-encoded by PHP as `50` and decodes as `int`, so the exact-match assertion needed a numeric closure (`(float) $v === 50.0`). Real behavior is correct; only the test comparison needed to be type-tolerant.
- **Fluent closures receive Collections:** `array_sum($d)` failed in `assertInertia` `where` closures — used `collect($d)->sum()`.

### Completion Notes List

- **`OverviewMetrics` (AC1):** composes the 7.2 `MetricsService` primitives into the full payload — signups, confirmation rate, trips created, status mix, sends-today, promo CTR, sample requests (each a tile + sparkline) and the four trend series. Rates (confirmation, CTR) are computed inline with **percentage-point** deltas (null when there's no prior denominator). Status mix and today's send breakdown each use one grouped, **literal** `selectRaw` (no interpolation). All bounded, grouped queries — no per-row loops.
- **Controller (AC2):** `overview()` reads `?days=`, accepts only `{7,30,90}`, and **degrades to 30** on absent/invalid input (never throws/422) — verified by the `45/abc/0/-7` dataset.
- **Overview page:** phone-first — KPI grid stacks to one column, charts are full-width one-per-row; a 7/30/90 window selector links via the Wayfinder `overview({query:{days}})` helper. Rate tiles render `"{n}%"` with pp-deltas; status mix is a point-in-time proportion bar (not a time sparkline — a distribution, by design); sends-today is a bespoke card. Read-only.
- **Data correctness fully tested:** `OverviewTest` seeds a known fixture (in- and out-of-window rows) and asserts every KPI value, the status mix, today's sends + success rate, promo CTR + clicks/impressions, sample count, and the two-series sends chart sums — plus the 7-day recompute, invalid-window fallback, and Gate guards. **7 tests; full suite 341 passed / 1221 assertions.**
- **Chart-SSR flag (from 7.2) — NOT yet verified live.** The Overview page compiles cleanly into both the client and **SSR** bundles (`npm run build:ssr`), and vue-chartjs v5 defers chart creation to `onMounted` (skipped during SSR), so the risk is low. But I could **not** run the in-browser render/console-error check: the Claude browser extension was not connected, and the local dev server on :8000 is running stale pre-panel code (needs a `composer run dev` restart to serve `/admin/overview`). **Remaining manual step:** restart `composer run dev`, open `/admin/overview` as an admin, confirm the charts draw and the console is clean. (Automated Pest v4 browser smoke is a future add once a browser plugin is installed.)
- **Scope held:** only Overview built; Users/Emails/Promos/Samples/Monitoring untouched. No migrations, no new deps (reuses 7.2's chart libs).

### File List

**New:**
- `app/Services/Metrics/OverviewMetrics.php`
- `resources/js/types/overview.ts`
- `tests/Feature/Admin/OverviewTest.php`

**Modified:**
- `app/Http/Controllers/AdminController.php` (`overview()` body + `DEFAULT_WINDOW` const, imports)
- `resources/js/pages/Admin/Overview.vue` (placeholder → real dashboard)
- regenerated Wayfinder helpers (gitignored)

**Unchanged:** `MetricsService`/`MetricsWindow`, `KpiTile`/`TrendChart`, routes, other sections. No migrations.

### Change Log

- 2026-07-01 — Implemented Story 7.3: Overview dashboard. Added `OverviewMetrics` composing the 7.2 metrics primitives into signups / confirmation rate / trips created / active-trip status mix / sends-today + success rate / promo CTR / sample requests KPIs (each with a sparkline) plus signups-, sends&failures-, CTR-, and samples-per-day trend charts; wired `AdminController@overview` with a 7/30/90 window (default 30, invalid → default) and built the phone-first Overview page consuming the 7.2 chart components. Read-only. Data fully tested (341 tests green); live chart-render check deferred to a manual browser pass (extension offline).
