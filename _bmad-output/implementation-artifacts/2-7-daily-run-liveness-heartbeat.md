---
baseline_commit: d79abc2ac1f16cb1f587ede26659dfffae256e7f
---

# Story 2.7: Daily-run liveness heartbeat

Status: review

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

As the builder,
I want every scheduled run to report its own liveness,
so that a total failure (cron/queue/Redis down) can't go undetected.

## Acceptance Criteria

**AC1 — The run records its outcome and emits a heartbeat** *(NFR-3, AD-14)*
- **Given** the daily command (`digests:send`) runs
- **When** it finishes
- **Then** it records a **run-level outcome** (started/finished, trips **due**, **dispatched**, plus a duration) and **emits a heartbeat** to the configured monitor. Per-trip `sent`/`failed` continue to live in `email_logs` (AD-9); this is the **whole-run dead-man's-switch above them**.

**AC2 — A dead run or a no-op-when-due fires an out-of-band alert** *(NFR-1, AD-14)*
- **Given** a **missed** heartbeat (cron/queue/Redis down → the command never runs → the monitor never gets pinged) **or** a run that **finished with zero dispatched when trips were due**
- **When** monitoring evaluates it
- **Then** an **out-of-band alert** fires to the builder. The command turns the "due-but-nothing-dispatched" / mid-run-failure case into a **failure heartbeat** (so the monitor alerts immediately) and a **non-zero exit**; the "missed heartbeat" case is the external monitor's own timeout. A monitoring/ping outage must **never** break the product run.

## Tasks / Subtasks

- [x] **Task 1 — Heartbeat config** (AC: 1, 2)
  - [x] `config/tripcast.php`: add a `heartbeat` block — `url` (`env('TRIPCAST_HEARTBEAT_URL')`, **nullable** — a null/unset URL disables pinging, so local/dev/test is a silent no-op) and `timeout` (`env('TRIPCAST_HEARTBEAT_TIMEOUT', 5)`, seconds, floored at 1). The URL is an external dead-man's-switch endpoint (e.g. a Healthchecks-style check); success pings `{url}`, failure pings `{url}/fail`.
- [x] **Task 2 — Record outcome + emit heartbeat in `SendDailyDigests`** (AC: 1, 2)
  - [x] In `app/Console/Commands/SendDailyDigests.php@handle`, keep the existing select-and-dispatch (AD-2) but **measure + report**: capture the start instant; compute `$due = $cadence->dueOn($today)`; dispatch one `SendTripDigest` per due trip counting `$dispatched`.
  - [x] **Health rule:** the run is **unhealthy** if a `Throwable` escapes the select/dispatch, **or** `due` is non-empty **and** `dispatched === 0` (finished-with-zero-dispatched-when-trips-were-due). Wrap the select/dispatch in a `try/catch (Throwable)`: on catch → log the error, emit a **failure** heartbeat, and return `self::FAILURE` (do **not** rethrow — the command always reaches a clean terminal exit; the fail-ping + non-zero exit are the signals).
  - [x] **Record the run-level outcome** with structured logging: `Log::info('digests:run', ['due' => …, 'dispatched' => …, 'healthy' => …, 'duration_ms' => …])` (started/finished implied by the surrounding info logs / timestamps). Keep the existing `$this->info("Dispatched …")` human line.
  - [x] **Emit the heartbeat** via a private `emitHeartbeat(bool $healthy): void`: read `config('tripcast.heartbeat.url')`; if null, **return (no-op)**; else `Http::timeout(config timeout)->get($healthy ? $url : rtrim($url,'/').'/fail')` wrapped in `try/catch (Throwable)` that logs a warning — **a ping failure never fails the run** (the product send already happened). Healthy finish → success ping; unhealthy → `/fail` ping.
  - [x] Return `self::SUCCESS` on a healthy run, `self::FAILURE` otherwise (the scheduler/monitor sees the exit code too).
