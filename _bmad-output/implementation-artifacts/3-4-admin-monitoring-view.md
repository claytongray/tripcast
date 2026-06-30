---
baseline_commit: b021b1a
---

# Story 3.4: Admin monitoring view

Status: done

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

As the builder,
I want one screen showing every trip and send,
so that I can confirm the beta is healthy without touching the database.

## Acceptance Criteria

**AC1 — A single admin Gate guards the view; non-admins are denied** *(FR-13, AD-12)*
- **Given** an `is_admin` boolean enforced by a **single Gate/middleware**
- **When** a non-admin (or guest) tries to open the view
- **Then** they are **denied** — an authenticated non-admin gets **403**, a guest is redirected to login.

**AC2 — Admins see every trip and its send history** *(FR-13, AD-9, UX-DR13)*
- **Given** an admin opens the view
- **When** it loads
- **Then** it lists **all Trips across users** (Destination, Canonical Place Name, dates, status, **owner**), the **most recent forecast snapshot/reference** per Trip, and the **per-Trip Email Log** (send dates, sent/failed + failure reason) — read from `email_logs`, the source of truth (AD-9). Read-only; no mutation.

## Tasks / Subtasks

- [x] **Task 1 — The single admin Gate** (AC: 1)
  - [x] In `app/Providers/AppServiceProvider.php@boot` (via `configureDefaults`), define one Gate: `Gate::define('admin', fn (User $user): bool => $user->is_admin)`. This is the single enforcement point (AD-12) — no allowlist, no admin CMS. Import `Gate` and `User`.
- [x] **Task 2 — `AdminController@index`** (AC: 2)
  - [x] Create `app/Http/Controllers/AdminController.php` with `index(): Response`. Load **all** non-trashed trips with owners and send history: `Trip::query()->with(['user', 'emailLogs' => fn ($q) => $q->orderByDesc('send_date')])->orderByDesc('id')->get()` (eager-load to avoid N+1).
  - [x] Project each trip to a read-only view-model: `id`, `owner` (user email), `destination_raw`, `canonical_place_name`, `departure_date`/`return_date` (ISO), `status`, `latestSnapshot` (the most recent email_log as a `{ send_date, status }` reference, or `null` if none), and `emailLogs` (each `{ send_date, status, failure_reason }`). Render `Inertia::render('Admin', ['trips' => …])`.
- [x] **Task 3 — Route behind `auth` + `can:admin`** (AC: 1)
  - [x] In `routes/web.php`, add `Route::get('admin', [AdminController::class, 'index'])->middleware(['auth', 'can:admin'])->name('admin')` (its own group/line — distinct from the trip-owner `auth` group; the `can:admin` middleware maps to the Gate). Import `AdminController`.
- [x] **Task 4 — `Admin.vue` monitoring screen** (AC: 2)
  - [x] Create `resources/js/pages/Admin.vue` (renders under `AppLayout` by default). Props `{ trips: AdminTrip[] }` (define the type). A read-only **table/list** of trips: owner, Destination (raw) + Canonical Place Name, dates, **status pill** (reuse the 3.1 pill styling), and the **latest snapshot reference** (e.g. "Last send: {date} ({status})" or "—" when none). Under/within each row, show the **Email Log**: each send's date, status (sent/failed/sending), and failure reason when present. Use a semantic `<table>` (no shadcn Table component exists) styled with the design tokens; empty states for "no trips" and "no sends yet". Calm, dense, monitoring-grade — no charts/analytics beyond the log.
- [x] **Task 5 — Tests** (AC: 1, 2)
  - [x] `tests/Feature/Admin/AdminViewTest.php`: a **guest** → `assertRedirect(route('login'))`; an authenticated **non-admin** → `assertForbidden()`; an **admin** (`User::factory()->admin()->confirmed()`) → `assertOk()` and Inertia `component('Admin')` with **trips from multiple users** (owner emails present), each carrying `status`, `canonical_place_name`, `latestSnapshot`, and `emailLogs` (seed a couple of `email_logs` rows incl. a `failed` with a `failure_reason` and assert they surface). Assert the view is **read-only** (no mutating routes added).
  - [x] **Gates:** `./vendor/bin/pest`, `vendor/bin/pint --dirty --format agent`, `./vendor/bin/phpstan analyse`, `npm run types:check`, `npm run lint:check`, `npm run build:ssr`.

