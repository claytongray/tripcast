---
baseline_commit: 5b76a980c0b372070ba9939b8b72fce1f0455aa5
---

# Story 2.2: Cadence predicate + daily command that selects and dispatches

Status: review

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

As the system,
I want one cadence predicate and a command that dispatches a job per due Trip,
so that "is a digest due today" has a single authority and the send is the pre-built scaling seam.

## Acceptance Criteria

**AC1 — One cadence predicate is the authority** *(FR-6, AD-11, AD-7, AD-13, AD-6)*
- **Given** a single cadence predicate
- **When** it evaluates a Trip for date D (the **America/New_York** calendar date as "today")
- **Then** the Trip is **due ⟺ `status == active` AND `deleted_at` is null AND the owner has confirmed their email (`email_verified_at` not null) AND the owner is not opted out AND D ∈ `[DepartureDate − 7 days, ReturnDate]`** — anything paused, completed, soft-deleted, owner-unconfirmed, or opted-out is **not due** (status/confirmation checked, not just the dates).

**AC2 — The scheduled command only selects + dispatches** *(FR-6, AD-2)*
- **Given** the scheduled `SendDailyDigests` command runs
- **When** it executes
- **Then** it computes the due set via the predicate and **dispatches one `SendTripDigest` job per due Trip** (to Redis) — the command does **no per-trip work inline** (claim/fetch/render/send all live in the job, Story 2.3).

**AC3 — Window + status exclusions hold** *(FR-6)*
- **And** no digest is dispatched for a Trip **more than 7 days before Departure**, a **paused** Trip gets none, and (AC1) an **unconfirmed or opted-out** owner's Trips get none.

## Tasks / Subtasks

- [x] **Task 1 — The cadence predicate (single authority, AD-11)** (AC: 1, 3)
  - [x] `app/Digest/CadencePredicate.php`: `isDue(Trip, CarbonInterface): bool` (all clauses), `dueOn(CarbonInterface): Collection<Trip>` (DB query: active + confirmed + not opted out + `departure<=D+7` + `return>=D`; SoftDeletes excludes deleted), `daysUntilDeparture(Trip, CarbonInterface): int`
  - [x] Date math anchored to America/New_York (AD-7); compares on **calendar-date strings** so naive DATE columns and ET "today" never disagree by a tz offset
- [x] **Task 2 — `SendTripDigest` job (stub)** (AC: 2)
  - [x] `app/Jobs/SendTripDigest.php` implements `ShouldQueue`; `public int $tries = 1;` (AD-4); constructor `(Trip $trip, string $sendDate)`; `handle()` is a marked placeholder for Stories 2.3/2.4
- [x] **Task 3 — `SendDailyDigests` command + schedule** (AC: 2, 3)
  - [x] `app/Console/Commands/SendDailyDigests.php` (`digests:send`): ET today → `dueOn()` → dispatch one `SendTripDigest` per trip (`send_date = today`); no per-trip work inline (AD-2)
  - [x] `routes/console.php`: `Schedule::command('digests:send')->dailyAt('09:00')->timezone('America/New_York')` (verified `0 13 * * *` UTC = 09:00 ET)
  - [x] Documented seam for the CompleteExpiredTrips/PurgeForecastHistory sweeps + heartbeat (not built here)
- [x] **Task 4 — Tests** (AC: 1, 2, 3)
  - [x] Predicate truth table (clock pinned, ET): in-window due; paused/completed/soft-deleted/unconfirmed/opted-out not due; boundaries D=departure−7 due, D=return due, D=return+1 not, D=departure−8 not
  - [x] `dueOn()` returns exactly the due trips for a mixed dataset
  - [x] Command (`Queue::fake()`): one `SendTripDigest` per due trip with correct `send_date`; nothing for non-due

## Dev Notes

