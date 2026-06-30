---
baseline_commit: e788ef8
---

# Story 3.3: Free-tier cost-control cap

Status: review

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

As the system,
I want a single enforced limit on active trips,
so that cost stays bounded without any upsell or billing.

## Acceptance Criteria

**AC1 — An over-limit add is refused at the single CreateTrip decision point, with no Trip created** *(FR-12, AD-15, UX-DR16)*
- **Given** the `CreateTrip` decision point and a configurable limit (default **3**)
- **When** a free-tier user tries to add an active Trip beyond the limit (slot-occupancy = `status == active && deleted_at` null; paused/completed/soft-deleted don't occupy)
- **Then** the add is **refused** with a calm "trip limit reached" message — **no upsell, no billing, no Trip created**. The refusal happens **inside `CreateTrip`** so **every** add path (dashboard add, landing email-capture) is covered by the one decision point.

**AC2 — No Pay Intent surface anywhere** *(AD-15)*
- **Given** the refusal
- **When** the user sees it
- **Then** there is **no Pay Intent / billing / upsell surface** — only the calm limit message and the option to pause or remove an existing trip.

## Tasks / Subtasks

- [x] **Task 1 — Configurable limit** (AC: 1)
  - [x] Add a `free_tier` block to `config/tripcast.php`: `'max_active_trips' => max(1, (int) env('TRIPCAST_MAX_ACTIVE_TRIPS', 3))` (floored at 1, mirroring the other floored knobs). Document it as the cost-control cap (AD-15) — pure cost-control, no monetization coupling.
- [x] **Task 2 — Enforce in `CreateTrip` (the single decision point)** (AC: 1, 2)
  - [x] Add `app/Actions/TripLimitReachedException.php` (extends `DomainException`) with a calm default message (e.g. *"You're watching the most trips your plan allows. Pause or remove one to add another."*).
  - [x] In `app/Actions/CreateTrip.php@handle`, **inside the existing `DB::transaction`**, after `User::firstOrCreate(...)` and **before** `->trips()->create(...)`, count the user's slot-occupying trips — `$user->trips()->where('status', Trip::STATUS_ACTIVE)->count()` (SoftDeletes scope already excludes `deleted_at` rows — **only** the `status==active && not-deleted` sub-clause, **not** the AD-11 cadence predicate). If `>= config('tripcast.free_tier.max_active_trips')`, **throw `TripLimitReachedException`** → the transaction rolls back, so **no Trip (and no brand-new user) is persisted**. A brand-new user has 0 trips and can never trip the cap on their first add.
  - [x] Update the `CreateTrip` class docblock: the AD-15 cap is now enforced here (replace the "will be enforced in Story 3.3" note).
- [x] **Task 3 — Surface the calm refusal on both add paths** (AC: 1, 2)
  - [x] `app/Http/Controllers/TripController.php@store`: wrap the `CreateTrip` call in `try/catch (TripLimitReachedException $e)` → `return back()->withErrors(['destination' => $e->getMessage()])` (shows in the dashboard add panel via `InputError`; no Trip created).
  - [x] `app/Http/Controllers/LandingController.php@createTrip`: add a `catch (TripLimitReachedException $e)` (alongside the existing `QueryException` catch) → `return back()->withErrors(['email' => $e->getMessage()])`. This covers a returning, logged-out user at their cap who runs the landing flow again. Keep `pending_trip` in the session is NOT required — but do **not** clear it on the limit path (let them retry after pausing). (The session clear stays only on the success path, as today.)
- [x] **Task 4 — Proactive dashboard state (no pointless submit)** (AC: 1, 2)
  - [x] `app/Http/Controllers/DashboardController.php@index`: add props `'maxActiveTrips' => (int) config('tripcast.free_tier.max_active_trips')` and `'activeTripCount' => <count of active, non-deleted trips for the user>` (compute from the already-loaded collection: `$trips->where('status', Trip::STATUS_ACTIVE)->count()`).
  - [x] `resources/js/pages/Dashboard.vue`: when `activeTripCount >= maxActiveTrips`, **hide the "Add a trip" button/panel** and show a **calm limit note** ("You're watching the most trips your plan allows ({{ maxActiveTrips }}). Pause or remove one to add another.") — **no upsell, no billing, no Pay Intent surface** (AC2). The server guard in Task 2/3 remains the real enforcement; this is just calm UX. Update the `defineProps` type.
- [x] **Task 5 — Tests** (AC: 1, 2)
  - [x] `tests/Feature/Trip/TripLimitTest.php`: a user with **3 active** trips → `CreateTrip::handle` (and `POST trips.store`) **throws/refuses**, calm message returned, **no 4th trip** (`assertDatabaseCount('trips', 3)` for that user). A user with 3 trips where some are **paused/completed/soft-deleted** → those **don't** occupy a slot, so the add **succeeds**. The limit is **configurable** (`config(['tripcast.free_tier.max_active_trips' => 1])` → second active add refused). A brand-new user's first add always succeeds.
  - [x] Extend `tests/Feature/Trip/AddTripTest.php` (or the new file) for the `trips.store` refusal path: `assertSessionHasErrors('destination')`, no trip, no `WelcomeMail` queued.
  - [x] Dashboard: `DashboardTest` asserts `activeTripCount`/`maxActiveTrips` props and that an at-cap user gets the calm state (prop assertion).
  - [x] **Gates:** `./vendor/bin/pest`, `vendor/bin/pint --dirty --format agent`, `./vendor/bin/phpstan analyse`, `npm run types:check`, `npm run lint:check`, `npm run build:ssr`.

## Dev Notes

### Scope boundary (read first)
- **Only** the cost-control cap: one count-query guard in `CreateTrip`, the calm refusal on both callers, and the proactive dashboard state. **No** billing, **no** upsell, **no** `PayIntent` model — AD-15 explicitly dissolves the soft-vs-hard question and forbids a pay surface. [Source: ARCHITECTURE-SPINE.md#AD-15]

### Architecture (binding)
- **AD-15 — one cost-control decision point:** "a free-tier `User` may hold up to a configurable limit (default 3) active Trips. The **slot-occupancy predicate is `status == active` AND `deleted_at` is null — and only that**; `completed` and `paused` trips do not occupy a slot. This is **deliberately NOT the AD-11 cadence/due predicate** … the cap shares only the `status==active && deleted_at null` sub-clause, so an opted-out or far-future-window trip still occupies its slot. The cap is a **count query**. … Enforcement runs through a **single decision point** in `CreateTrip` … an over-limit add is **refused** with a calm 'trip limit reached' message — no upsell, no Trip created. There is no `PayIntent` model." A past-Return-Date-but-unswept trip counts as active until the next sweep (accepted self-heal). [Source: ARCHITECTURE-SPINE.md#AD-15, lines 136-139]

### Code intel (exact patterns to reuse)
- **`CreateTrip::handle`** already wraps create in `DB::transaction` with `User::firstOrCreate` then `->trips()->create([... 'status' => STATUS_ACTIVE])`. Insert the cap check between them; throwing inside the closure rolls the whole transaction back. The class docblock already says the cap "will be enforced here in Story 3.3." [Source: app/Actions/CreateTrip.php:30-58]
- **Config floor idiom:** `max(1, (int) env(...))` — mirror for `max_active_trips`. [Source: config/tripcast.php]
- **`TripController@store`** already has a `try/catch (GeocodingFailedException)`; add the limit catch around the `CreateTrip` call (geocoding succeeds, then the create refuses). Surfaces via `withErrors(['destination' => …])` in the add panel. [Source: app/Http/Controllers/TripController.php]
- **`LandingController@createTrip`** already catches `QueryException`; add the limit catch. Don't clear `pending_trip` on the limit path. [Source: app/Http/Controllers/LandingController.php:108-122]
- **`DashboardController@index`** loads `$trips` and maps a view-model — derive the active count from the same collection (no extra query). [Source: app/Http/Controllers/DashboardController.php]
- **`Dashboard.vue`** has the add-panel toggle (`showAddPanel`, `openAddPanel`) and the flash banner pattern — gate the add affordance on the new props. [Source: resources/js/pages/Dashboard.vue]

### Testing standards
- Pest, `RefreshDatabase`, pinned ET clock. Build occupying trips with `Trip::factory()->for($user)` + states `paused()`/`completed()` and `->create()` then `->delete()` for the soft-deleted case. Drive config with `config(['tripcast.free_tier.max_active_trips' => N])`. `Mail::fake()` to assert no welcome on refusal. Inertia prop assertions for the dashboard state. [Source: tests/Feature/Trip/*, tests/Feature/Dashboard/DashboardTest.php]

### Project Structure Notes
- **New:** `app/Actions/TripLimitReachedException.php`, `tests/Feature/Trip/TripLimitTest.php`.
- **Modified:** `config/tripcast.php`, `app/Actions/CreateTrip.php`, `app/Http/Controllers/TripController.php`, `app/Http/Controllers/LandingController.php`, `app/Http/Controllers/DashboardController.php`, `resources/js/pages/Dashboard.vue`, dashboard/add tests.
- **Unchanged:** the `Trip` model, routes (no new routes), the cadence predicate.

### Previous story intelligence (3.1/3.2)
- `CreateTrip` is the sole creation path used by both `TripController@store` (3.2) and `LandingController@createTrip` (Epic 1) — enforcing here is genuinely one decision point. `now()` is `CarbonImmutable` (irrelevant here; the cap is a count, no dates). The dashboard add-panel and props were built in 3.1/3.2 — extend, don't rebuild.

### References
- [Source: _bmad-output/planning-artifacts/epics.md#Story-3.3]
- [Source: _bmad-output/planning-artifacts/architecture/architecture-tripcast-2026-06-28/ARCHITECTURE-SPINE.md#AD-15]
- [Source: _bmad-output/specs/spec-tripcast/SPEC.md#FR-12]
- [Source: app/Actions/CreateTrip.php; app/Http/Controllers/TripController.php, LandingController.php, DashboardController.php; config/tripcast.php]

## Dev Agent Record

### Agent Model Used

claude-opus-4-8 (1M context)

### Debug Log References

- Pest global-helper collision: a `tripDetails()` helper already exists in `WelcomeMailTest`; renamed the new one to `capTripDetails()`.

### Completion Notes List

- **Task 1 — config:** added `tripcast.free_tier.max_active_trips` (env `TRIPCAST_MAX_ACTIVE_TRIPS`, default 3, floored at 1).
- **Task 2 — enforce in `CreateTrip`:** inside the existing transaction, after `firstOrCreate`, count active non-deleted trips (`status==active`, SoftDeletes excludes trashed) and throw the new `TripLimitReachedException` when at/over the cap — the rollback guarantees no Trip (or brand-new user) is persisted. Docblock updated.
- **Task 3 — surface refusal:** `TripController@store` and `LandingController@createTrip` both catch the exception and return `back()->withErrors([...])` with the calm message (no Trip created, no upsell). The landing path keeps `pending_trip` so the user can retry after pausing.
- **Task 4 — proactive dashboard state:** `DashboardController` now passes `maxActiveTrips` + `activeTripCount` (derived from the loaded collection, no extra query); `Dashboard.vue` hides the add affordance at the cap and shows a calm limit note — no Pay Intent surface (AC2).
- **Task 5 — tests:** `TripLimitTest` (over-cap refusal creates nothing + no welcome; paused/completed/soft-deleted don't occupy; configurable limit; new-user first add always allowed; dashboard `trips.store` refusal) + a `DashboardTest` cap-prop assertion. 6 new tests.
- **Verification:** full suite **182 passed** (691 assertions). `pint` clean, `phpstan` 0 errors, `types:check`/`lint`/`build:ssr` green. No billing/upsell/PayIntent introduced.

### File List

**New:**
- `app/Actions/TripLimitReachedException.php`
- `tests/Feature/Trip/TripLimitTest.php`

**Modified:**
- `config/tripcast.php` (`free_tier.max_active_trips`)
- `app/Actions/CreateTrip.php` (cap check)
- `app/Http/Controllers/TripController.php` (limit catch on `store`)
- `app/Http/Controllers/LandingController.php` (limit catch on `createTrip`)
- `app/Http/Controllers/DashboardController.php` (`maxActiveTrips`/`activeTripCount` props)
- `resources/js/pages/Dashboard.vue` (at-limit calm state)
- `tests/Feature/Dashboard/DashboardTest.php`

### Change Log

- 2026-06-30 — Implemented Story 3.3: free-tier cost-control cap. A configurable active-trip limit (default 3) is enforced at the single `CreateTrip` decision point — slot occupancy is active-and-not-deleted only — refusing over-limit adds with a calm message on both the dashboard and landing paths, no Trip created and no upsell/billing/PayIntent surface. The dashboard proactively hides the add affordance at the cap. All gates green.