- [x] **Task 3 — Tests** (AC: 1, 2)
  - [x] Healthy run with due trips (`Queue::fake`, `Http::fake`, a configured `heartbeat.url`): the command dispatches one job per due trip, **pings the success URL** (not `/fail`), exits success, and logs the `digests:run` outcome with `due`/`dispatched`/`healthy:true`.
  - [x] Nothing due (`dueOn` empty) → still **healthy**: success URL pinged, exit success, `dispatched: 0` logged (zero-due is normal, not an alert).
  - [x] **No URL configured** → no Http call at all (`Http::assertNothingSent`), run still exits success (local/dev no-op).
  - [x] **Unhealthy** (mock `CadencePredicate::dueOn` to throw): the command **pings `/fail`**, exits **failure**, logs the error, and **no exception escapes** the command.
  - [x] A **ping outage** never breaks the run: with a configured URL, `Http::fake` a connection error/500 on the heartbeat → the command still completes its dispatch and exits success (the send is unaffected).
  - [x] Gates: `./vendor/bin/pest`, `./vendor/bin/pint`, `./vendor/bin/phpstan analyse`, `npm run types:check`, `npm run lint:check`, `npm run build:ssr` (frontend unchanged).

## Dev Notes

### Scope boundary (read first)
- This is **whole-run liveness only** — the dead-man's-switch *above* the per-trip layer. **Per-trip `sent`/`failed` already live in `email_logs`** (Story 2.3/2.4, AD-9) — do **not** duplicate them here. There is **no new table** (the ERD has none for runs; the admin view, FR-13, reads `email_logs`) — the run outcome is **structured logging + the heartbeat ping**, nothing more. The **admin monitoring UI (FR-13)** and the **`CompleteExpiredTrips` / forecast-retention sweeps (AD-5/AD-16)** are **their own later stories** — this story does not build them. The external monitor (Healthchecks-style) is **configured out-of-band**, not in the app. [Source: ARCHITECTURE-SPINE.md#AD-14, #AD-9, #Structural-Seed line 327; epics.md#Story-2.7]

