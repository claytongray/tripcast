---
baseline_commit: 26251b5
---

# Story 7.7: Sample activity & acquisition

Status: review

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

As the builder,
I want the sample-request funnel,
so that I can measure top-of-funnel acquisition and conversion.

## Acceptance Criteria

**AC1 — `/admin/samples` shows requests-over-time, top destinations, and sample→confirmed conversion** *(FR-25)*
- **Given** an admin opens `/admin/samples`
- **When** it renders
- **Then** it shows **sample_requests over time**, **top destinations**, and **sample→confirmed-signup conversion** (joining `sample_requests.user_id` → `users.email_verified_at`).

**AC2 — Windowed, phone-first, guarded** *(FR-25, Epic-7 cross-cutting)*
- Date range is the 7/30/90 window (default 30; invalid → default). Phone-first; read-only; behind the `admin` Gate (guest → login, non-admin → 403).

## Tasks / Subtasks

- [x] **Task 1 — `SampleFunnelMetrics` builder** (AC: 1)
  - [x] Create `app/Services/Metrics/SampleFunnelMetrics.php`. Constructor-inject `MetricsService`. `build(MetricsWindow $window): array`.
  - [x] **Requests over time:** `dailyCountsByTimestamp(SampleRequest::query(), 'created_at', $window)` → the daily series; chart = `[{label:'Sample requests', data: dailyCounts}]`; `totals.requests` = the series sum.
  - [x] **Top destinations:** one grouped query — `SampleRequest::query()->whereBetween('created_at', [$window->start, $window->end])->groupBy('destination')->selectRaw('destination, count(*) as aggregate')->orderByDesc('aggregate')->limit(10)->get()` → `list<{ destination, count }>` (`aggregate` via `getAttribute`). (`selectRaw` string is literal — keep it literal.)
  - [x] **Conversion** (distinct requesters, so a user with multiple requests counts once):
    - `requesters` = `SampleRequest::query()->whereBetween('created_at', [start,end])->distinct()->count('user_id')`.
    - `confirmed_requesters` = same window, **joined** to `users` (`->join('users','users.id','=','sample_requests.user_id')->whereNotNull('users.email_verified_at')->distinct()->count('sample_requests.user_id')`) — qualify `created_at` as `sample_requests.created_at` to avoid ambiguity with the join.
    - `conversion_rate` = `requesters > 0 ? round(confirmed_requesters/requesters*100, 1) : null`.
  - [x] Return `{ requests: TrendSeries[], totals: { requests, requesters, confirmed_requesters, conversion_rate }, top_destinations: [...] }`. Bounded, grouped queries; read-only.
- [x] **Task 2 — Wire `AdminController@samples`** (AC: 1, 2)
  - [x] Replace the placeholder. Resolve the window from `?days=` like the other sections (allowlist `{7,30,90}`, default `DEFAULT_WINDOW`). Build via `SampleFunnelMetrics`. `Inertia::render('Admin/Samples', [...$funnel, 'window' => …, 'windows' => …, 'dates' => $window->dates()])`. Read-only; thin.
- [x] **Task 3 — Build `Admin/Samples.vue`** (AC: 1, 2)
  - [x] Replace the placeholder. Typed props. Header: title + the 7/30/90 window selector (same pattern as the other sections).
  - [x] **Cards** (stack on phone): total sample requests, distinct requesters, conversion rate (`{n}%` / "—") with a `confirmed_requesters / requesters` subtitle.
  - [x] **Requests/day** `TrendChart` using `dates` labels.
  - [x] **Top destinations** list/table (destination + count), empty state.
  - [x] Single root; reuse `Admin/*` tokens; strictly read-only.
- [x] **Task 4 — TS contracts** (AC: 1)
  - [x] Payload types (inline in `Samples.vue` or `resources/js/types/samples.ts`): `DestinationRow = { destination: string; count: number }`, `SampleFunnelPayload = { window, windows, dates, requests: TrendSeries[], totals: { requests, requesters, confirmed_requesters, conversion_rate: number|null }, top_destinations: DestinationRow[] }`.
