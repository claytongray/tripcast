---
baseline_commit: adb63fa
---

# Story 3.2: Add a trip from the dashboard

Status: review

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

As an authenticated user,
I want to add another trip from an inline panel,
so that I can watch more than one destination.

## Acceptance Criteria

**AC1 — Add-trip uses the same geocoding path and the single CreateTrip decision point** *(FR-12, AD-8, AD-10)*
- **Given** the dashboard add-trip inline panel
- **When** I add a Trip
- **Then** it uses the **same geocoding path as Story 1.3** (the `Geocoder` port, resolved once at submit) and creates the Trip through the **`CreateTrip` action** — the single creation decision point every add path routes through.

**AC2 — No email-capture step for a confirmed user; trip is immediately active and lands on a dated success screen** *(FR-9, AD-6, AD-11)*
- **Given** the user is already confirmed (`email_verified_at` not null, AD-6)
- **When** the Trip is created
- **Then** there is **no email-capture step** — the Trip is **immediately active-for-sending**, its **Welcome Email fires at creation** (honoring opt-out, AD-13), and the user lands on a **success state**: *"Trip added — your first forecast goes out {date}."* where `{date}` is the first daily-digest send date for that trip.

**AC3 — The success screen is shared and reused for the new-user email-confirmation landing** *(UX-DR4, UX-DR15)*
- **Given** the polished, dated "you're all set — first forecast goes out {date}" success screen built here
- **When** a brand-new user confirms their email (the first magic-link consume that activates their account + first trip)
- **Then** they land on the **same shared success screen** (replacing the Story 1.1/1.4 placeholder-dashboard "all set" status message — see the 2026-06-29 email-confirmation sprint change).

## Tasks / Subtasks

