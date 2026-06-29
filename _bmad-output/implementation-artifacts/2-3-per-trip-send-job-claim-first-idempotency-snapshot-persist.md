# Story 2.3: Per-trip send job — claim-first idempotency + snapshot persist

Status: ready-for-dev

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

- [ ] **Task 1 — `email_logs` table + `EmailLog` model (AD-9 owns this schema)** (AC: 1, 2)
  - [ ] Migration `create_email_logs_table`: `trip_id` (FK→trips, cascade), `send_date` (date), `status` (string `sending|sent|failed`), `claimed_at` (datetime nullable, the lease), `failure_reason` (text nullable), `weather_snapshot` (**json/longtext nullable** — the per-send forecast snapshot, AD-9), timestamps; **`unique(trip_id, send_date)`** (AD-3); index `(status, claimed_at)` for stale-lease scans
  - [ ] `app/Models/EmailLog.php`: `belongsTo(Trip)`; casts (`send_date` → date, `claimed_at` → datetime, `weather_snapshot` → array); status constants `STATUS_SENDING|SENT|FAILED`; `Trip::emailLogs()` `hasMany`
  - [ ] Create **only** `email_logs` here — `feedback`/`promo_events` are later epics
- [ ] **Task 2 — Forecast snapshot serialization** (AC: 2)
  - [ ] Add `toArray(): array` to `Forecast` + `ForecastDay` (Story 2.1 DTOs) producing a stable, JSON-safe shape (days with date/condition/precip/highC/highF/lowC/lowF + the `limited` flag) — this is what persists in `weather_snapshot` and what Stories 2.4 (render) and 4.x (history/narration) read back
- [ ] **Task 3 — `SendTripDigest`: claim → fetch once → persist snapshot** (AC: 1, 2, 3)
  - [ ] **Claim** in `handle()`: attempt `EmailLog::create([...sending, claimed_at=now...])`; on the unique-violation `QueryException`, load the existing row and **reclaim only a STALE `sending` row** via an **atomic conditional UPDATE** (`WHERE status=sending AND claimed_at < now − staleLease`); a `sent`/`failed` row or a fresh `sending` row → **abort** (return, no work)
  - [ ] **Fetch once**: resolve the `WeatherProvider` (DI) and `fetchForecast($trip->latitude, $trip->longitude)` exactly once; persist `weather_snapshot = $forecast->toArray()` on the claimed row **before** any delivery
  - [ ] **Weather failure**: catch `WeatherProviderFailedException` → set the row `status = failed`, `failure_reason` → **return** (never a broken digest, AD-4; next day's run retries with a new `send_date`)
  - [ ] Leave the row `status = sending` with the snapshot persisted for **Story 2.4** to render + deliver and set the terminal `sent`/`failed`; mark this seam clearly. Keep `tries = 1` (AD-4)
  - [ ] Stale-lease threshold from config: `config('tripcast.send.stale_lease_minutes')` (default e.g. 30) — add a `send` block to `config/tripcast.php`
- [ ] **Task 4 — Tests** (AC: 1, 2, 3)
  - [ ] Migration: `unique(trip_id, send_date)` enforced (duplicate insert throws)
  - [ ] Job claims first: running `handle()` creates one `email_logs` row `status=sending` with `claimed_at`, then persists `weather_snapshot`; assert the `WeatherProvider` was called **once** (mock/spy)
  - [ ] Duplicate/concurrent: a fresh `sending` row already present → the job **aborts** (weather **not** fetched, no new row, existing row untouched); a `sent` row → aborts; a `failed` row → aborts
  - [ ] Stale-lease reclaim: a `sending` row with `claimed_at` older than the threshold → reclaimed (lease refreshed) and the snapshot persisted
  - [ ] Weather failure: `WeatherProvider` throws → row ends `status=failed` with a reason, **no** snapshot, no exception escapes the job
  - [ ] Snapshot round-trips: `weather_snapshot` reloads to the same day/temp/precip values (`Forecast::toArray()` shape)

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

### Debug Log References

### Completion Notes List

### File List