- [x] **Task 5 — Tests** (AC: 1, 2)
  - [x] Create `tests/Feature/Admin/SampleFunnelTest.php` (Pest, `RefreshDatabase`, `travelTo`). Seed `sample_requests` in the window: several across a few destinations (one destination repeated to top the list), at least one **user with two requests** (to prove distinct requesters), a mix of **confirmed** and **unconfirmed** requester users, and some rows **outside** the window. Assert as admin:
    - `component('Admin/Samples')`; `totals.requests` (row count in window), `totals.requesters` (distinct users), `totals.confirmed_requesters`, `totals.conversion_rate`; `top_destinations` ordered by count desc with correct counts; `requests` series length = window and sum = `totals.requests`; out-of-window rows excluded.
    - **Window param:** default 30; `?days=7` → `window` 7; invalid → 30.
    - **Authz:** guest → login; non-admin → 403.
  - [x] **Gates (all green):** `php artisan test --compact`, `vendor/bin/pint --dirty --format agent`, `./vendor/bin/phpstan analyse`, `npm run types:check`, `npm run lint:check`, `npm run build:ssr`.

## Dev Notes

### Scope boundary (read first)
- **Samples section only.** Do not touch other sections. **Read-only**, **no migrations**. Reuse `MetricsService` (7.2, `dailyCountsByTimestamp` + `resolveWindow`) + `TrendChart` (7.2) + the window-selector pattern (7.3).

