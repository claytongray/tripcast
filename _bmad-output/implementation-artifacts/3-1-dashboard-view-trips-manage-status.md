---
baseline_commit: d79abc2ac1f16cb1f587ede26659dfffae256e7f
---

# Story 3.1: Dashboard — view trips & manage status

Status: in-progress

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

As an authenticated user,
I want to see and manage my trips,
so that I control what Tripcast watches.

## Acceptance Criteria

**AC1 — The dashboard lists trips, grouped, with no weather/analytics, and offers logout** *(FR-12, UX-DR8, UX-DR9)*
- **Given** the authenticated dashboard
- **When** it loads
- **Then** it lists **upcoming** Trips (each showing **Destination**, **dates**, **days-until-departure**, and a **status pill**) and **past/completed** Trips grouped **separately**, with **no weather preview and no analytics**; **logout** is available. An **empty state** ("No trips yet — add your first.") shows when the user has no trips.

**AC2 — Pause / resume / delete go through the single Trip transition method, reflect immediately, and delete is a soft-delete behind one calm confirm** *(FR-12, AD-5, UX-DR8, UX-DR15)*
- **Given** a Trip the user owns
- **When** they **pause** / **resume** / **delete** it
- **Then** status changes route through the **single state-transition method on `Trip`** (`active ⇄ paused`; `Trip::transitionTo()`), a **delete is a soft delete** (`deleted_at`) that **preserves the Trip's `email_logs` and `feedback`** (AD-9 audit trail), the change **reflects immediately (optimistic)**, and a **delete asks exactly one calm confirm** ("Stop watching {destination} and remove it? This can't be undone." → "Remove trip" / "Keep it."). A user may **only** act on their **own** Trips (others' trips → 403). `completed` Trips are **terminal/read-only** (no pause/resume/delete affordances beyond what AD-5 allows — completed cannot transition).

## Tasks / Subtasks

- [x] **Task 1 — `TripFactory` for tests** (AC: 1, 2)
  - [x] Create `database/factories/TripFactory.php` (does not exist yet). Default a sensible upcoming, **active** trip (real-ish coords + canonical place name, `departure_date`/`return_date` in the future relative to the pinned test clock). Add states: `paused()` (`status => Trip::STATUS_PAUSED`), `completed()` (`status => Trip::STATUS_COMPLETED`), and `past()` (dates before "today" — useful for grouping tests). Wire `Trip::factory()` by adding the `HasFactory` trait usage if not already resolvable (the model is `App\Models\Trip`; factory auto-resolves by name). Mirror the inline trip shape already used in `tests/Feature/Trip/TripTransitionTest.php` (Edinburgh sample) so existing tests can optionally migrate.
