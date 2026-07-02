---
baseline_commit: 26251b5
---

# Story 7.5: Email health & daily-run liveness

Status: review

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

As the builder,
I want send-health and batch-run liveness in one place,
so that I can confirm emails are actually going out and the daily job is healthy.

## Acceptance Criteria

**AC1 — `/admin/emails` shows send health from `email_logs`** *(FR-24, AD-9)*
- **Given** an admin opens `/admin/emails`
- **When** it renders
- **Then** it shows **sends/day**, **sent-vs-failed rate**, **failures grouped by reason** (`weather:` vs `delivery:`), and a **stuck-`sending` count** — all from `email_logs`.

**AC2 — Daily-run liveness (`digests:run`, AD-14) + deferred opens/bounces** *(FR-24, AD-14)*
- **Given** the daily-run liveness signal (`digests:run`)
- **When** the section renders
- **Then** it surfaces the **last run's health** (healthy?, due vs dispatched, duration), and email **opens/bounces show a clearly-labeled "deferred" placeholder**.

**AC3 — Phone-first, read-only, guarded** *(Epic-7 cross-cutting)*
- Phone-first (cards stack, chart full-width); strictly read-only; behind the `admin` Gate (guest → login, non-admin → 403).

## Tasks / Subtasks

- [x] **Task 1 — Persist the last-run liveness snapshot for the panel** (AC: 2)
  - [x] The `digests:run` outcome is currently only `Log::info(...)` + an external heartbeat ping (AD-14) — **not queryable**. In `app/Console/Commands/SendDailyDigests.php`, add `public const LAST_RUN_CACHE_KEY = 'admin:digests:last_run';` and, inside `recordRun(...)`, **also** write the snapshot to the cache: `Cache::put(self::LAST_RUN_CACHE_KEY, ['healthy' => $healthy, 'due' => $due, 'dispatched' => $dispatched, 'duration_ms' => (int) $startedAt->diffInMilliseconds(now()), 'error' => $error, 'ran_at' => now()->toIso8601String()], now()->addDays(14))`. Import `Illuminate\Support\Facades\Cache`.
  - [x] This is the minimal in-app source for AD-14 (no new table — consistent with 2.7's "no runs table" decision; the log + external monitor stay as-is). Keep the existing `Log::info('digests:run', …)` call.
- [x] **Task 2 — `EmailHealthMetrics` builder** (AC: 1)
  - [x] Create `app/Services/Metrics/EmailHealthMetrics.php`. Constructor-inject `MetricsService`. `build(MetricsWindow $window): array` returning:
    - **`sends`** (chart): `[{label:'Sent', data: dailySent}, {label:'Failed', data: dailyFailed}]` via `dailyCountsByDate(EmailLog::query()->where('status', …), 'send_date', $window)` for `STATUS_SENT` and `STATUS_FAILED`.
    - **`totals`**: `{ sent, failed, total: sent+failed, success_rate }` where `sent`/`failed` are the series sums and `success_rate = (sent+failed) > 0 ? round(sent/(sent+failed)*100, 1) : null`.
    - **`failures_by_reason`**: over the window (failed rows, `send_date` in `[start,end]`): `{ weather, delivery, other }` — `weather` = `failure_reason like 'weather:%'`, `delivery` = `failure_reason like 'delivery:%'`, `other` = `failed_total - weather - delivery`. (Prefixes are set in `SendTripDigest`: `'weather: '.…` and `'delivery: '.…`.)
    - **`stuck_sending`**: current count of `email_logs` with `status = STATUS_SENDING` and `claimed_at < now()->subMinutes(config('tripcast.send.stale_lease_minutes'))` — a send whose lease went stale (AD-3). Not windowed.
  - [x] Bounded, grouped queries only; read-only.
- [x] **Task 3 — Wire `AdminController@emails`** (AC: 1, 2, 3)
  - [x] Replace the placeholder. Resolve the window from `?days=` exactly like `overview()` (allowlist `{7,30,90}`, default `DEFAULT_WINDOW`). Build email health via `EmailHealthMetrics`. Read liveness: `$liveness = Cache::get(SendDailyDigests::LAST_RUN_CACHE_KEY)` (array or `null`).
  - [x] `Inertia::render('Admin/Emails', [...$emailHealth, 'window' => …, 'windows' => …, 'dates' => $window->dates(), 'liveness' => $liveness])`. Read-only; thin.
- [x] **Task 4 — Build `Admin/Emails.vue`** (AC: 1, 2, 3)
  - [x] Replace the placeholder. Typed props for the payload. Header: title + the 7/30/90 window selector (same pattern as `Overview.vue`).
  - [x] **Send-health cards** (stack on phone): sent-vs-failed **rate** (`success_rate`% or "—"), **sent** total, **failed** total, **stuck sending** count (flag it visually if `> 0`). A small **failures-by-reason** breakdown (weather / delivery / other).
  - [x] **Sends/day** `TrendChart` (2 series: Sent, Failed) using `dates` as labels.
  - [x] **Liveness card:** if `liveness` present → healthy pill (positive/destructive), `due` vs `dispatched`, `duration_ms` (show as ms or s), and `ran_at` (relative/date); if `null` → "No daily run recorded yet." Show `error` when present.
  - [x] **Opens/bounces:** a clearly-labeled **"Deferred — not tracked on the current mail driver (needs an ESP)"** placeholder card. Static; no data.
  - [x] Single root; reuse `Admin/*` tokens; strictly read-only.
- [x] **Task 5 — TS contracts** (AC: 1, 2)
  - [x] Add the payload types (inline in `Emails.vue` or `resources/js/types/emails.ts`): `EmailHealthPayload` = `{ window, windows, dates, sends: TrendSeries[], totals: { sent, failed, total, success_rate: number|null }, failures_by_reason: { weather, delivery, other }, stuck_sending: number, liveness: LivenessSnapshot | null }` with `LivenessSnapshot = { healthy: boolean; due: number; dispatched: number; duration_ms: number; error: string | null; ran_at: string }`.
- [x] **Task 6 — Tests** (AC: 1, 2, 3)
  - [x] **Command persistence** (`tests/Feature/Digest/SendDailyDigestsTest.php` or a focused new test): after running `digests:run`, assert `Cache::get(SendDailyDigests::LAST_RUN_CACHE_KEY)` holds `healthy`/`due`/`dispatched`/`duration_ms`/`ran_at`. Do not weaken existing 2.7 heartbeat/log assertions.
  - [x] Create `tests/Feature/Admin/EmailHealthTest.php` (Pest, `RefreshDatabase`, `travelTo`). Seed `email_logs` across the window: `sent` and `failed` (some `weather:`, some `delivery:`, one bare/`other`), plus `sending` rows — one **stale** (`claimed_at` older than the stale-lease) and one **fresh** (recent `claimed_at`), and one row **outside** the window. Assert as admin:
    - `component('Admin/Emails')`; `totals.sent`/`totals.failed`/`totals.success_rate`; `failures_by_reason.{weather,delivery,other}`; `stuck_sending` counts only the **stale** sending row; `sends` chart has two series whose sums match.
    - **Liveness:** with `Cache::put(SendDailyDigests::LAST_RUN_CACHE_KEY, [...])` set → `liveness.healthy`/`due`/`dispatched` surface; with the key absent → `liveness` is `null`.
    - **Window param:** default 30; `?days=7` → `window` 7; invalid → 30.
    - **Authz:** guest → login; non-admin → 403.
  - [x] **Gates (all green):** `php artisan test --compact`, `vendor/bin/pint --dirty --format agent`, `./vendor/bin/phpstan analyse`, `npm run types:check`, `npm run lint:check`, `npm run build:ssr`.

## Dev Notes

### Scope boundary (read first)
- **Emails section only** (+ the tiny liveness-cache write in the daily command). Do not touch Overview/Users/Promos/Samples/Monitoring. **Read-only**, **no migrations**. Reuse `MetricsService` (7.2) + `TrendChart` (7.2) + the window-selector pattern (7.3).

### Architecture (binding)
- **FR-24 / AD-9:** send health reads `email_logs` (`sent`/`failed`/`sending` + `failure_reason`), the source of truth. [Source: epics.md#Story-7.5; app/Models/EmailLog.php]
- **AD-14 (run liveness):** the whole-run signal (`digests:run`) is healthy unless trips were due but nothing dispatched. Currently logged + external heartbeat only; this story adds a **cache snapshot** so the panel can read it — no runs table (2.7's decision preserved). [Source: app/Console/Commands/SendDailyDigests.php:65-90]
- **Opens/bounces are out of scope** for Epic 7 (not trackable on the `log`/array mail driver) — render a labeled "deferred" placeholder, don't fake data. [Source: epics.md#Epic-7 cross-cutting note]

### Data model / signals (exact)
- **`email_logs`** — `send_date` (date), `status` (`EmailLog::STATUS_SENDING|STATUS_SENT|STATUS_FAILED`), `failure_reason`, `claimed_at` (lease). No factory — seed with `$trip->emailLogs()->create([...])` or `DB::table('email_logs')->insert([...])` (set `claimed_at` explicitly for stuck-sending cases). [Source: app/Models/EmailLog.php; migration]
- **Failure-reason prefixes** are literal: `SendTripDigest.php:62` writes `'weather: '.$e->getMessage()`, `:216` writes `'delivery: '.$lastError?->getMessage()`. Group with `like 'weather:%'` / `like 'delivery:%'`. [Source: app/Jobs/SendTripDigest.php]
- **Stuck sending** = `status = sending` AND `claimed_at < now()->subMinutes(config('tripcast.send.stale_lease_minutes'))` (default 30) — the same stale-lease threshold the reclaim logic uses (AD-3). [Source: config/tripcast.php:64]
- **Liveness cache key:** `SendDailyDigests::LAST_RUN_CACHE_KEY`. `CACHE_STORE=array` in tests (deterministic; flush or set explicitly per test). App default is `redis` (predis). [Source: phpunit.xml; config/cache.php]

### Code intel (patterns to match)
- **`MetricsService`** (7.2): `resolveWindow`, `dailyCountsByDate(EmailLog::query()->where('status', …), 'send_date', $window)`. Column literal `'send_date'` is already allowed. [Source: app/Services/Metrics/MetricsService.php]
- **`OverviewMetrics`** (7.3) is the section-builder template; `EmailHealthMetrics` mirrors it (inject `MetricsService`, return an array payload). [Source: app/Services/Metrics/OverviewMetrics.php]
- **Controller/window handling** copies `AdminController@overview` (`DEFAULT_WINDOW`, `ALLOWED_WINDOWS` fallback). [Source: app/Http/Controllers/AdminController.php]
- **Frontend:** reuse `TrendChart` + the `Overview.vue` window selector + card styling and `Admin/*` tokens. `Emails` route helper: `import { emails } from '@/routes/admin'`. Activate `inertia-vue-development`. [Source: resources/js/pages/Admin/Overview.vue; resources/js/components/admin/TrendChart.vue]

### Testing standards
- Pest + `RefreshDatabase` + `travelTo` (pin now for deterministic windows and the stale-lease boundary). For stuck-sending, seed one `sending` row with `claimed_at` = `now()->subMinutes(31)` (stale) and one with `now()->subMinutes(5)` (fresh) → only the stale counts. Fake the log-mail/heartbeat as the existing 2.7 tests do where needed. Liveness: `Cache::put`/`Cache::forget` the snapshot key. Assert Inertia props with `->component('Admin/Emails')->where('totals.sent', n)…`. Authz mirrors `AdminShellTest`. [Source: tests/Feature/Digest/SendDailyDigestsTest.php; tests/Feature/Admin/*.php]

### Project Structure Notes
- **New:** `app/Services/Metrics/EmailHealthMetrics.php`, `tests/Feature/Admin/EmailHealthTest.php` (+ optional `resources/js/types/emails.ts`).
- **Modified:** `app/Console/Commands/SendDailyDigests.php` (+`LAST_RUN_CACHE_KEY` + cache write in `recordRun`), `app/Http/Controllers/AdminController.php` (`emails()` body), `resources/js/pages/Admin/Emails.vue` (placeholder → real).
- **Unchanged:** routes (`admin.emails` from 7.1), other sections, migrations. **No migrations.**

### Previous story intelligence (7.1–7.4)
- 7.2 gave `MetricsService`/`TrendChart`; 7.3 gave the window selector + section-builder pattern; both reused here. This is the first story to touch a non-admin file (`SendDailyDigests`) — keep that change surgical (one const + one cache write) and don't alter run semantics. Regenerate Wayfinder on build. 7.1–7.4 may be uncommitted; 7.5 adds its own files + the two small edits. [Source: _bmad-output/implementation-artifacts/7-2-*.md, 7-3-*.md]

### References
- [Source: _bmad-output/planning-artifacts/epics.md#Story-7.5] (ACs)
- [Source: app/Console/Commands/SendDailyDigests.php] (liveness/recordRun)
- [Source: app/Jobs/SendTripDigest.php:62,216] (failure-reason prefixes)
- [Source: app/Models/EmailLog.php; config/tripcast.php:64] (statuses, stale-lease)
- [Source: app/Services/Metrics/MetricsService.php; OverviewMetrics.php] (builder pattern)

## Dev Agent Record

### Agent Model Used

claude-opus-4-8 (1M context)

### Debug Log References

- **Whole-number float serialization** (again): `success_rate` `40.0` JSON-encodes to `40` (int), so the exact-match assertion used a numeric closure. Real value is correct.

### Completion Notes List

- **Liveness source (AC2):** `digests:run` was log-only + an external heartbeat — not queryable. Added `SendDailyDigests::LAST_RUN_CACHE_KEY` and a `Cache::put` of the run snapshot (`healthy/due/dispatched/duration_ms/error/ran_at`, 14-day TTL) inside `recordRun()`. Surgical — no runs table (preserves 2.7's decision), existing `Log::info` + heartbeat untouched, run semantics unchanged. Verified by a new test that running `digests:send` populates the snapshot.
- **`EmailHealthMetrics` (AC1):** daily sent/failed series (reuses `MetricsService::dailyCountsByDate`), sent-vs-failed rate, failures grouped by the `weather:`/`delivery:`/other reason prefix (over the window), and a point-in-time stuck-`sending` count using the AD-3 stale-lease threshold (`claimed_at < now - stale_lease_minutes`). Bounded, grouped queries; read-only.
- **Controller (AC1/2/3):** `emails()` mirrors `overview()`'s 7/30/90 window handling (invalid → default 30) and reads the liveness snapshot from cache (array or `null`).
- **Page:** phone-first cards (rate/sent/failed/stuck, stuck flagged red when > 0), a failures-by-reason breakdown, the sends&failures `TrendChart`, a liveness card (healthy pill, dispatched-of-due, duration, ran_at, error), and a dashed **"Deferred — needs an ESP"** opens/bounces placeholder. Read-only.
- **Scope held:** only the Emails section + the two-line liveness-cache addition to the daily command. No migrations, no new deps.
- **Verification:** full suite **353 passed / 1366 assertions** (5 new EmailHealthTest + 1 new command test). pint clean, phpstan 0 errors, types:check + lint:check clean, build:ssr built (Emails in client + SSR bundles).

### File List

**New:**
- `app/Services/Metrics/EmailHealthMetrics.php`
- `tests/Feature/Admin/EmailHealthTest.php`

**Modified:**
- `app/Console/Commands/SendDailyDigests.php` (+`LAST_RUN_CACHE_KEY`, cache snapshot in `recordRun`, `Cache` import)
- `app/Http/Controllers/AdminController.php` (`emails()` body + imports)
- `resources/js/pages/Admin/Emails.vue` (placeholder → real)
- `tests/Feature/Digest/SendDailyDigestsTest.php` (+ liveness-cache test, `Cache`/`SendDailyDigests` imports)
- regenerated Wayfinder helpers (gitignored)

**Unchanged:** routes (`admin.emails` from 7.1), other sections, migrations. No migrations.

### Change Log

- 2026-07-01 — Implemented Story 7.5: Email health & daily-run liveness. `/admin/emails` shows sends/day (Sent/Failed chart), sent-vs-failed rate, failures grouped by `weather:`/`delivery:`/other, and a stale-lease stuck-`sending` count — all from `email_logs` (AD-9) over a 7/30/90 window. Added a cache snapshot of the `digests:run` outcome (AD-14) so the panel surfaces last-run health (healthy?, dispatched-of-due, duration, ran_at); opens/bounces render a labeled "deferred" placeholder. Read-only. All gates green (353 tests).
