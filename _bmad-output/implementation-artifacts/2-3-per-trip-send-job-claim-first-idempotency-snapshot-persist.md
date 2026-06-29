---
baseline_commit: 400453fbc32ab4a3d4a60cbe6c0a10f5234fc65a
---

# Story 2.3: Per-trip send job — claim-first idempotency + snapshot persist

Status: review

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

As the system,
I want each per-trip job to claim its send row before doing work and persist the forecast once,
so that retries and concurrent workers never double-send or re-fetch weather.

## Acceptance Criteria

**AC1 — Claim-first via a unique `(trip_id, send_date)` row** *(FR-6, AD-3)*
- **Given** an `email_logs` table with a **unique index on `(trip_id, send_date)`**
- **When** a `SendTripDigest` job starts
- **Then** it inserts the log row (`status = sending`, with a `claimed_at` lease) **before** fetching anything; a **duplicate insert fails the unique constraint and the job aborts** as already-claimed (no second send).

**AC2 — Forecast fetched once, snapshot persisted before delivery** *(FR-11, AD-3, AD-9)*
- **Given** a claimed row
- **When** the forecast is fetched (the `WeatherProvider`, Story 2.1)
- **Then** it is fetched **once** and its snapshot persisted on the claimed row **before** any delivery — so a later delivery retry (Story 2.4) never re-fetches weather.

**AC3 — Stale-lease rows are reclaimable; weather failure is terminal, not stuck** *(AD-3, AD-4)*
- **Given** a row stuck in `sending` past a stale-lease threshold (a crash mid-send)
- **When** a later run processes that trip
- **Then** it is **reclaimable** (lease refreshed, processing resumes); a `sent`/`failed` row or a fresh in-flight `sending` row is **not** reclaimed (abort).
- **And** if the forecast fetch fails, the row reaches a **terminal `failed` + reason** (never left stuck in `sending`, never a broken digest) — recovery is the next day's run (a new `send_date`).

## Tasks / Subtasks

- [x] **Task 1 — `email_logs` table + `EmailLog` model (AD-9 owns this schema)** (AC: 1, 2)
  - [x] Migration: `trip_id` (FK cascade), `send_date` (date), `status` default `sending`, `claimed_at` (lease), `failure_reason`, `weather_snapshot` (json), timestamps; `unique(trip_id, send_date)` (AD-3); `(status, claimed_at)` index
  - [x] `EmailLog` model: `belongsTo(Trip)`, casts (send_date date, claimed_at datetime, weather_snapshot array), `STATUS_SENDING|SENT|FAILED`; `Trip::emailLogs()`
  - [x] Only `email_logs` created
- [x] **Task 2 — Forecast snapshot serialization** (AC: 2)
  - [x] `Forecast::toArray()` (`{days:[…], limited:bool}`) + `ForecastDay::toArray()` (date/condition/precip/highC/F/lowC/F)
- [x] **Task 3 — `SendTripDigest`: claim → fetch once → persist snapshot** (AC: 1, 2, 3)
  - [x] Claim: `EmailLog::create([sending, claimed_at])`; on `UniqueConstraintViolationException`, **reclaim only a STALE `sending` row** via atomic conditional UPDATE (`status=sending AND claimed_at < now−staleLease`); fresh-sending/sent/failed → abort
  - [x] Fetch once via injected `WeatherProvider`; persist `weather_snapshot = $forecast->toArray()` before delivery
  - [x] Weather failure → `status=failed` + reason, return (AD-4; no broken digest, no stuck row)
  - [x] Row left `sending` with snapshot for Story 2.4; `tries = 1` retained
  - [x] `config('tripcast.send.stale_lease_minutes')` (default 30) added
- [x] **Task 4 — Tests** (AC: 1, 2, 3)
  - [x] `unique(trip_id, send_date)` enforced (duplicate throws)
  - [x] Claims first + fetches **once** (Mockery) + persists snapshot
  - [x] Fresh `sending` / `sent` rows → abort (weather not fetched)
  - [x] Stale `sending` row → reclaimed (lease refreshed) + snapshot persisted
  - [x] Weather throws → `failed` + reason, no snapshot, no exception escapes
  - [x] Snapshot round-trips (toArray shape; JSON normalizes whole floats — asserted by value)

## Dev Notes

