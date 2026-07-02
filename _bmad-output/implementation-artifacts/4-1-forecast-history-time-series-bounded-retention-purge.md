---
baseline_commit: b0b9c3f
---

# Story 4.1: Forecast-history time-series + bounded-retention purge

Status: review

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

As the system,
I want the per-send snapshots to serve as the forecast history and to age out,
so that day-over-day change can be computed without a second store or unbounded growth.

## Acceptance Criteria

**AC1 — The `email_logs` snapshot series IS the forecast-history time-series (no new store)** *(FR-15, AD-9)*
- **Given** the `email_logs` snapshot series (Story 2.3) is the forecast-history time-series — no new store
- **When** consecutive captures exist for a Trip
- **Then** a target day's values (e.g. precip probability) are **diffable across capture dates** by reading the `weather_snapshot` of two `(trip_id, send_date)` rows. (This story does **not** add the diff/narration — that is Story 4.2 — it establishes and bounds the series.)

**AC2 — A `PurgeForecastHistory` sweep nulls only `weather_snapshot`, anchored on return_date, leaving the row intact** *(FR-15, AD-16)*
- **Given** a `PurgeForecastHistory` sweep in the daily command
- **When** it runs
- **Then** it **nulls only `weather_snapshot`** ~30 days (configurable) after the Trip's **`return_date`** (anchored on `return_date`, **never** `send_date`/`created_at`), leaving the **send-outcome row intact** (`status`, `failure_reason`, `claimed_at`, dates) so AD-5/AD-9's audit trail and `feedback` joins survive; readers tolerate a snapshot-absent row. Soft-deleted Trips' snapshots purge too (age-out is status/deletion-independent).

## Tasks / Subtasks

- [x] **Task 1 — Retention config** (AC: 2)
  - [x] Add to the `forecast` block in `config/tripcast.php`: `'retention_days' => max(1, (int) env('TRIPCAST_FORECAST_RETENTION_DAYS', 30))` (floored at 1). Document it as the AD-16 retention horizon (anchored on `return_date`).
- [x] **Task 2 — `PurgeForecastHistory` action** (AC: 2)
  - [x] Create `app/Actions/PurgeForecastHistory.php` with `handle(?CarbonInterface $today = null): int` (default `now('America/New_York')`, the send clock AD-7). Compute `$cutoff = $today->toDateString()` minus `retention_days`. Null `weather_snapshot` for every `email_logs` row whose owning Trip's `return_date <= $cutoff` and whose snapshot is still present:
    ```php
    return EmailLog::query()
        ->whereNotNull('weather_snapshot')
        ->whereIn('trip_id', Trip::withTrashed()
            ->whereDate('return_date', '<=', $cutoff)
            ->select('id'))
        ->update(['weather_snapshot' => null]);
    ```
    `withTrashed()` is **required** — a soft-deleted Trip's snapshots must still age out (anchored on `return_date`, not status). The `update` touches **only** `weather_snapshot`; the send-outcome columns are untouched. Return the affected-row count.
  - [x] Docblock: this is the **one** intentional lifecycle mutation on the snapshot payload (AD-16) — nulls the forecast figures, never the outcome row; anchored on `return_date` so it can never race AD-17's in-window prior-snapshot read (Story 4.2).