### Architecture (binding)
- **FR-25:** sample_requests over time, top destinations, sample→confirmed conversion via `sample_requests.user_id` → `users.email_verified_at`. [Source: epics.md#Story-7.7]
- **Cross-cutting Epic-7 ACs:** phone-first, read-only, `admin`-Gate-guarded. [Source: epics.md#Epic-7]

### Data model (exact)
- **`sample_requests`** — `user_id`, `email`, `destination`, `created_at`. `SampleRequest::user()` BelongsTo `User`; `User::sampleRequests()` HasMany (added in 7.4). Multiple requests per user are possible → conversion counts **distinct** `user_id`. [Source: app/Models/SampleRequest.php; app/Models/User.php]
- **`users.email_verified_at`** — non-null = confirmed (`User::hasConfirmedEmail()`). Conversion = distinct requesters whose user is confirmed ÷ distinct requesters. [Source: app/Models/User.php:60-65]
- **App tz UTC**; `created_at` is a timestamp → `dailyCountsByTimestamp` (`DATE(created_at)` bucketing). [Source: config/app.php; app/Services/Metrics/MetricsService.php]

### Code intel (patterns to match)
- **`MetricsService`:** `resolveWindow`, `dailyCountsByTimestamp(SampleRequest::query(), 'created_at', $window)` (`'created_at'` is an allowed literal column). [Source: app/Services/Metrics/MetricsService.php]
- **Section builder** mirrors `OverviewMetrics`/`EmailHealthMetrics`/`PromoAnalytics`. The join for confirmed-requesters is the one new bit — qualify columns (`sample_requests.created_at`, `sample_requests.user_id`, `users.email_verified_at`). Dynamic `aggregate` alias → `getAttribute('aggregate')` (as in 7.4/7.6). [Source: app/Services/Metrics/PromoAnalytics.php]
- **Controller/window** copies `overview()`/`emails()`/`promos()`. [Source: app/Http/Controllers/AdminController.php]
- **Frontend:** reuse window selector + cards + `TrendChart` + table styling; `Samples` route helper `import { samples } from '@/routes/admin'`. Activate `inertia-vue-development`. [Source: resources/js/pages/Admin/Overview.vue; Promos.vue]

### Testing standards
- Pest + `RefreshDatabase` + `travelTo`. Seed via the `User::sampleRequests()->create([...])` relation or `DB::table('sample_requests')->insert([...])` with explicit `created_at`. Make one user submit two requests (distinct-requester check) and mix confirmed/unconfirmed users (conversion check). Whole-number rates JSON-encode to int — assert with a numeric closure. Fluent `where` closures receive Collections — use `collect(...)`/`->sum()` not `array_sum`. Assert `->component('Admin/Samples')->where('totals.requesters', n)->where('top_destinations.0.destination', …)`. Authz mirrors `AdminShellTest`. [Source: tests/Feature/Admin/PromoAnalyticsTest.php; OverviewTest.php]

### Project Structure Notes
- **New:** `app/Services/Metrics/SampleFunnelMetrics.php`, `tests/Feature/Admin/SampleFunnelTest.php` (+ optional `resources/js/types/samples.ts`).
- **Modified:** `app/Http/Controllers/AdminController.php` (`samples()` body), `resources/js/pages/Admin/Samples.vue` (placeholder → real).
- **Unchanged:** routes (`admin.samples` from 7.1), other sections, migrations. **No migrations.**

### Previous story intelligence (7.1–7.6)
- Same section-builder + controller-window + window-selector + chart patterns as 7.3/7.5/7.6 — reuse them. The `User::sampleRequests()` relation added in 7.4 is available. Read-only. Regenerate Wayfinder on build. 7.1–7.6 may be uncommitted; 7.7 adds its own files + the `samples()` method. This is the last observability section (7.8 is the demo seeder). [Source: _bmad-output/implementation-artifacts/7-3-*.md … 7-6-*.md]

### References
- [Source: _bmad-output/planning-artifacts/epics.md#Story-7.7] (AC)
- [Source: app/Models/SampleRequest.php; app/Models/User.php] (fields, relation, confirmation)
- [Source: app/Services/Metrics/MetricsService.php] (resolveWindow, dailyCountsByTimestamp)

## Dev Agent Record

### Agent Model Used

claude-opus-4-8 (1M context)

### Debug Log References

- **PHPStan `list` vs `array<int,…>`:** `->get()->map()->values()->all()` still infers `array<int,…>` (not `list`), so `topDestinations()` failed its `list<...>` return type. Rebuilt the array with a `foreach` (a genuine `list`) instead. `aggregate` alias read via `getAttribute` as before.

### Completion Notes List

- **`SampleFunnelMetrics` (AC1):** requests-over-time (reuses `MetricsService::dailyCountsByTimestamp`), top-10 destinations (one grouped query), and sample→confirmed conversion — computed on **distinct requesters** (`distinct()->count('user_id')`) so a user with multiple sample requests counts once, with `confirmed_requesters` via a join to `users.email_verified_at` (columns qualified to avoid `created_at` ambiguity). `conversion_rate` null when there are no requesters. Read-only.
- **Controller:** `samples()` mirrors the other sections' 7/30/90 window handling (invalid → 30).
- **Page:** window selector, funnel cards (total requests, distinct requesters, confirmed conversion with a `x of y` subtitle), requests/day `TrendChart`, and a phone-scrollable top-destinations table with an empty state. Read-only.
- **Scope held:** samples section only; no migrations, no new deps, other sections untouched.
- **Verification:** full suite **359 passed / 1460 assertions** (3 new: distinct-requester + confirmed conversion, top-destination ordering incl. alphabetical tiebreak, out-of-window exclusion, window fallback, Gate guards). pint clean, phpstan 0 errors, types:check + lint:check clean, build:ssr built (Samples in client + SSR bundles).

### File List

**New:**
- `app/Services/Metrics/SampleFunnelMetrics.php`
- `tests/Feature/Admin/SampleFunnelTest.php`

**Modified:**
- `app/Http/Controllers/AdminController.php` (`samples()` body + import)
- `resources/js/pages/Admin/Samples.vue` (placeholder → real)
- regenerated Wayfinder helpers (gitignored)

**Unchanged:** routes (`admin.samples` from 7.1), other sections, migrations. No migrations.

### Change Log

- 2026-07-01 — Implemented Story 7.7: Sample activity & acquisition. `/admin/samples` shows sample requests/day, top destinations, and sample→confirmed-signup conversion (distinct requesters joined to `users.email_verified_at`) over a 7/30/90 window. Read-only. All observability sections (7.3–7.7) now consume the 7.2 foundation. All gates green (359 tests).