- [x] **Task 2 — `DashboardController@index` (replace the inline Inertia route)** (AC: 1)
  - [x] Create `app/Http/Controllers/DashboardController.php` with `index(Request $request): Response` (mirror `LandingController`'s `Inertia::render(...)` thin-controller style). Load **only the authed user's** trips: `$request->user()->trips()->orderBy('departure_date')->get()` (SoftDeletes scope auto-excludes deleted rows — do **not** add `withTrashed`).
  - [x] Build a **view-model array** per trip — `id`, `destination` (use `canonical_place_name`, fall back to `destination_raw`), `departure_date`/`return_date` (ISO `Y-m-d` strings via the date casts), `status`, and **`days_until_departure`** computed via the **single authority** `CadencePredicate::daysUntilDeparture($trip, $today)` where `$today = now('America/New_York')` (AD-7/AD-11 — never re-implement the countdown). Inject `CadencePredicate` via the method signature (it's already container-resolvable; see `CountdownLine`).
  - [x] **Group server-side by status** into `upcoming` (status `active` or `paused`) and `past` (status `completed`) — grouping is **status-driven, not date-derived** (completion is owned by the separate `CompleteExpiredTrips` sweep per AD-5, a later story; the dashboard reflects status, it does not re-derive "past" from dates). Pass `Inertia::render('Dashboard', ['upcomingTrips' => …, 'pastTrips' => …])`.
  - [x] In `routes/web.php`, **replace** `Route::inertia('dashboard', 'Dashboard')->name('dashboard')` with `Route::get('dashboard', [DashboardController::class, 'index'])->name('dashboard')` inside the existing `Route::middleware('auth')->group(...)`. Keep the route **name `dashboard`** unchanged (Wayfinder `dashboard()` and the Landing/AppLayout links already depend on it).
- [x] **Task 3 — Trip status & delete endpoints (AD-5 single method, owner-scoped)** (AC: 2)
  - [x] Add a **`TripController`** (`app/Http/Controllers/TripController.php`) with `pause`, `resume`, and `destroy` — each takes route-model-bound `Trip $trip`, **authorizes ownership**, mutates through the model, and **redirects back** with a calm flash `status` (Inertia expects a redirect response, not JSON). `pause` → `$trip->transitionTo(Trip::STATUS_PAUSED)`; `resume` → `$trip->transitionTo(Trip::STATUS_ACTIVE)`; `destroy` → `$trip->delete()` (SoftDeletes — **never** `forceDelete`). **No controller writes `status` directly** (AD-5) — always via `transitionTo()`.
  - [x] **Authorization:** create `app/Policies/TripPolicy.php` with `update(User $user, Trip $trip): bool` (owner) and `delete(User $user, Trip $trip): bool` (owner) — `$trip->user_id === $user->id`. Laravel auto-discovers `App\Policies\TripPolicy` for `App\Models\Trip`. Call `$this->authorize('update', $trip)` (pause/resume) and `$this->authorize('delete', $trip)` (destroy) — a non-owner gets **403**. (This policy also seeds the Epic 3.4 admin gate work.)
  - [x] Routes in the `auth` group of `routes/web.php`:
    - `Route::patch('trips/{trip}/pause', [TripController::class, 'pause'])->name('trips.pause')`
    - `Route::patch('trips/{trip}/resume', [TripController::class, 'resume'])->name('trips.resume')`
    - `Route::delete('trips/{trip}', [TripController::class, 'destroy'])->name('trips.destroy')`
  - [x] **Guard the terminal state gracefully:** `transitionTo()` throws `InvalidTripTransitionException` if called on a `completed` trip. The UI won't surface pause/resume on completed trips, but the controller must not 500 on a crafted request — let the policy/validation prevent it, or catch the domain exception and redirect back with a calm message. (Keep it simple: completed trips fail the affordance at the UI; a defensive catch is acceptable but not required by AC.)
  - [x] Regenerate Wayfinder route helpers so the frontend can import them: run `php artisan wayfinder:generate` (the Vite plugin also emits on build). New helpers land under `resources/js/routes/` (e.g. `trips.*`).
- [x] **Task 4 — Dashboard.vue: trip list, status pills, grouping, optimistic actions, calm delete confirm** (AC: 1, 2)
  - [x] Replace the placeholder `resources/js/pages/Dashboard.vue` body. **Shell it in `AppLayout`** (`resources/js/layouts/AppLayout.vue` — the authenticated top bar with logo + **Log out**, satisfying the logout AC). Set `<Head title="Dashboard" />`.
  - [x] `defineProps<{ upcomingTrips: TripCard[]; pastTrips: TripCard[] }>()` (add a local `TripCard` type: `id`, `destination`, `departure_date`, `return_date`, `status`, `days_until_departure`). Render an **Upcoming** section and, when non-empty, a separate **Past** section (muted, read-only). When both are empty, render the **empty state** ("No trips yet — add your first." + a primary CTA; the add-trip panel itself is **Story 3.2** — a placeholder/disabled CTA or link is fine here).
  - [x] **Trip card** (reuse `@/components/ui/card`): destination (subtitle) · dates + `days_until_departure` (meta) · **status pill** · row actions. Use the existing `CountdownLine` phrasing as a guide but the dashboard copy is its own (e.g. `{n} days until {place}` / `1 day until …` / `Today` — keep consistent with the email countdown wording where natural). **No weather, no analytics.**
  - [x] **Status pill** (reuse `@/components/ui/badge`): **Active** = accent-wash bg + accent text; **Paused** = hairline border + ink-secondary; **Completed** = muted. **Always text + color** (never color-only — AA / color-blind safe per UX-DR). Map to design tokens already in the Tailwind config (`bg-accent-wash`, `text-brand`, `border-hairline`, `text-ink-secondary`).
  - [x] **Actions (optimistic):** pause/resume and delete via `router.patch(...)` / `router.delete(...)` from `@inertiajs/vue3`, using the generated Wayfinder helpers. Use `{ preserveScroll: true }`; reflect the change immediately in local reactive state and **roll back in `onError`** (optimistic per UX-DR15). A paused card shows **"Paused — no emails until you resume."** with a **Resume** action; an active card shows **Pause** + **Delete**. Completed cards are **read-only** (no actions).
  - [x] **Delete confirm (exactly one calm dialog):** reuse `@/components/ui/dialog`. Copy: **"Stop watching {destination} and remove it? This can't be undone."** → **"Remove trip"** (destructive) / **"Keep it."** (cancel). Only the confirm fires `router.delete(trips.destroy({ trip: id }))`.
  - [x] Keep the existing `flash.status` banner pattern for server confirmations if desired (shared via `HandleInertiaRequests`), but the primary feedback is the optimistic UI.
- [x] **Task 5 — Tests** (AC: 1, 2)
  - [x] **Feature (dashboard render):** `tests/Feature/Dashboard/DashboardTest.php` (new). Pin the clock (`Carbon::setTestNow('2026-06-30 09:00 America/New_York')` style, matching the digest tests). A confirmed user with mixed trips (active upcoming, paused, completed) sees them **grouped** (`upcomingTrips` has active+paused, `pastTrips` has completed) via Inertia assertions (`AssertableInertia`), each upcoming trip carrying `destination`, dates, `status`, and the correct `days_until_departure`. A user with no trips gets empty arrays. **Scoping:** another user's trips never appear. A **soft-deleted** trip never appears.
  - [x] **Feature (mutations):** `tests/Feature/Trip/TripManagementTest.php` (new, or extend `Trip/TripTransitionTest.php`). Owner can **pause** (active→paused), **resume** (paused→active), and **delete** (row soft-deleted, `deleted_at` set, **and its `email_logs`/`feedback` rows still exist** — assert the audit trail survives). A **non-owner** is **403** on each route. An **unauthenticated** request **redirects to login**. Deleting routes through SoftDeletes (assert `Trip::withTrashed()->find($id)` still present, `Trip::find($id)` gone).
  - [x] **Unit/edge:** transitioning a `completed` trip via the endpoint does not 500 (policy denies or domain exception handled) — assert a graceful response, no uncaught `InvalidTripTransitionException`.
  - [x] **Gates (run all, must be green):** `./vendor/bin/pest`, `vendor/bin/pint --dirty --format agent`, `./vendor/bin/phpstan analyse` (or `composer run` equivalents used by prior stories), `npm run types:check`, `npm run lint:check`, `npm run build:ssr` (this story changes the frontend, so the SSR build must pass).

## Dev Notes

### Scope boundary (read first)
- This story is **view + status management only**. **Out of scope (later stories):** the **add-trip inline panel** (Story 3.2 — leave a CTA placeholder), the **3-active-trip cost-control cap** (Story 3.3 — lives in `CreateTrip`, not the dashboard), the **admin monitoring view** (Story 3.4), and the **`CompleteExpiredTrips` daily sweep** (AD-5 completion sweep — **does not exist yet**; do not build it here). Grouping is therefore **status-driven** (`completed` ⇒ past), not date-derived. [Source: epics.md#Story-3.1; ARCHITECTURE-SPINE.md#AD-5; deferred-work.md]
- **Reuse, don't rebuild:** the **Trip state machine already exists** — `Trip::transitionTo()`, `Trip::complete()`, the `STATUS_*` constants, `SoftDeletes`, and `InvalidTripTransitionException` were all built in Story 2.5 and are tested in `tests/Feature/Trip/TripTransitionTest.php`. This story **consumes** them; it must **not** add a parallel status path. [Source: app/Models/Trip.php:34-96]

### Architecture (binding)
- **AD-5 — one owner for Trip status; soft delete preserves the audit trail:** "transitions go through a **single state-transition method on `Trip`** (`active ⇄ paused` by user; `→ completed` by system or end-trip link)… **No controller/job writes `status` directly.**… **Delete (FR-12) is a soft delete** (`deleted_at`): the trip leaves cadence and the UI but its `email_logs`/`feedback` survive (AD-9 keeps the metric/audit trail) — no hard delete cascades the source of truth away." `completed` is **terminal**. [Source: ARCHITECTURE-SPINE.md#AD-5, lines 84-87]
- **AD-7 — one time frame:** all scheduling math, **including the "N days until" countdown**, uses the **America/New_York calendar date** as "today" — never a destination/user frame. `departure_date`/`return_date` are timezone-naive `DATE`s. [Source: ARCHITECTURE-SPINE.md#AD-7, lines 96-99]
- **AD-11 — one cadence predicate is the countdown authority:** the dashboard countdown must **derive from the single predicate, never re-implement it**. `CadencePredicate::daysUntilDeparture()` is that authority. [Source: ARCHITECTURE-SPINE.md#AD-11; app/Digest/CadencePredicate.php:84-91]
- **FR-12 (intent):** "An authenticated User can view, add, pause, resume, and delete Trips, and view past Trips… Upcoming list shows Destination, dates, days-until-departure, and Status; … pause stops digests and resume restores them; delete removes the Trip from cadence and stops emails (soft delete preserves logs/feedback); past Trips are viewable separately; the User can log out." [Source: SPEC.md#FR-12]

### Code intel (exact patterns to reuse)
- **`Trip` model** (`app/Models/Trip.php`): uses `SoftDeletes`; `status` is a string with constants `STATUS_ACTIVE='active'`, `STATUS_PAUSED='paused'`, `STATUS_COMPLETED='completed'`; **`transitionTo(string $status): void`** (idempotent; throws `InvalidTripTransitionException` on unknown status or any move off `completed`); `complete(): void`; relations `user()` (BelongsTo), `emailLogs()` (HasMany), `feedback()` (HasMany). `status` is in `$fillable`. [Source: app/Models/Trip.php:34-124]
- **Countdown authority** — `CadencePredicate::daysUntilDeparture(Trip $trip, CarbonInterface $date): int` returns a signed whole-day diff from the ET calendar date to `departure_date` (positive = future). The dashboard must call this with `now('America/New_York')`. `CountdownLine::positionLine()` shows the canonical copy mapping (`{n} days until {place}` / `1 day until …` / `Today: …`). [Source: app/Digest/CadencePredicate.php:84-91; app/Digest/CountdownLine.php:22-44]
- **Thin Inertia controller pattern** — `LandingController` does `return Inertia::render('PageName', [...])` and type-hints `Request`/returns `Response`. `EmailAction` shows `Inertia::render('email/EndTripConfirm', [...])`. Mirror this; keep controllers thin (request → action/model → Inertia response). [Source: app/Http/Controllers/LandingController.php, EmailAction.php]
- **`CreateTrip`** is the single creation decision point; new trips default to `Trip::STATUS_ACTIVE`. (Relevant only as the reference point for 3.2/3.3 — **not modified here**.) [Source: app/Actions/CreateTrip.php:28-60]
- **Migrations (do not change):** `trips` has `status string default 'active'`, `softDeletes()`, index `['user_id','status']`; `email_logs.trip_id` and `feedback.trip_id` are `cascadeOnDelete()` — that DB cascade fires only on **hard** delete, so a **soft** delete (this story) leaves them intact. [Source: database/migrations/2026_06_29_000002_create_trips_table.php; …000003…; …000004…]
- **`User`**: `is_admin` boolean cast (not mass-assignable — for 3.4); `trips()` HasMany; factory state `confirmed()` (sets `email_verified_at`); `hasConfirmedEmail()`. [Source: app/Models/User.php; database/factories/UserFactory.php:72-78]
- **Routes today:** the dashboard is currently `Route::inertia('dashboard', 'Dashboard')->name('dashboard')` inside `Route::middleware('auth')->group(...)`. Replace it with a controller route, **keep the name**. Logout is `POST /logout` (`logout()` Wayfinder helper), already wired in `AppLayout`. [Source: routes/web.php:22-24; routes/auth.php]

### Frontend intel (reuse vs build)
- **Layout:** `resources/js/layouts/AppLayout.vue` is the authenticated shell (top bar: `tripcast` home link + **Log out** `<Link method="post" :href="logout()">`). Wrap the dashboard page in it — this is how the **logout** AC is met. `max-w-3xl`, hairline border, no sidebar. [Source: resources/js/layouts/AppLayout.vue]
- **Vue conventions** (from `Landing.vue`): `<script setup lang="ts">`, `@inertiajs/vue3` imports (`Head`, `Link`, `useForm`, `router`), Wayfinder route imports from `@/routes` / `@/routes/<group>`, shadcn-vue from `@/components/ui/*`, `InputError` for field errors, `$page.props.auth.user` / `$page.props.flash.status` shared props. [Source: resources/js/pages/Landing.vue; app/Http/Middleware/HandleInertiaRequests.php]
- **Components available** (`resources/js/components/ui/`): `card` (Card/CardHeader/CardTitle/CardContent/CardFooter), `badge` (status pills), `button`, `dialog` (DialogTrigger/Content/Header/Title/Description/Footer — for the delete confirm), `dropdown-menu`, `alert`, `separator`, `skeleton`, `spinner`, `sonner` (toast). [Source: resources/js/components/ui/*]
- **Optimistic mutations:** no existing example in-repo, but the standard is `router.patch(url, {}, { preserveScroll: true, onError })` / `router.delete(url, { preserveScroll: true, onError })` with local reactive state updated before the request and reverted in `onError`. Inertia returns redirects (server controllers redirect back). [Source: Inertia v3 docs; resources/js/pages/Landing.vue form patterns]
- **Design tokens** (Tailwind v4, already configured): surfaces `bg-surface-wash`/`bg-surface-raised`, `border-hairline`, text `text-display`/`text-title`/`text-subtitle`/`text-body`/`text-meta`, `text-ink`/`text-ink-secondary`, accent `text-brand`/`bg-accent-wash`. Flat (no shadows), calm. [Source: ux-designs/.../DESIGN.md]

### UX (binding copy & behaviors)
- **Trip card:** destination · dates + days-until · status pill · row actions. **No weather, no analytics.** **Status pill** always text+color (AA, color-blind safe). **Paused** card reads "Paused — no emails until you resume." with **Resume**. **Empty state:** "No trips yet — add your first." [Source: ux-designs/.../EXPERIENCE.md Component Patterns / State Patterns]
- **Delete = one calm confirm:** "Stop watching {destination} and remove it? This can't be undone." → "Remove trip" / "Keep it." Optimistic; reflect immediately. [Source: ux-designs/.../EXPERIENCE.md Flow 3; UX-DR15]
- **Watching motif** throughline ("We're watching {place}" / "We've stopped watching") — keep dashboard copy in that calm register. [Source: ux-designs/.../DESIGN.md Voice & Tone]

### Testing standards
- Pest, MySQL `tripcast_test`, `RefreshDatabase`, pinned ET clock (`Carbon::setTestNow(...)` as in `SendDailyDigestsTest`). Use the **new `TripFactory`** + `User::factory()->confirmed()`. Assert Inertia props with `AssertableInertia` (`->has('upcomingTrips', n)`, `->where(...)`). Assert authz (`assertForbidden()` for non-owner, `assertRedirect(login)` for guests) and SoftDeletes (`assertSoftDeleted('trips', ['id'=>…])`, plus `assertDatabaseHas('email_logs', …)` to prove the audit trail survives). [Source: tests/Feature/Digest/SendDailyDigestsTest.php; tests/Feature/Trip/TripTransitionTest.php]

### Project Structure Notes
- **New:** `database/factories/TripFactory.php`, `app/Http/Controllers/DashboardController.php`, `app/Http/Controllers/TripController.php`, `app/Policies/TripPolicy.php`, `tests/Feature/Dashboard/DashboardTest.php`, `tests/Feature/Trip/TripManagementTest.php`.
- **Modified:** `routes/web.php` (dashboard → controller, keep name; add `trips.*` routes in the `auth` group), `resources/js/pages/Dashboard.vue` (placeholder → real dashboard), generated `resources/js/routes/*` (Wayfinder, regenerated — don't hand-edit).
- **Unchanged (do not touch):** `Trip` model state machine, `CreateTrip`, the migrations, the cadence predicate. [Source: ARCHITECTURE-SPINE.md#Structural-Seed]

### Previous story intelligence (Epic 2 + Story 2.5)
- **Story 2.5** already added `Trip::transitionTo()` / `complete()` / `InvalidTripTransitionException` / `SoftDeletes` and the `EmailAction` end-trip path that calls `complete()`. This story is the **UI owner** of the same `active ⇄ paused` + soft-delete transitions — reuse the exact method, don't fork it. [Source: app/Models/Trip.php; app/Http/Controllers/EmailAction.php]
- **Quality discipline carried forward:** pin the ET clock; run **PHPStan** (`now()` is `CarbonImmutable` app-wide — type countdown params as `CarbonInterface`, the same TypeError trap noted in 2.7); keep controllers thin and route all status writes through the model method (AD-5). Run the **full gate set incl. `build:ssr`** since this story touches the frontend.
- **Deferred item resolved-adjacent:** the "shared public header / login indicator" note (deferred-work.md) is satisfied for the dashboard by `AppLayout`'s top bar; the broader shared-public-header refactor remains its own item. The `WelcomeMail`+`SerializesModels` soft-delete concern is **not** triggered here (no welcome email path in this story). [Source: deferred-work.md]

### References
- [Source: _bmad-output/planning-artifacts/epics.md#Epic-3 / #Story-3.1]
- [Source: _bmad-output/planning-artifacts/architecture/architecture-tripcast-2026-06-28/ARCHITECTURE-SPINE.md#AD-5, #AD-7, #AD-11, #AD-12, #AD-15, #ERD, #Structural-Seed]
- [Source: _bmad-output/specs/spec-tripcast/SPEC.md#FR-12]
- [Source: _bmad-output/planning-artifacts/ux-designs/ux-tripcast-2026-06-28/DESIGN.md, EXPERIENCE.md (UX-DR8, UX-DR9, UX-DR15)]
- [Source: app/Models/Trip.php; app/Digest/CadencePredicate.php; app/Http/Controllers/LandingController.php; routes/web.php; resources/js/layouts/AppLayout.vue; resources/js/pages/Landing.vue]

## Dev Agent Record

### Agent Model Used

claude-opus-4-8 (1M context)

### Debug Log References

- `now()` resolves to `CarbonImmutable` app-wide; `DashboardController` types its clock as `Illuminate\Support\Carbon` only where it owns the instance, and passes a `CarbonInterface` into `CadencePredicate::daysUntilDeparture()` (the predicate already accepts `CarbonInterface`). No TypeError.
- The base `App\Http\Controllers\Controller` did not include `AuthorizesRequests` (Laravel 12 slim skeleton), so `$this->authorize()` was unavailable — added the trait once on the base class.
- `EmailLog`/`Feedback` have no factories; the soft-delete audit-trail assertions insert those rows via `DB::table()` directly rather than introduce new factories (out of scope).

### Completion Notes List

- **Task 1 — `TripFactory`:** added `database/factories/TripFactory.php` (default upcoming+active, states `paused()`/`completed()`/`past()`) and the `HasFactory` trait on `Trip`. Dates derive from the pinnable America/New_York clock so they stay future-relative under `Carbon::setTestNow`.
- **Task 2 — Dashboard view:** `DashboardController@index` loads only the owner's (non-trashed) trips, projects a weather/analytics-free view-model, computes `days_until_departure` via the single authority `CadencePredicate::daysUntilDeparture()` on the ET clock (AD-7/AD-11), and groups **by status** (`active`/`paused` → upcoming, `completed` → past — not date-derived, since completion is the separate sweep's job per AD-5). Route `dashboard` switched from inline `Route::inertia` to the controller, name preserved.
- **Task 3 — Status/delete endpoints:** `TripController` (`pause`/`resume`/`destroy`) routes every status change through `Trip::transitionTo()` (AD-5, never a direct write) and soft-deletes on destroy (audit trail preserved). `TripPolicy` (`update`/`delete`, owner-only, auto-discovered) gates each action → non-owners 403. A completed (terminal) trip can't be paused: the domain exception is swallowed so a crafted request returns a calm redirect, never a 500. Routes added under the `auth` group; Wayfinder regenerated (`resources/js/routes/trips`, `resources/js/actions/...`).
- **Task 4 — Dashboard.vue:** replaced the placeholder with grouped Upcoming/Past sections, status pills (text+color, AA-safe), an empty state, and optimistic pause/resume/delete via `router.patch`/`router.delete` with `onError` rollback + toast. Delete is one calm `Dialog` confirm ("Stop watching {destination}…" → "Remove trip" / "Keep it."). Logout comes from the central `AppLayout` (resolved in `app.ts`). No weather, no analytics.
- **Task 5 — Tests:** `DashboardTest` (grouping, day-count, owner-scoping, soft-deleted hidden, guest→login, empty state) and `TripManagementTest` (pause/resume, soft-delete-preserves-logs/feedback, non-owner 403, guest→login, completed-no-500). 11 new tests.
- **Verification:** full suite **167 passed** (632 assertions). `pint` clean, `phpstan` 0 errors, `npm run types:check` / `lint:check` / `build:ssr` all green.

### File List

**New:**
- `database/factories/TripFactory.php`
- `app/Http/Controllers/DashboardController.php`
- `app/Http/Controllers/TripController.php`
- `app/Policies/TripPolicy.php`
- `tests/Feature/Dashboard/DashboardTest.php`
- `tests/Feature/Trip/TripManagementTest.php`

**Modified:**
- `app/Models/Trip.php` (added `HasFactory`)
- `app/Http/Controllers/Controller.php` (added `AuthorizesRequests`)
- `routes/web.php` (dashboard → controller; `trips.pause`/`trips.resume`/`trips.destroy`)
- `resources/js/pages/Dashboard.vue` (placeholder → real dashboard)
- `resources/js/routes/index.ts`, `resources/js/routes/trips/*`, `resources/js/actions/*` (Wayfinder regenerated)

### Change Log

- 2026-06-30 — Implemented Story 3.1: the authenticated trip dashboard. Users see upcoming and past trips grouped by status (destination, dates, days-until-departure, status pill — no weather/analytics), pause/resume/delete optimistically through the single `Trip::transitionTo()` method with owner-scoped authorization, and soft-delete behind one calm confirm while `email_logs`/`feedback` survive (AD-5/AD-9). All gates green.

### Review Findings

_Code review 2026-06-30 (Epic 3 adversarial pass: Blind Hunter + Edge Case Hunter + Acceptance Auditor)_

- [ ] [Review][Patch] Pause/resume report success even when no transition occurred — `transition()` swallows `InvalidTripTransitionException` but `pause()`/`resume()` always flash the cheerful success copy, so a crafted PATCH against a completed trip shows "Paused — no emails until you resume." while nothing changed [app/Http/Controllers/TripController.php:376-422]
- [ ] [Review][Patch] Active status-pill uses `bg-surface-wash` instead of an accent token — diverges from design intent in dark mode (surface-wash `#1b2d3d` vs accent-wash `#22384a`); `bg-accent-wash` utility doesn't exist, correct token is `bg-accent` [resources/js/pages/Dashboard.vue, resources/js/pages/Admin.vue]