- [x] **Task 3 — Run the sweep in the daily command** (AC: 2)
  - [x] In `app/Console/Commands/SendDailyDigests.php@handle`, after the select/dispatch succeeds, run the purge as a **selection-only maintenance sweep** — **not** inside the send job. Inject/resolve `PurgeForecastHistory` and call it inside its **own** `try/catch (Throwable)` that logs a warning and continues: a purge failure must **not** fail the digest run or flip the heartbeat (the digests already dispatched; the run's health is about dispatch, AD-14). Log the purged count: `Log::info('digests:purge', ['purged' => $count])`. Keep the existing dispatch/heartbeat/exit-code logic unchanged. Update the class docblock (the `PurgeForecastHistory` sweep now lives here; `CompleteExpiredTrips` remains its own later story).
- [x] **Task 4 — Tests** (AC: 1, 2)
  - [x] `tests/Feature/Digest/PurgeForecastHistoryTest.php` (pin the ET clock): a Trip whose `return_date` is **> retention_days ago** → its `email_logs` rows have `weather_snapshot` **nulled**, but `status`/`failure_reason`/`send_date` **survive** (assert the row still exists with its outcome). A Trip whose `return_date` is **recent** (or in the future) → snapshot **untouched**. **Anchor proof:** a row with an **old `send_date` but a recent `return_date`** is **not** purged (anchored on return_date, not send_date). A **soft-deleted** Trip past retention → still purged. Already-null snapshots are a no-op. The action returns the purged count. `retention_days` is **configurable** (`config(['tripcast.forecast.retention_days' => N])`).
  - [x] Extend `tests/Feature/Digest/SendDailyDigestsTest.php`: the daily command **invokes** the purge (seed a past-retention snapshot, run `digests:send`, assert it's nulled), and a **purge failure does not fail the run** (mock `PurgeForecastHistory` to throw → command still `assertSuccessful()` and dispatches normally).
  - [x] **AC1 diffability** (lightweight): a test asserting two `(trip_id, send_date)` rows with different `weather_snapshot` payloads are independently readable/diffable (the series exists) — a small guard that the snapshot cast round-trips arrays.
  - [x] **Gates:** `./vendor/bin/pest`, `vendor/bin/pint --dirty --format agent`, `./vendor/bin/phpstan analyse`, `npm run types:check`, `npm run lint:check`, `npm run build:ssr` (frontend unchanged — should be untouched/green).

## Dev Notes

### Scope boundary (read first)
- **In scope:** the retention config, the `PurgeForecastHistory` action, wiring it into the daily command, and tests. **Out of scope:** the **day-over-day diff / narration** (Story 4.2 — AD-17), the **`CompleteExpiredTrips` completion sweep** (AD-5, still a separate later story — do **not** build it here), and any new table (AD-16 forbids a second store). Frontend untouched. [Source: epics.md#Story-4.1; ARCHITECTURE-SPINE.md#AD-16]

### Architecture (binding)
- **AD-16 — forecast history is a bounded-retention sweep over `email_logs`:** "forecast history needs **no new store** — it is the `email_logs` snapshot time-series (AD-9). A scheduled retention sweep purges by **nulling only `weather_snapshot`** ~30 days after the owning Trip's Return Date, **anchored on `trip.return_date` — never on `send_date`/`created_at`** (so it can never race AD-17's in-window prior-snapshot read). The **send-outcome row survives** the purge (`status`, `failure_reason`, dates, `claimed_at`) … only the forecast payload ages out. This purge is the **one intentional lifecycle** on that store. The sweep runs **in the daily scheduler command** … selection-only, like the other sweeps — not inside the send job." [Source: ARCHITECTURE-SPINE.md#AD-16, lines 141-144]
- **AD-9 — `email_logs` is the sole source of truth + the forecast-history series:** "each `(trip_id, send_date)` holds one snapshot until purged per AD-16, then absent (the send-outcome row survives), so the row series **is** the day-by-day forecast-history time-series … history/admin readers must tolerate a purged (snapshot-absent) row." [Source: ARCHITECTURE-SPINE.md#AD-9, line 109]

### Code intel (exact patterns to reuse)
- **`EmailLog`** (`app/Models/EmailLog.php`): `weather_snapshot` is `nullable` + `array` cast; `trip()` BelongsTo; status consts. No factory — seed rows in tests via `$trip->emailLogs()->create([... 'weather_snapshot' => [...]])` (fillable covers it). [Source: app/Models/EmailLog.php]
- **`Trip`**: `return_date` is a `date` cast; SoftDeletes (so the purge must `withTrashed()` to reach deleted trips' logs); `emailLogs()` HasMany. [Source: app/Models/Trip.php]
- **Daily command** (`app/Console/Commands/SendDailyDigests.php`): `handle(CadencePredicate $cadence): int` — currently select → dispatch → `recordRun` → `emitHeartbeat` → exit code. Add the purge **after** the dispatch try-block, in its own guarded try/catch so it can't affect the run health/heartbeat (AD-14). The command already imports `Throwable`, `Log`. To inject the action, add a second method param `PurgeForecastHistory $purge` (the container resolves it) **or** `app(PurgeForecastHistory::class)` — match the existing DI style (the command type-hints `CadencePredicate` in `handle`, so add `PurgeForecastHistory` the same way). [Source: app/Console/Commands/SendDailyDigests.php]
- **Action style**: `app/Actions/*` are plain invokable-ish service classes with a `handle(...)` method (see `CreateTrip`, `SendWelcomeEmail`). No constructor needed here. [Source: app/Actions/]
- **Config floor idiom**: `max(1, (int) env(...))` — mirror for `retention_days`. [Source: config/tripcast.php]
- **Mass `update` of a cast column to null**: `->update(['weather_snapshot' => null])` writes SQL NULL directly (bypasses the array cast, which is correct — we want NULL, not `"null"`). [Source: Eloquent]

### Testing standards
- Pest, `RefreshDatabase`, pinned ET clock (`Carbon::setTestNow`). Build trips with `Trip::factory()->for($user)` (+ `past()` / explicit `return_date`), seed `email_logs` via `$trip->emailLogs()->create([...])`. Assert `weather_snapshot` null after purge and the outcome columns intact (`assertDatabaseHas('email_logs', ['id' => …, 'status' => 'sent', 'weather_snapshot' => null])`). For the command test, mock the action to throw (`$this->mock(PurgeForecastHistory::class)`) for the "purge failure never fails the run" case; the existing `Queue::fake()`/`Http::fake()`/clock-pin setup stays. [Source: tests/Feature/Digest/SendDailyDigestsTest.php]

### Project Structure Notes
- **New:** `app/Actions/PurgeForecastHistory.php`, `tests/Feature/Digest/PurgeForecastHistoryTest.php`.
- **Modified:** `config/tripcast.php` (`forecast.retention_days`), `app/Console/Commands/SendDailyDigests.php` (run the sweep), `tests/Feature/Digest/SendDailyDigestsTest.php`.
- **Unchanged:** `EmailLog`/`Trip` schema (no migration), the send job, the frontend.

### Previous story intelligence (Epic 2 + Epic 3)
- The daily command was built thin in 2.2 and wrapped with run-liveness in 2.7 — keep the dispatch/heartbeat semantics identical; the purge is an **additional** guarded sweep, never a reason the run fails (same discipline as AD-4's never-break-the-send and AD-14's monitoring-never-breaks-the-run). [Source: app/Console/Commands/SendDailyDigests.php]
- `now()` is `CarbonImmutable` app-wide — type the action's `$today` param `CarbonInterface` (the trap noted in 2.7/3.x). `whereHas` on an `update` would respect the Trip SoftDeletes scope and silently skip deleted trips' logs — that's why the spec uses an explicit `withTrashed()` subquery.

### References
- [Source: _bmad-output/planning-artifacts/epics.md#Story-4.1]
- [Source: _bmad-output/planning-artifacts/architecture/architecture-tripcast-2026-06-28/ARCHITECTURE-SPINE.md#AD-16, #AD-9]
- [Source: _bmad-output/specs/spec-tripcast/SPEC.md#FR-15]
- [Source: app/Models/EmailLog.php; app/Models/Trip.php; app/Console/Commands/SendDailyDigests.php; config/tripcast.php]

## Dev Agent Record

### Agent Model Used

claude-opus-4-8 (1M context)

### Debug Log References

- None — clean implementation. `whereIn('trip_id', Trip::withTrashed()->...->select('id'))` is a single subquery (no id materialization) and reaches soft-deleted trips' logs, which a `whereHas` would have silently skipped.

### Completion Notes List

- **Task 1 — config:** added `tripcast.forecast.retention_days` (env `TRIPCAST_FORECAST_RETENTION_DAYS`, default 30, floored at 1).
- **Task 2 — `PurgeForecastHistory`:** nulls `email_logs.weather_snapshot` for rows whose owning Trip's `return_date <= today − retention_days` (anchored on return_date; `withTrashed()` so soft-deleted trips age out too). `update()` writes SQL NULL, leaving the send-outcome columns; returns the purged count.
- **Task 3 — daily command:** `SendDailyDigests@handle` now resolves `PurgeForecastHistory` and runs it after the dispatch succeeds, in its own `try/catch (Throwable)` that logs `digests:purge` (count) or a warning on failure — never failing the run or flipping the heartbeat (AD-14). Dispatch/heartbeat/exit semantics unchanged.
- **Task 4 — tests:** `PurgeForecastHistoryTest` (past-horizon nulled + outcome row survives; within-horizon untouched; **anchored on return_date not send_date**; soft-deleted purged; already-null no-op; configurable horizon; consecutive captures diffable) + two `SendDailyDigestsTest` cases (command invokes the purge; a thrown purge still `assertSuccessful` and dispatches). 9 new tests.
- **Verification:** full suite **195 passed** (739 assertions). `pint` clean, `phpstan` 0 errors, `types`/`lint`/`build:ssr` green (frontend untouched). No new table (AD-16).

### File List

**New:**
- `app/Actions/PurgeForecastHistory.php`
- `tests/Feature/Digest/PurgeForecastHistoryTest.php`

**Modified:**
- `config/tripcast.php` (`forecast.retention_days`)
- `app/Console/Commands/SendDailyDigests.php` (run the retention sweep, guarded)
- `tests/Feature/Digest/SendDailyDigestsTest.php`

### Change Log

- 2026-06-30 — Implemented Story 4.1: forecast-history retention. The `email_logs` snapshot series is the (diffable) forecast history; a guarded `PurgeForecastHistory` sweep in the daily command nulls `weather_snapshot` ~30 days (configurable) after each Trip's return_date — anchored on return_date, reaching soft-deleted trips, leaving the send-outcome row intact. No new store. All gates green.

### Review Findings

_Code review 2026-06-30 (Epic 4 adversarial pass: Blind Hunter + Edge Case Hunter + Acceptance Auditor)_

- [ ] [Review][Patch] Purge does not clamp `retention_days` at runtime — the `max(1, …)` floor lives only in the config default (env-time); a runtime `config(['tripcast.forecast.retention_days' => 0])` or negative value sets cutoff >= today and nulls in-window snapshots still needed for day-over-day narration + AD-9 history. Add `max(1, (int) config(...))` in the action [app/Actions/PurgeForecastHistory.php:35-37]
- [ ] [Review][Patch] `whereDate('return_date', …)` defeats an index — `$cutoff` is already a date string, so `where('return_date', '<=', $cutoff)` is index-friendly on a large table [app/Actions/PurgeForecastHistory.php:45]
- [x] [Review][Defer] Purge is one unbounded UPDATE (no chunking) — single `whereIn(...)->update()` can hold locks on `email_logs` under a large backlog while in-flight send claims touch the same table; acceptable at MVP scale [app/Actions/PurgeForecastHistory.php:42-47] — deferred, MVP-acceptable
- [x] [Review][Dismiss] Purge `<=` cutoff vs "older than" docstring — behavior matches AD-16 ("~30 days after Return Date"); prose-only nuance, not a defect [app/Actions/PurgeForecastHistory.php:35-46]