### Architecture (binding)
- **AD-14 — the daily run reports its own liveness:** "every scheduled run records a run-level outcome (started/finished, trips due, dispatched, sent, failed) and emits a **heartbeat**; a missed heartbeat or a finished-with-zero-dispatched-when-trips-were-due triggers an out-of-band alert to the builder. Per-trip failures live in `email_logs` (AD-9); this is the whole-run dead-man's-switch above them." The **`sent`/`failed` counts are the per-trip `email_logs` layer** (the jobs run async on the queue *after* the command returns) — the command's own run-outcome is **started/finished + due + dispatched**; the monitor reads `sent`/`failed` from `email_logs` (AD-9) separately. [Source: ARCHITECTURE-SPINE.md#AD-14]
- **Liveness is the primary health signal; admin view is pull-only detail.** The heartbeat (this story) is the dead-man's-switch; the admin view (FR-13, later) is the pull-only `email_logs` detail. So the heartbeat must be **out-of-band** (an external monitor that alerts on a *missing* ping — something the app itself cannot do when it's down). [Source: ARCHITECTURE-SPINE.md#Deployment "Liveness", #AD-14]
- **No new persistence.** AD-9 is the sole owner of `email_logs`; AD-14 adds **no table** — the ERD has `users/trips/email_logs/login_tokens/feedback/promo_events` only. Run liveness is logs + an HTTP ping. [Source: ARCHITECTURE-SPINE.md#AD-9, #ERD]

### Heartbeat mechanism (concrete)
- **Dead-man's-switch pattern:** the command pings a configured URL **on healthy finish**; the external monitor alerts if that ping is **late/absent** (covers cron/queue/Redis/host down — the app can't alert when it's dead, so an external timer must). For the **internal** anomaly (due-but-zero-dispatched or a mid-run throwable), the command pings the **`/fail`** variant so the monitor alerts immediately (Healthchecks-style convention: `GET {url}` = success, `GET {url}/fail` = fail).
- **Never break the run:** wrap the ping in `try/catch (Throwable)`; a monitoring outage logs a warning and is swallowed — the digests already dispatched. Use Laravel's `Http` client with the configured short timeout. [Source: ARCHITECTURE-SPINE.md#AD-14 "out-of-band"; NFR-1/NFR-3]

### Code intel (exact patterns to reuse)
- **The command:** `app/Console/Commands/SendDailyDigests.php` — currently `handle(CadencePredicate $cadence): int` computes `$today = now('America/New_York')`, `$due = $cadence->dueOn($today)`, dispatches `SendTripDigest::dispatch($trip, $today->toDateString())` per trip, `$this->info("Dispatched {$due->count()} …")`, returns `self::SUCCESS`. **Preserve this exactly**; wrap it with the start-time, `$dispatched` counter, try/catch, structured log, heartbeat, and SUCCESS/FAILURE return. Uses `#[Signature('digests:send')]` attribute style. [Source: app/Console/Commands/SendDailyDigests.php]
- **Already scheduled:** `routes/console.php` registers `Schedule::command('digests:send')->dailyAt('09:00')->timezone('America/New_York')` — **do not change the schedule**; the heartbeat is emitted from inside the command. [Source: routes/console.php]
- **`Http` client convention:** `app/Services/Weather/WeatherApiProvider.php` and `app/Services/Geocoding/GoogleGeocoder.php` use Laravel's `Http` facade with `->timeout(...)` — mirror that (facade, short timeout). [Source: those files]
- **Config convention:** `config/tripcast.php` floors numeric env reads at a safe minimum (`max(1, (int) env(...))`) — mirror for the timeout; the URL stays nullable (no floor). [Source: config/tripcast.php `magic_link`/`send` blocks]
- **Command tests:** `tests/Feature/Digest/SendDailyDigestsTest.php` already pins the clock (`Carbon::setTestNow('2026-06-29 09:00 America/New_York')`), `Queue::fake()`s, builds due trips via `User::factory()->confirmed()`, and runs `$this->artisan('digests:send')->assertSuccessful()`. **Extend this file** — add `Http::fake()` + `config(['tripcast.heartbeat.url' => …])` and assert pings. The existing dispatch-count + nothing-due tests must keep passing (heartbeat is a no-op when no URL is set; set/unset per test). [Source: tests/Feature/Digest/SendDailyDigestsTest.php]

### Testing standards
- Pest, MySQL `tripcast_test`, `RefreshDatabase`, pinned ET clock. `Http::fake()` to capture/inspect heartbeat requests; `Http::assertSent(fn (Request $r) => $r->url() === $url)` for success and `…/fail` for failure; `Http::assertNothingSent()` for the no-URL case. Mock `CadencePredicate` (`$this->mock(CadencePredicate::class)`) to force the throwable/unhealthy path. Assert exit via `->assertSuccessful()` / `->assertFailed()`. Optionally `Log::spy()` to assert the `digests:run` outcome record. Set `config(['tripcast.heartbeat.url' => 'https://hc.example/ping/abc'])` inside the tests that exercise pinging. [Source: tests/Feature/Digest/SendDailyDigestsTest.php]

### Project Structure Notes
- **Modified:** `app/Console/Commands/SendDailyDigests.php` (measure + record + heartbeat + exit code), `config/tripcast.php` (`heartbeat` block), `tests/Feature/Digest/SendDailyDigestsTest.php` (heartbeat tests). **No** new models, migrations, routes, controllers, or frontend. [Source: ARCHITECTURE-SPINE.md#Structural-Seed]

### Previous story intelligence (Stories 2.1–2.6)
- **`SendDailyDigests`** (Story 2.2) is intentionally thin — "compute the due set and dispatch one job per due trip; no per-trip work here." 2.7 adds the **run-level** wrapper around that; keep the dispatch semantics identical. [Source: 2-2 SendDailyDigests]
- **`CadencePredicate::dueOn(today)`** returns the due `Collection<Trip>` — `$due->count()` is "trips due", and one dispatch each is "dispatched". [Source: app/Digest/CadencePredicate.php]
- **`SendTripDigest`** owns its own per-trip terminal `sent`/`failed` in `email_logs` (Story 2.3/2.4) — the run heartbeat does **not** wait for or aggregate those (jobs run async). [Source: 2-3/2-4]
- Quality lessons carried forward: run **PHPStan**; pin the clock; keep external I/O (the ping) wrapped so it can never break the core run (same discipline as the bounded-retry/never-broken-digest rule, AD-4).

### References
- [Source: _bmad-output/planning-artifacts/epics.md#Story-2.7]
- [Source: _bmad-output/planning-artifacts/architecture/architecture-tripcast-2026-06-28/ARCHITECTURE-SPINE.md#AD-14, #AD-9, #Deployment ("Liveness", "Supervised daemons"), #ERD, #Structural-Seed]
- [Source: _bmad-output/specs/spec-tripcast/SPEC.md#NFR-1, #NFR-3, #FR-6]

## Dev Agent Record

### Agent Model Used

claude-opus-4-8 (1M context)

### Debug Log References

- `now()` resolves to `CarbonImmutable` in this app (a global `Date::use` immutable default), so `recordRun`'s `$startedAt` param is typed `Carbon\CarbonInterface`, not `Illuminate\Support\Carbon` — the latter triggered a TypeError at runtime.

### Completion Notes List

- **Task 1 — Config:** added a `tripcast.heartbeat` block — nullable `url` (disables pinging when unset → dev/test no-op) and a floored `timeout` (default 5s).
- **Task 2 — Command:** `SendDailyDigests::handle` now wraps the existing select-and-dispatch (unchanged semantics, AD-2) with: a start instant + `$dispatched` counter; a `try/catch (Throwable)` that on failure logs the error, fail-pings, and returns `FAILURE` **without** rethrowing (clean terminal exit); a `digests:run` structured `Log::info` outcome (`due`/`dispatched`/`healthy`/`duration_ms`/`error`); and `emitHeartbeat(bool)` which pings `{url}` (healthy) or `{url}/fail` (unhealthy) via `Http` with the configured timeout, swallowing any ping error so monitoring can never break the run. Health = no throwable **and** not (due>0 && dispatched==0). Exit code reflects health.
- **Task 3 — Tests:** 5 new cases in `SendDailyDigestsTest` — healthy run pings success (not `/fail`); zero-due still pings success; no URL → `Http::assertNothingSent`; a thrown `dueOn` (mocked `CadencePredicate`) fail-pings + `assertFailed` with no escaping exception; a 500 on the heartbeat still completes the dispatch + `assertSuccessful`. Existing dispatch-count and e2e tests still pass unchanged (heartbeat is a no-op with no URL set).
- **No new table** (AD-14 adds none; admin reads `email_logs`) — run liveness is structured logging + the external dead-man's-switch ping. The schedule (`routes/console.php`, 09:00 ET) is unchanged.
- **Verification:** full suite **132 passed** (5 new). `pint` clean, `phpstan` 0 errors, `npm run types:check` / `lint:check` / `build:ssr` green (frontend untouched).

### File List

**Modified:**
- `app/Console/Commands/SendDailyDigests.php` (run-outcome logging + heartbeat + health exit code)
- `config/tripcast.php` (`heartbeat` block)
- `tests/Feature/Digest/SendDailyDigestsTest.php` (heartbeat tests)

### Change Log

- 2026-06-29 — Implemented Story 2.7: daily-run liveness heartbeat. The `digests:send` command now records a structured run-level outcome and pings an external dead-man's-switch monitor (success / `/fail`), with a non-zero exit on an unhealthy run and a ping that can never break the product send. Closes Epic 2. All gates green.