### Scope boundary (read first)
- This story is **the predicate + the selector command + a dispatchable job shell**. The **per-trip work is Story 2.3** (claim-first `email_logs` row + snapshot persist) **and 2.4** (render + delivery). The **`email_logs` table does not exist yet — do not create it.** The `CompleteExpiredTrips` sweep (AD-5), `PurgeForecastHistory` sweep (AD-16, Story 4.1), and the **run heartbeat (AD-14, Story 2.7)** are explicitly **not** in this story — leave seams. [Source: epics.md#Story-2.3, #Story-2.4, #Story-2.7, #Story-4.1]

### Architecture (binding)
- **AD-11 — cadence lives in ONE predicate:** a single class is the authority for "is this Trip due a Daily Digest on date D". Due ⟺ `status==active` AND `deleted_at` null AND **owner `email_verified_at` not null (AD-6)** AND owner not opted out (AD-13) AND D ∈ `[Departure−7, Return]`. Both the send selector **and** the dashboard "days until" derive from this — never a second implementation. The Welcome Email is separate (fires at creation/confirmation, FR-9). [Source: ARCHITECTURE-SPINE.md#AD-11]
- **AD-2 — command selects + dispatches; the job does the work:** `SendDailyDigests` does exactly two things — compute the due set and dispatch one `SendTripDigest` per Trip (Redis). All per-trip work (claim, fetch, render, send, log, retry) lives in the job. **v1 dispatches to Redis; running sync vs worker is config, never structure.** [Source: ARCHITECTURE-SPINE.md#AD-2]
- **AD-4 — the job runs with `tries = 1`** (Laravel's queue must never re-dispatch it; a re-dispatch would later hit its own claim row). Set it now on the shell so 2.3 inherits it. [Source: ARCHITECTURE-SPINE.md#AD-4]
- **AD-7 — the send clock is fixed America/New_York:** "today" = `now('America/New_York')->toDateString()`; the schedule runs `dailyAt('09:00')->timezone('America/New_York')`. `users.timezone` is **not** consulted for sends in v1 (reading it for scheduling is a violation). Window math uses the naive `DATE` columns. [Source: ARCHITECTURE-SPINE.md#AD-7, #Consistency-Conventions "Dates & times"]
- **Window algebra:** D ∈ [Departure−7, Return] ⟺ `departure_date <= D+7` AND `return_date >= D`. Use that form for the SQL selector so it's index-friendly (there's a `(user_id, status)` index from Story 1.4; status + date range filter is fine at v1 volume). [Source: ARCHITECTURE-SPINE.md#AD-11, #Send-pipeline diagram]
- **Naming/structure:** predicate in `app/Digest/`; command in `app/Console/Commands/`; job in `app/Jobs/` — `SendDailyDigests`, `SendTripDigest` (verb-phrase). [Source: ARCHITECTURE-SPINE.md#Structural-Seed source tree, #Consistency-Conventions]

### Cadence predicate shape (concrete)
- `isDue(Trip $trip, CarbonInterface $date)`: `$trip->status === Trip::STATUS_ACTIVE && $trip->deleted_at === null && $trip->user->email_verified_at !== null && ! $trip->user->email_opted_out && $date->betweenIncluded($trip->departure_date->copy()->subDays(7), $trip->return_date)` (compare on calendar dates / startOfDay).
- `dueOn(CarbonInterface $date)`: `Trip::query()->where('status', Trip::STATUS_ACTIVE)->whereDate('departure_date', '<=', $date->copy()->addDays(7)->toDateString())->whereDate('return_date', '>=', $date->toDateString())->whereHas('user', fn ($q) => $q->whereNotNull('email_verified_at')->where('email_opted_out', false))->get()`. (SoftDeletes excludes deleted automatically.)
- Keep the two consistent — ideally `dueOn` is the query and `isDue` mirrors the same clauses; a test should assert they agree on a dataset.

### `SendTripDigest` shell
- `implements ShouldQueue; use Queueable; public int $tries = 1;` Constructor: `__construct(public Trip $trip, public string $sendDate)`. `handle(): void { /* Story 2.3: claim email_logs row, fetch forecast (WeatherProvider, 2.1), persist snapshot, then 2.4 render + send */ }`. Don't add `email_logs`/Weather wiring here. (It's queued to Redis via the default connection; tests use `Queue::fake()`.)

### Testing standards
- Pest feature tests, MySQL `tripcast_test`, `RefreshDatabase`. `Carbon::setTestNow(Carbon::parse('YYYY-MM-DD', 'America/New_York'))` to pin "today"; reset in `afterEach`. Use `Queue::fake()` + `Queue::assertPushed(SendTripDigest::class, N)` / `assertNotPushed`. Build trips via `User::factory()` (use `confirmed()` / `optedOut()` states) + `$user->trips()->create([...])`. [Source: 1-4 Trip model + factory states; 1-3/2-1 test patterns]
- For the predicate truth table, a dataset/`it(...)->with([...])` keeps it exhaustive. Assert `dueOn()->pluck('id')` equals the expected set.
- Gates: `./vendor/bin/pest`, `./vendor/bin/pint`, `./vendor/bin/phpstan analyse`, `npm run types:check`, `npm run lint:check`, `npm run build:ssr` (frontend unchanged).

### Project Structure Notes
- New only: `app/Digest/CadencePredicate.php`, `app/Jobs/SendTripDigest.php`, `app/Console/Commands/SendDailyDigests.php`, tests; **modified:** `routes/console.php` (schedule registration). No migrations, models, or frontend. [Source: ARCHITECTURE-SPINE.md#Structural-Seed]

### Previous story intelligence (Stories 1.4, 1.5, 2.1 + the sprint change)
- **`Trip`** has `STATUS_ACTIVE/PAUSED/COMPLETED` constants, `SoftDeletes`, `belongsTo(User)`, date casts (`departure_date`/`return_date` → CarbonImmutable). **`User`** has `email_verified_at` (datetime cast) + `hasConfirmedEmail()` and `email_opted_out` (bool) + factory `confirmed()`/`optedOut()` states. Reuse all of it — the predicate's clauses map directly. [Source: 1-4 Trip, 1-3/sprint-change User, 1-1 factory]
- **Email-confirmation gating (2026-06-29 sprint change)** is the reason for the `email_verified_at` clause — AD-11 was updated; this story is where the selector first enforces it. [Source: planning-artifacts/sprint-change-proposal-2026-06-29-email-confirmation.md]
- **`WeatherProvider`** (Story 2.1) is ready for the job to consume in 2.3 — not used here. [Source: 2-1 File List]
- Scheduling lives in `routes/console.php` (Laravel 11+ style) where the `model:prune` schedule already is (Story 1.1). Add the digest schedule alongside it. [Source: 1-1 routes/console.php]
- Quality lessons: run **PHPStan**; pin the clock in date tests; keep the command thin (AD-2).

### References
- [Source: _bmad-output/planning-artifacts/epics.md#Story-2.2] (+ #Story-2.3/2.4/2.7 for boundaries)
- [Source: _bmad-output/planning-artifacts/architecture/architecture-tripcast-2026-06-28/ARCHITECTURE-SPINE.md#AD-11, #AD-2, #AD-4, #AD-7, #AD-13, #AD-6, #Structural-Seed, #Send-pipeline]
- [Source: _bmad-output/specs/spec-tripcast/SPEC.md#FR-6]
- [Source: _bmad-output/planning-artifacts/sprint-change-proposal-2026-06-29-email-confirmation.md]

## Dev Agent Record

### Agent Model Used

Amelia (Senior Software Engineer) — claude-opus-4-8[1m]

### Debug Log References

- TDD (13 new tests). Full suite: `./vendor/bin/pest` 78 passed / 270 assertions. PHPStan 0, Pint, vue-tsc, build green.
- `php artisan schedule:list` shows `digests:send` at `0 13 * * *` (UTC) = 09:00 America/New_York.

### Completion Notes List

- `CadencePredicate` (`app/Digest/`) is the single AD-11 authority: `isDue`, `dueOn` (DB selector), `daysUntilDeparture`. Clauses: active + not soft-deleted + owner confirmed (`email_verified_at`, AD-6) + owner not opted out (AD-13) + D ∈ [departure−7, return].
- **Timezone fix:** `isDue`/`daysUntilDeparture` compare on **calendar-date strings** (Y-m-d), because trip DATE columns cast at UTC midnight while "today" is ET — an instant comparison was off by the offset (caught by the return-date-boundary test). `dueOn` uses `whereDate` + date strings (already calendar-correct).
- `SendDailyDigests` (`digests:send`) does only select + dispatch (AD-2); one `SendTripDigest` per due trip with `send_date = today` (ET). `SendTripDigest` is a `ShouldQueue` shell with `tries = 1` (AD-4) — claim/fetch/render/send land in 2.3/2.4.
- Scheduled `dailyAt('09:00')->timezone('America/New_York')` (AD-7) alongside the existing token prune.
- **Scope held:** no `email_logs` table/persistence (2.3), no render (2.4); CompleteExpiredTrips (AD-5), PurgeForecastHistory (4.1), and the heartbeat (2.7) are left as documented seams.

### File List

**Created**
- `app/Digest/CadencePredicate.php`
- `app/Jobs/SendTripDigest.php`
- `app/Console/Commands/SendDailyDigests.php`
- `tests/Feature/Digest/CadencePredicateTest.php` · `tests/Feature/Digest/SendDailyDigestsTest.php`

**Modified**
- `routes/console.php` — daily `digests:send` schedule (09:00 ET)

### Change Log

| Date | Change |
| --- | --- |
| 2026-06-29 | Story 2.2 implemented: single `CadencePredicate` (AD-11, incl. owner-confirmed clause), `SendDailyDigests` command selects + dispatches one `SendTripDigest` per due trip (AD-2), scheduled 09:00 ET (AD-7); job shell with `tries=1` (AD-4). 13 new tests (78 total). Status → review. |