### Scope boundary (read first)
- This story is **claim + fetch-once + persist snapshot**. **Rendering the Blade digest and delivering it (with bounded in-process retry, setting the terminal `sent`/`failed`) is Story 2.4** — 2.3 deliberately leaves the claimed row in `status=sending` with the snapshot ready. The **footer email-action links (2.5), feedback (2.6), heartbeat (2.7)** are separate. Do **not** create `feedback`/`promo_events` here. [Source: epics.md#Story-2.4, #Story-2.5–2.7]
- `email_logs` is created **once, here** — AD-9 is its sole schema owner. Later stories (AD-4 status writes in 2.4, AD-16 purge in 4.1, AD-17 read in 4.2) consume/mutate it but do **not** redefine it. [Source: ARCHITECTURE-SPINE.md#AD-9]

### Architecture (binding)
- **AD-3 — idempotency via claim-first unique constraint:** `email_logs` has a **DB unique index on `(trip_id, send_date)`**. The job inserts the row (`status=sending`, `claimed_at` lease) **before** fetching/sending; a duplicate insert fails the constraint → the job aborts as already-claimed. The forecast is **fetched once** and its snapshot persisted on the claimed row **before** delivery, so a delivery retry never re-fetches (AD-4). **A row stuck in `sending` past a stale-lease threshold (crash mid-send) is reclaimable** by a later run; absent reclamation that trip simply misses *that* day's digest and resumes next day — an accepted, logged loss, not corruption. **The row is the dedup authority — not an in-memory check.** [Source: ARCHITECTURE-SPINE.md#AD-3]
- **AD-9 — `email_logs` is the single per-send source of truth + forecast-history series:** each row carries the send outcome (`sent`/`failed` + reason) **and** the weather snapshot for that send. Forecasts are cached **nowhere else** — fresh fetch every morning. The `(trip_id, send_date)` row series **is** the forecast-history time-series (FR-15). Persist a self-contained snapshot (the `Forecast::toArray()` shape). [Source: ARCHITECTURE-SPINE.md#AD-9]
- **AD-4 — bounded retry, always terminal, never a broken digest:** the job runs `tries = 1` (already set on the shell in 2.2) — Laravel must never re-dispatch it (a re-dispatch would hit its own claim). Delivery retry is in-process ≤3× (Story 2.4, on delivery only — weather is already snapshotted). The job **always reaches a terminal state**: 2.4 sets `sent` or `failed`+reason; **this story** handles the *fetch-failure* path → `failed`+reason (so no row is left in `sending` by a weather outage). [Source: ARCHITECTURE-SPINE.md#AD-4; EXPERIENCE.md State Patterns "Weather API down → send nothing"]
- **AD-1/AD-7 (carried):** weather via the `WeatherProvider` port by coordinates (Story 2.1); `send_date` is the America/New_York date the command passed (Story 2.2). [Source: 2-1, 2-2]

### Claim mechanics (concrete)
- Insert-then-catch is the claim: `try { EmailLog::create([trip_id, send_date, status=sending, claimed_at=now]); } catch (QueryException) { …reclaim-or-abort… }`. The unique index makes the **insert** the atomic claim (the row is the authority, AD-3 — not a SELECT-then-INSERT check).
- Reclaim a stale row with a **conditional UPDATE** and check affected-rows (so two concurrent reclaimers can't both win): `EmailLog::where('trip_id',…)->where('send_date',…)->where('status', SENDING)->where('claimed_at','<', now()->subMinutes($stale))->update(['claimed_at'=>now()])` → proceed only if it returns 1.
- Detecting "the insert failed because of the unique index" specifically: catch `Illuminate\Database\UniqueConstraintViolationException` (Laravel maps it) — or `QueryException` and re-throw if it's not a duplicate. Prefer `UniqueConstraintViolationException`.

### `Forecast::toArray()` shape
- Keep it explicit and stable (downstream 2.4/4.x depend on it), e.g. `['days' => [['date','conditionText','precipChance','highC','highF','lowC','lowF'], …], 'limited' => bool]`. `weather_snapshot` cast = `array`, so it round-trips as associative arrays (the renderer in 2.4 maps these back, not the DTO objects — or rehydrate if convenient).

### Testing standards
- Pest feature tests, MySQL `tripcast_test`, `RefreshDatabase`. Build trips via `User::factory()->confirmed()` + `$user->trips()->create([...])`. Drive the job directly: `(new SendTripDigest($trip, '2026-06-29'))->handle($weather)` or `app()->call([...])`. Use `Mockery`/`$this->mock(WeatherProvider::class)` to assert `fetchForecast` is called **once** / **never** (abort path), and to inject a known `Forecast` (and to throw `WeatherProviderFailedException` for the failure path). Pin the clock for stale-lease tests (`Carbon::setTestNow`). [Source: 2-1/2-2 patterns]
- Assert the row's `status`/`claimed_at`/`weather_snapshot` directly; assert duplicate insert throws at the DB (migration test).
- Gates: `./vendor/bin/pest`, `./vendor/bin/pint`, `./vendor/bin/phpstan analyse`, `npm run types:check`, `npm run lint:check`, `npm run build:ssr` (frontend unchanged).

### Project Structure Notes
- New: `database/migrations/..._create_email_logs_table.php`, `app/Models/EmailLog.php`, tests; **modified:** `app/Jobs/SendTripDigest.php` (claim/fetch/persist), `app/Models/Trip.php` (`emailLogs()`), `app/Services/Weather/{Forecast,ForecastDay}.php` (`toArray()`), `config/tripcast.php` (`send.stale_lease_minutes`). No frontend/routes. [Source: ARCHITECTURE-SPINE.md#Structural-Seed]

### Previous story intelligence (Stories 2.1, 2.2, 1.4)
- **`SendTripDigest`** already exists as a `ShouldQueue` shell with `public int $tries = 1;` and `__construct(public Trip $trip, public string $sendDate)` (Story 2.2) — flesh `handle()`. Inject the `WeatherProvider` into `handle(WeatherProvider $weather)` (method injection is resolved by the queue). [Source: 2-2 SendTripDigest]
- **`WeatherProvider::fetchForecast(lat, lng): Forecast`** (Story 2.1) returns `Forecast{ days: ForecastDay[] }` with both °C/°F + precip + condition + `isLimited()`; bound to the real adapter (key is live) / fake. Tests should mock the port, not hit the network. [Source: 2-1 File List]
- **`Trip`** has `latitude`/`longitude` (float casts), `SoftDeletes`, `belongsTo(User)`. Add `emailLogs()` `hasMany`. The `(trip_id, send_date)` claim mirrors the **atomic conditional-UPDATE** pattern already used for magic-link consume (Story 1.1 review) — reuse that discipline. [Source: 1-4 Trip; 1-1 consume]
- **`config/tripcast.php`** exists (magic-link block) — add a `send` block beside it. [Source: 1-1 config/tripcast.php]
- Quality lessons: run **PHPStan**; catch the *specific* unique-violation exception; conditional UPDATE for the reclaim race; pin the clock in lease tests.

### References
- [Source: _bmad-output/planning-artifacts/epics.md#Story-2.3] (+ #Story-2.4 for the delivery boundary)
- [Source: _bmad-output/planning-artifacts/architecture/architecture-tripcast-2026-06-28/ARCHITECTURE-SPINE.md#AD-3, #AD-9, #AD-4, #AD-1, #AD-7, #Structural-Seed, #Send-pipeline]
- [Source: _bmad-output/planning-artifacts/ux-designs/ux-tripcast-2026-06-28/EXPERIENCE.md#State-Patterns ("Weather API down", "Send failure")]
- [Source: _bmad-output/specs/spec-tripcast/SPEC.md#FR-6, #FR-11]

## Dev Agent Record

### Agent Model Used

Amelia (Senior Software Engineer) — claude-opus-4-8[1m]

### Debug Log References

- TDD (6 new tests, Mockery for the port). Full suite: `./vendor/bin/pest` 84 passed / 292 assertions. PHPStan 0, Pint, vue-tsc, build green.

### Completion Notes List

- `email_logs` table (AD-9 source of truth) with **`unique(trip_id, send_date)`** (the AD-3 claim authority) + `(status, claimed_at)` index; `EmailLog` model with `weather_snapshot` array cast + status constants; `Trip::emailLogs()`.
- `SendTripDigest@handle(WeatherProvider)`: **claim-first** — `EmailLog::create([sending, claimed_at])` is the atomic claim; on `UniqueConstraintViolationException`, reclaim **only a stale `sending` row** via a conditional UPDATE (affected-rows check, no double-reclaim); fresh-sending/sent/failed → abort. Then fetch the forecast **once** and persist `weather_snapshot = $forecast->toArray()` before any delivery (AD-3/AD-9). Weather fetch failure → terminal `failed` + reason (AD-4; never stuck in `sending`, never a broken digest). `tries = 1`.
- `Forecast::toArray()` / `ForecastDay::toArray()` give the stable snapshot shape (2.4 render + 4.x history/narration read it). Note: MySQL JSON normalizes whole floats (20.0 → 20) — harmless; non-whole temps round-trip exactly.
- `config('tripcast.send.stale_lease_minutes')` (default 30) governs reclaim.
- **Scope held:** row left in `sending` with the snapshot for **Story 2.4** (render + deliver + terminal status). No `feedback`/`promo_events`; no heartbeat (2.7).

### File List

**Created**
- `database/migrations/2026_06_29_000003_create_email_logs_table.php`
- `app/Models/EmailLog.php`
- `tests/Feature/Digest/SendTripDigestTest.php`

**Modified**
- `app/Jobs/SendTripDigest.php` — claim-first + fetch-once + persist snapshot + weather-fail terminal
- `app/Models/Trip.php` — `emailLogs()` relation
- `app/Services/Weather/Forecast.php` · `ForecastDay.php` — `toArray()` snapshot serialization
- `config/tripcast.php` — `send.stale_lease_minutes`

### Change Log

| Date | Change |
| --- | --- |
| 2026-06-29 | Story 2.3 implemented: `email_logs` (AD-9, unique trip+send_date AD-3) + `EmailLog`; `SendTripDigest` claims first, fetches forecast once, persists snapshot before delivery, reclaims stale leases, terminal-fails on weather error (AD-4). 6 new tests (84 total). Status → review. |