- [x] **Task 1 — First-send-date authority on `CadencePredicate`** (AC: 2)
  - [x] Add a public method to `app/Digest/CadencePredicate.php` — `firstSendDate(Trip $trip, CarbonInterface $date): CarbonImmutable` — returning the first date the daily digest will send: `max($date, departure_date − horizonDays)` on the calendar (the send window opens `horizon` days before departure, AD-11/AD-7). Reuse the existing private `horizonDays()`. This is the single authority for "your first forecast goes out {date}" — no second implementation in a controller. (Do **not** clamp to return_date; a just-added trip's departure is in the future, and the selector already bounds the close.)
- [x] **Task 2 — `AddTripRequest` (authenticated)** (AC: 1)
  - [x] Create `app/Http/Requests/AddTripRequest.php` mirroring `TripSetupRequest` rules (`destination` required string; `departure_date`/`return_date` `date_format:Y-m-d` with `after_or_equal` guards on the ET clock) **but** `authorize(): bool { return $this->user() !== null; }` and **omit `temperature_unit`** (a confirmed user already has an account preference; `CreateTrip` only applies the unit on user creation). Reuse the same locked microcopy `messages()`.
- [x] **Task 3 — `TripController@store` + success screen + routes** (AC: 1, 2, 3)
  - [x] Add `store(AddTripRequest $request, Geocoder $geocoder, CreateTrip $createTrip): RedirectResponse` to `app/Http/Controllers/TripController.php`. Geocode the destination once (outside any transaction, AD-8); on `GeocodingFailedException` return `back()->withInput()->withErrors(['destination' => "We couldn't find that place. Try a city and country — like 'Edinburgh, UK'."])` (verbatim — mirror `LandingController@store`). On success, call `$createTrip->handle($request->user()->email, [...validated + canonical_place_name/latitude/longitude])` and `redirect()->route('trips.added', $trip)` (PRG — refresh-safe).
  - [x] Add `added(Trip $trip, CadencePredicate $cadence): Response` — `$this->authorize('view', $trip)` (add a `view` ability to `TripPolicy`, owner-only), render `Inertia::render('TripAdded', ['destination' => $place, 'firstForecastDate' => $cadence->firstSendDate($trip, now('America/New_York'))->toDateString()])`. `$place` = canonical place name (fall back to raw).
  - [x] Routes in the `auth` group of `routes/web.php`: `Route::post('trips', [TripController::class, 'store'])->middleware('throttle:20,1')->name('trips.store')` (throttled — it hits geocoding + creates records + sends mail, same rationale as the landing POST) and `Route::get('trips/{trip}/added', [TripController::class, 'added'])->name('trips.added')`.
  - [x] Add `view(User $user, Trip $trip): bool` (owner) to `app/Policies/TripPolicy.php`.
- [x] **Task 4 — `TripAdded.vue` shared success screen** (AC: 2, 3)
  - [x] Create `resources/js/pages/TripAdded.vue` (rendered under `AppLayout` by default in `app.ts`). Props `{ destination: string; firstForecastDate: string }`. Calm, centered success state: a confirmation headline + **"Your first forecast goes out {formatted date}."** (format the `Y-m-d` to a friendly absolute date, e.g. "Wednesday, July 1" — parse manually to avoid TZ drift, reuse the month-array approach from `Dashboard.vue`). Include a primary link back to the dashboard ("View your trips" → `dashboard()`). Use the watching-motif register ("We're watching {destination}.").
  - [x] Wire the dashboard add-trip panel in `resources/js/pages/Dashboard.vue`: an inline, toggleable panel (a "+ Add a trip" button reveals the form) using `useForm({ destination, departure_date, return_date })` → `form.submit(store())` (Wayfinder `@/routes/trips` `store`). Reuse `Input`/`Label`/`Button`/`InputError` like `Landing.vue`. The empty-state CTA ("Add a trip") should open this panel too (replace the current `home()` link). Plain date inputs are fine (the polished range picker is deferred — see deferred-work.md).
- [x] **Task 5 — Reuse success screen on email-confirmation landing** (AC: 3)
  - [x] In `app/Http/Controllers/Auth/MagicLinkController@consume`, when the first consume **just confirmed** a new user and activated a trip, redirect to `route('trips.added', $trip)` (the user's relevant trip) instead of the placeholder-dashboard `status` flash. Preserve the existing already-confirmed-login behavior (those users continue to the dashboard as today). Pick the trip to feature: the user's most recently created active trip (the one the signup created). If a confirmed login has **no** such trip, keep the current dashboard redirect.
  - [x] Update the affected `MagicLinkTest`/auth assertions: a new-signup consume now lands on `TripAdded` (component assertion) with the right `firstForecastDate`; an already-confirmed login still lands where it did. Keep all existing magic-link tests green.
- [x] **Task 6 — Tests** (AC: 1, 2, 3)
  - [x] `tests/Feature/Trip/AddTripTest.php`: an authenticated confirmed user POSTs a valid trip → trip created **active** under that user (assert via `CreateTrip` effects), `WelcomeMail` **queued** (`Mail::fake()` + `assertQueued`), redirect to `trips.added`; the `added` page renders `TripAdded` with the computed `firstForecastDate`. Geocode failure (`mock(Geocoder::class)` to throw `GeocodingFailedException`) → redirect back with the `destination` error, **no** trip created. Validation failures (past departure, return before departure) → errors, no trip. A **guest** POST → redirect to `login`. A user **cannot** view another user's `trips.added` (403 via the `view` policy).
  - [x] Unit: `CadencePredicateTest` gains `firstSendDate` cases — departure beyond the horizon → `departure − horizon`; departure inside the horizon (or today) → today; pin the clock.
  - [x] **Gates:** `./vendor/bin/pest`, `vendor/bin/pint --dirty --format agent`, `./vendor/bin/phpstan analyse`, `npm run types:check`, `npm run lint:check`, `npm run build:ssr`.

## Dev Notes

### Scope boundary (read first)
- **In scope:** the inline add-trip panel, the authenticated create path through `CreateTrip`, the dated shared success screen, and reusing it on the new-user confirmation landing.
- **Out of scope:** the **3-active-trip cost-control cap** (Story 3.3 — it lands in `CreateTrip`; this story does **not** add it, so do not gate the add here). The **"pending" dashboard state** for unconfirmed users is **not applicable** to the dashboard add path (a logged-in user is confirmed by definition — they consumed a magic link to log in); don't build a pending UI here. The polished **date range picker** stays deferred (plain date inputs — deferred-work.md). [Source: epics.md#Story-3.2; sprint-change-proposal-2026-06-29-email-confirmation.md]

### Architecture (binding)
- **AD-10 — one creation decision point:** `CreateTrip` upserts the user (by case-insensitive email) and inserts the trip in one DB-only transaction; **every add path routes through it.** For a logged-in user, pass `$request->user()->email` — `firstOrCreate` matches the existing account and keeps their temperature preference. [Source: app/Actions/CreateTrip.php]
- **AD-8 — geocode once at submit, outside the transaction:** resolve via the `Geocoder` port to a `GeocodeResult` (`canonicalPlaceName`, `latitude`, `longitude`); never inside `CreateTrip`. Mirror `LandingController@store` exactly (same try/catch + error copy). [Source: app/Http/Controllers/LandingController.php; app/Services/Geocoding/Geocoder.php, GeocodeResult.php]
- **AD-6 / FR-9 — welcome timing:** `CreateTrip` already queues the welcome **only if the owner `hasConfirmedEmail()`** — true on the dashboard path, so the welcome fires at creation; opt-out honored in `SendWelcomeEmail`. No change to that action needed beyond calling it. [Source: app/Actions/CreateTrip.php; app/Actions/SendWelcomeEmail.php]
- **AD-11/AD-7 — send-window math is the cadence predicate's job:** the "first forecast goes out {date}" value must come from `CadencePredicate` (the single authority), computed on the America/New_York calendar date. The window opens `horizonDays` before departure. [Source: app/Digest/CadencePredicate.php]

### Code intel (exact patterns to reuse)
- **`CreateTrip::handle(string $email, array $tripDetails): Trip`** — `$tripDetails` shape: `destination`, `departure_date`, `return_date`, `canonical_place_name`, `latitude`, `longitude`, optional `temperature_unit`. Returns the `Trip`. Welcomes a confirmed owner. [Source: app/Actions/CreateTrip.php:30-58]
- **Geocode + error flow** — copy from `LandingController@store`: `try { $place = $geocoder->geocode($validated['destination']); } catch (GeocodingFailedException) { return back()->withInput()->withErrors([...]); }` then build the details array with `$place->canonicalPlaceName/latitude/longitude`. [Source: app/Http/Controllers/LandingController.php:40-66]
- **`TripSetupRequest`** — clone its rules/messages; the only deltas are `authorize()` (require a user) and dropping `temperature_unit`. Dates: `date_format:Y-m-d`, `after_or_equal` on `now('America/New_York')->toDateString()`. [Source: app/Http/Requests/TripSetupRequest.php]
- **`MagicLinkController@consume`** — already calls `$user->confirmEmail()` (returns `$justConfirmed`) and loops the user's trips queuing welcomes; today it redirects to the dashboard with a `status` flash. Change only the **new-signup** landing to `route('trips.added', $trip)`. Inject nothing new beyond what's there. [Source: app/Http/Controllers/Auth/MagicLinkController.php:103-136]
- **Inertia page + layout:** pages render under `AppLayout` by default (resolved centrally in `resources/js/app.ts`); `TripAdded` is a normal page (no wrapper needed). Forms use `useForm` + Wayfinder route objects (`form.submit(store())`); regenerate Wayfinder after adding routes (`php artisan wayfinder:generate`; gitignored). [Source: resources/js/app.ts; resources/js/pages/Landing.vue]
- **Dashboard already built (3.1):** `Dashboard.vue` renders grouped trips + an empty state with an "Add a trip" CTA (currently a `home()` link — repoint it to the new inline panel). `DashboardController@index` props unchanged. [Source: resources/js/pages/Dashboard.vue; app/Http/Controllers/DashboardController.php]

### Testing standards
- Pest, MySQL `tripcast_test`, `RefreshDatabase`, pinned ET clock (`Carbon::setTestNow`). `Mail::fake()` + `Mail::assertQueued(WelcomeMail::class)` for the welcome; `mock(Geocoder::class)` for the success and failure paths (don't hit Google). Inertia assertions (`assertInertia(fn ($p) => $p->component('TripAdded')->where('firstForecastDate', …))`). Authz: guest → `assertRedirect(route('login'))`; non-owner `trips.added` → `assertForbidden()`. [Source: tests/Feature/Landing/*, tests/Feature/Auth/MagicLinkTest.php]

### Project Structure Notes
- **New:** `app/Http/Requests/AddTripRequest.php`, `resources/js/pages/TripAdded.vue`, `tests/Feature/Trip/AddTripTest.php`.
- **Modified:** `app/Http/Controllers/TripController.php` (`store` + `added`), `app/Policies/TripPolicy.php` (`view`), `app/Digest/CadencePredicate.php` (`firstSendDate`), `routes/web.php` (`trips.store`, `trips.added`), `resources/js/pages/Dashboard.vue` (inline add panel), `app/Http/Controllers/Auth/MagicLinkController.php` (success-screen landing), `tests/Feature/Digest/CadencePredicateTest.php`, magic-link/auth tests, regenerated Wayfinder (gitignored).
- **Unchanged:** `CreateTrip`, `SendWelcomeEmail`, the `Geocoder` port. The cost cap is **not** added here (Story 3.3).

### Previous story intelligence (Story 3.1 + sprint change)
- 3.1 added `TripController`, `TripPolicy`, the dashboard, and `AuthorizesRequests` on the base controller — extend those, don't recreate. `now()` is `CarbonImmutable` app-wide (type clock params `CarbonInterface`). [Source: app/Http/Controllers/TripController.php, app/Policies/TripPolicy.php]
- The 2026-06-29 email-confirmation sprint change deferred the **polished, reusable success screen to Epic 3** (sub-decision C). This story is where it's built and retro-wired into the confirmation landing. [Source: sprint-change-proposal-2026-06-29-email-confirmation.md]

### References
- [Source: _bmad-output/planning-artifacts/epics.md#Story-3.2]
- [Source: _bmad-output/planning-artifacts/architecture/architecture-tripcast-2026-06-28/ARCHITECTURE-SPINE.md#AD-6, #AD-8, #AD-10, #AD-11, #AD-7]
- [Source: _bmad-output/specs/spec-tripcast/SPEC.md#FR-12, #FR-9]
- [Source: _bmad-output/planning-artifacts/sprint-change-proposal-2026-06-29-email-confirmation.md]
- [Source: app/Actions/CreateTrip.php; app/Http/Controllers/LandingController.php; app/Digest/CadencePredicate.php; app/Http/Controllers/Auth/MagicLinkController.php]

## Dev Agent Record

### Agent Model Used

claude-opus-4-8 (1M context)

### Debug Log References

- PHPStan: spreading `$request->validated()` (`array<string,mixed>`) into the `CreateTrip` call lost the required array shape. Added a typed `AddTripRequest::tripDetails(): array{...}` accessor (string-cast the validated values) rather than casting at the call site.

### Completion Notes List

- **Task 1 — `firstSendDate`:** added to `CadencePredicate` — `max(today, departure − horizon)` on the ET calendar; the single authority behind the success-screen date.
- **Task 2 — `AddTripRequest`:** authenticated clone of `TripSetupRequest` (no `temperature_unit`), plus a typed `tripDetails()` shape accessor for `CreateTrip`.
- **Task 3 — `TripController@store`/`added` + routes + policy:** `store` geocodes once (AD-8, mirrors `LandingController`), creates via `CreateTrip` (AD-10) for the authed owner, PRG-redirects to `trips.added`. `added` renders the shared `TripAdded` screen with `firstSendDate`, owner-gated by a new `TripPolicy::view`. Routes `trips.store` (throttled 20/1) + `trips.added` added to the auth group.
- **Task 4 — frontend:** new `TripAdded.vue` (calm dated success, "Your first forecast goes out {date}", TZ-safe date format). Dashboard gained an inline, toggleable add-trip panel (`useForm` → `trips.store`); the empty-state CTA now opens it.
- **Task 5 — confirmation landing:** `MagicLinkController@consume` now lands a freshly-confirmed new signup on `trips.added` for their just-created trip; returning logins and trip-less confirmations are unchanged. New `MagicLinkTest` case covers it; all prior magic-link tests stay green.
- **Task 6 — tests:** `AddTripTest` (active create + welcome queued + dated success; geocode-failure no-create; past/return validation; guest→login; non-owner 403), `firstSendDate` cadence cases, and the new-signup landing case. 9 new tests.
- **Verification:** full suite **176 passed** (667 assertions). `pint` clean, `phpstan` 0 errors, `types:check`/`lint`/`build:ssr` green. Cost cap intentionally **not** added (Story 3.3).

### File List

**New:**
- `app/Http/Requests/AddTripRequest.php`
- `resources/js/pages/TripAdded.vue`
- `tests/Feature/Trip/AddTripTest.php`

**Modified:**
- `app/Digest/CadencePredicate.php` (`firstSendDate`)
- `app/Http/Controllers/TripController.php` (`store` + `added`)
- `app/Policies/TripPolicy.php` (`view`)
- `app/Http/Controllers/Auth/MagicLinkController.php` (success-screen landing)
- `routes/web.php` (`trips.store`, `trips.added`)
- `resources/js/pages/Dashboard.vue` (inline add-trip panel)
- `tests/Feature/Digest/CadencePredicateTest.php`, `tests/Feature/Auth/MagicLinkTest.php`
- regenerated Wayfinder (gitignored)

### Change Log

- 2026-06-30 — Implemented Story 3.2: add-a-trip from the dashboard. An inline panel creates a trip through the single `CreateTrip` decision point (geocoded once at submit), with no email-capture step for the already-confirmed owner; the welcome fires at creation and the user lands on a shared, dated "your first forecast goes out {date}" success screen — reused for the new-user email-confirmation landing. All gates green.