## Dev Notes

### Scope boundary (read first)
- **Read-only monitoring only.** One Gate, one controller, one page, one route. **No** admin mutations (no pause/delete from admin), **no** charts/analytics, **no** allowlist or admin CMS (AD-12). The view reads `email_logs` (AD-9) — it does **not** add a runs table or duplicate the per-send data. [Source: ARCHITECTURE-SPINE.md#AD-12, #AD-9]

### Architecture (binding)
- **AD-12 — admin is a boolean behind a single Gate:** "admin access is an `is_admin` boolean on `users`, enforced by a single Gate/middleware. No allowlist or admin CMS in v1." [Source: ARCHITECTURE-SPINE.md#AD-12, lines 121-124]
- **AD-9 — `email_logs` is the source of truth:** per-send `sent`/`failed` + `failure_reason` + `weather_snapshot` live here; the admin view **reads** them (the "most recent snapshot/reference" is the latest `email_logs` row). No second store. [Source: ARCHITECTURE-SPINE.md#AD-9; app/Models/EmailLog.php]
- **FR-13 (intent):** admin view of all trips + email logs; non-admins denied. [Source: SPEC.md#FR-13]

### Code intel (exact patterns to reuse)
- **`User`**: `is_admin` boolean cast, **not** mass-assignable (intentional); factory state `admin()`. Use `User::factory()->admin()->confirmed()` in tests. [Source: app/Models/User.php; database/factories/UserFactory.php]
- **`EmailLog`**: `trip_id`, `send_date` (date), `status` (`sending|sent|failed` consts), `failure_reason`, `weather_snapshot` (array cast); `trip()` BelongsTo. No `HasFactory` and no factory — seed rows in tests via `DB::table('email_logs')->insert([...])` (as `TripManagementTest` does) or `$trip->emailLogs()->create([...])` (EmailLog `$fillable` covers these columns). [Source: app/Models/EmailLog.php]
- **`Trip`**: `emailLogs()` HasMany; `user()` BelongsTo; `Trip::factory()` with `paused()`/`completed()`/`past()` states (3.1). SoftDeletes default scope excludes trashed. [Source: app/Models/Trip.php; database/factories/TripFactory.php]
- **Thin Inertia controller**: `Inertia::render('Admin', [...])` like `DashboardController`. Eager-load `with([...])` to avoid N+1 across trips' owners + logs. [Source: app/Http/Controllers/DashboardController.php]
- **Gate + `can` middleware**: `Gate::define('admin', ...)` in `AppServiceProvider@boot`; route `->middleware(['auth', 'can:admin'])`. `can:admin` (no model) resolves the ability; an authenticated non-admin → 403, a guest → `auth` redirects to login. [Source: app/Providers/AppServiceProvider.php]
- **Layout/page conventions**: pages render under `AppLayout` (logout in the top bar) via `app.ts`; status-pill styling exists in `Dashboard.vue` — reuse the same token classes. [Source: resources/js/app.ts; resources/js/pages/Dashboard.vue]

### Testing standards
- Pest, `RefreshDatabase`. `User::factory()->admin()->confirmed()` for the admin; a plain confirmed user for the 403 case. Seed trips with `Trip::factory()->for($user)` across **two** users; seed `email_logs` rows (one `sent`, one `failed` with `failure_reason`) to assert the log + latest-snapshot reference surface. Inertia prop assertions (`->component('Admin')->has('trips', n)->where('trips.0.owner', …)`). `assertForbidden()` / `assertRedirect(route('login'))` for authz. [Source: tests/Feature/Dashboard/DashboardTest.php; tests/Feature/Trip/TripManagementTest.php]

### Project Structure Notes
- **New:** `app/Http/Controllers/AdminController.php`, `resources/js/pages/Admin.vue`, `tests/Feature/Admin/AdminViewTest.php`.
- **Modified:** `app/Providers/AppServiceProvider.php` (the `admin` Gate), `routes/web.php` (`admin` route).
- **Unchanged:** `EmailLog`/`Trip`/`User` models, the dashboard. No migrations (all columns exist).

### Previous story intelligence (3.1–3.3)
- The dashboard/controllers/policy/factory built in 3.1–3.3 are the reuse base; `TripPolicy` stays owner-only (admin is a separate Gate, not a policy ability). `now()` is `CarbonImmutable` (irrelevant here). Keep the controller thin and the view read-only. Regenerate Wayfinder after adding the route (gitignored).
- This closes Epic 3 — after review, the epic's stories are all `review`; a retrospective is optional.

### References
- [Source: _bmad-output/planning-artifacts/epics.md#Story-3.4]
- [Source: _bmad-output/planning-artifacts/architecture/architecture-tripcast-2026-06-28/ARCHITECTURE-SPINE.md#AD-12, #AD-9]
- [Source: _bmad-output/specs/spec-tripcast/SPEC.md#FR-13]
- [Source: app/Models/EmailLog.php; app/Models/User.php; app/Http/Controllers/DashboardController.php; app/Providers/AppServiceProvider.php]

## Dev Agent Record

### Agent Model Used

claude-opus-4-8 (1M context)

### Debug Log References

- None — clean implementation.

### Completion Notes List

- **Task 1 — admin Gate:** `Gate::define('admin', fn (User $user) => $user->is_admin)` in `AppServiceProvider@boot` — the single enforcement point (AD-12).
- **Task 2 — `AdminController@index`:** eager-loads all non-trashed trips with `user` + `emailLogs` (send_date desc) to avoid N+1; projects a read-only view-model (owner, raw + canonical place, dates, status, `latestSnapshot` reference, full `emailLogs`).
- **Task 3 — route:** `GET /admin` behind `['auth', 'can:admin']` — authed non-admin → 403, guest → login.
- **Task 4 — `Admin.vue`:** read-only monitoring screen — a card per trip (owner, place, dates, status pill, last-send reference) with a compact email-log table (send date, color-coded status, failure reason) and calm empty states. Renders under `AppLayout`.
- **Task 5 — tests:** `AdminViewTest` (guest→login, non-admin→403, admin sees trips across two users, email-log + latest-snapshot reference incl. a failed send with reason). 4 new tests.
- **Verification:** full suite **186 passed** (723 assertions). `pint` clean, `phpstan` 0 errors, `types:check`/`lint`/`build:ssr` green. No mutations, no migrations, no PayIntent — read-only over `email_logs` (AD-9). **Closes Epic 3.**

### File List

**New:**
- `app/Http/Controllers/AdminController.php`
- `resources/js/pages/Admin.vue`
- `tests/Feature/Admin/AdminViewTest.php`

**Modified:**
- `app/Providers/AppServiceProvider.php` (`admin` Gate)
- `routes/web.php` (`admin` route)
- regenerated Wayfinder (gitignored)

### Change Log

- 2026-06-30 — Implemented Story 3.4: admin monitoring view. A single `is_admin` Gate (AD-12) guards a read-only screen listing every trip across users with owner, latest forecast-snapshot reference, and the per-trip Email Log (send dates, sent/failed + reason) read from `email_logs` (AD-9); non-admins are denied (403 / login). Closes Epic 3. All gates green.

### Review Findings

_Code review 2026-06-30 (Epic 3 adversarial pass)_

- [x] [Review][Defer] Admin view loads all trips and all email logs unbounded — `->get()` with eager-loaded `emailLogs` and no `paginate`/`limit`; N+1 is avoided but the result set grows without bound (memory/response-size cliff at scale). Acceptable for MVP monitoring [app/Http/Controllers/AdminController.php:110-135] — deferred, pre-existing/MVP-acceptable
