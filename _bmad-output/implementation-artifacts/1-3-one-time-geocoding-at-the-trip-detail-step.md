# Story 1.3: One-time geocoding at the trip-detail step

Status: ready-for-dev

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

As a visitor,
I want my destination resolved to a real place before I commit,
so that Tripcast watches the right location and never creates an unmonitorable trip.

## Acceptance Criteria

**AC1 — Resolve once, at the trip-detail step, and show the canonical name back for passive confirm** *(FR-10, AD-8, AD-1, UX-DR3, UX-DR15)*
- **Given** a submitted Destination (Story 1.2) and a `Geocoder` port with a `GoogleGeocoder` adapter (vendor HTTP only in the adapter)
- **When** geocoding runs — **once, at the trip-detail step, before email capture, outside any DB transaction**
- **Then** it resolves to a **Canonical Place Name + latitude/longitude**, held in the session, and shown back for **passive confirm** ("Watching Edinburgh, United Kingdom. Not right? Edit destination.") with a quiet **"Edit destination"** affordance and a **"Finding that place…"** pending state that **disables submit so it can't double-fire**.

**AC2 — Ambiguous name resolves to the most-likely locale (no picker)** *(FR-10)*
- **Given** an ambiguous name ("Paris")
- **When** it resolves
- **Then** it picks the **most-likely locale** (e.g. "Paris, France") and shows that name back to confirm — **no interactive "did you mean?" picker**.

**AC3 — Failure surfaces inline; nothing is created** *(FR-10, AD-8, UX-DR15)*
- **Given** geocoding fails or returns nothing usable
- **When** the visitor submits
- **Then** an **inline error** is shown ("We couldn't find that place. Try a city and country — like 'Edinburgh, UK'." + "Edit destination") and **no Trip and no coordinates are created** (still no DB writes — that's Story 1.4).

**AC4 — Cross-cutting gates** *(UX-DR18, UX-DR19)*
- WCAG 2.2 AA (labeled controls, announced errors/pending status, visible focus, ≥44px targets, contrast) and responsive mobile-first single column on the trip-detail / confirm surface.

## Tasks / Subtasks

- [ ] **Task 1 — `Geocoder` port + result type + typed failure** (AC: 1, 2, 3)
  - [ ] `app/Services/Geocoding/Geocoder.php` — interface: `geocode(string $destination): GeocodeResult` (capability-noun port, AD-1)
  - [ ] `app/Services/Geocoding/GeocodeResult.php` — readonly DTO: `canonicalPlaceName`, `latitude`, `longitude`
  - [ ] `app/Services/Geocoding/GeocodingFailedException.php` — thrown on no-result/unusable; caught at the controller boundary → inline error
- [ ] **Task 2 — `GoogleGeocoder` adapter + `FakeGeocoder` + binding** (AC: 1, 2, 3)
  - [ ] `app/Services/Geocoding/GoogleGeocoder.php` — calls Google Maps Geocoding API via Laravel `Http` (vendor HTTP **only here**); maps `results[0].formatted_address` → canonical name, `geometry.location.{lat,lng}` → coords; `ZERO_RESULTS`/empty/non-OK → throw `GeocodingFailedException`; **most-likely = `results[0]`** (no picker, AC2)
  - [ ] `app/Services/Geocoding/FakeGeocoder.php` — deterministic stub for local dev (no key yet) + tests; returns a canonical name + fixed coords for known inputs, throws for a sentinel "unfindable" input
  - [ ] `config/services.php` — `google.geocoding_key` from `GOOGLE_GEOCODING_KEY`; add the var to `.env` / `.env.example` (empty for now)
  - [ ] Bind `Geocoder` in `AppServiceProvider`: **`GoogleGeocoder` when `google.geocoding_key` is set, else `FakeGeocoder`** — so local dev works before the key arrives and tests are deterministic (AD-1 convention: bind port→adapter in a ServiceProvider)
- [ ] **Task 3 — Run geocoding at the trip-detail step (landing submit)** (AC: 1, 2, 3)
  - [ ] In `LandingController@store` (after validation, **outside any DB transaction**), call `Geocoder::geocode($destination)`; on success **merge** `canonical_place_name` + `latitude` + `longitude` into the `pending_trip` session payload and redirect to `trip.detail`
  - [ ] On `GeocodingFailedException`, redirect back to `home` with the **locked failure message** on the `destination` field and preserved input — **no coords, no Trip**
  - [ ] Geocode **once** per submission; do not re-geocode on a plain `GET trip.detail` revisit (the resolved result is already in session)
- [ ] **Task 4 — Trip-detail passive-confirm UI** (AC: 1, 4)
  - [ ] Replace `TripDetailPlaceholder.vue` with `pages/TripDetail.vue`: show the **Canonical Place Name** back ("Watching {canonical}. Not right? Edit destination."), a quiet **"Edit destination"** link back to `home`, and the dates; this is the passive confirm (no picker)
  - [ ] **"Finding that place…"** pending state: the landing form's submit button shows it and is **disabled while in flight** (`form.processing`) so it can't double-fire (UX-DR3, UX-DR15)
  - [ ] A11y: labeled/announced pending + error states, visible focus, ≥44px targets, mobile-first single column
- [ ] **Task 5 — Tests** (AC: 1, 2, 3)
  - [ ] Bind `FakeGeocoder` in the test environment (deterministic); assert a valid submit **merges `canonical_place_name`/`latitude`/`longitude` into `pending_trip`** and lands on `trip.detail` showing the canonical name
  - [ ] Ambiguous input returns the most-likely canonical name (fake returns one result; assert no picker/array of choices)
  - [ ] The "unfindable" sentinel → inline failure message on `destination`, **no coords in session**, redirect back to `home`, zero `users` rows
  - [ ] Geocoding is **not** wrapped in a DB transaction and makes **no DB writes** (assert `User::count() === 0`); `GoogleGeocoder` is never hit in tests (HTTP faked/avoided via the bound `FakeGeocoder`)
  - [ ] (Adapter unit) with `Http::fake()`, `GoogleGeocoder` maps a sample Google payload → `GeocodeResult` and throws on `ZERO_RESULTS`

## Dev Notes

### Scope boundary (read first)
- This story adds **geocoding only** — the `Geocoder` port/adapter and resolving the session's destination at the trip-detail step. **Still no DB writes, no `trips` table, no account.** The atomic `User`+`Trip` insert is **Story 1.4** (`CreateTrip` action). [Source: epics.md#Story-1.4; ARCHITECTURE-SPINE.md#AD-10]
- This story **evolves Story 1.2's flow**: `LandingController@store` now also geocodes, and the `trip.detail` placeholder becomes the real passive-confirm page. Keep 1.2's validation + session behavior intact. [Source: 1-2 File List]

### Architecture (binding)
- **AD-1 — ports for external I/O:** the `Geocoder` **interface** is the seam; the vendor HTTP/SDK appears **only** in `GoogleGeocoder`. Code depends on `Geocoder`, never on Google directly. Port = capability noun (`Geocoder`); adapter = vendor-prefixed (`GoogleGeocoder`). Bind port→adapter in a ServiceProvider. Weather has no dependency here — geocoding is independent. [Source: ARCHITECTURE-SPINE.md#AD-1, #Consistency-Conventions "Naming — ports"]
- **AD-8 — geocode once at creation; a Trip cannot exist without coordinates:** `latitude`, `longitude`, `canonical_place_name` are **required and set exactly once**, by the `Geocoder`, at the **trip-detail step — before email capture and outside any DB transaction** (it's an external HTTP call). The result is **held in the session** for passive confirm. On failure: **no Trip/coords**, inline error. Coordinates are never recomputed later. [Source: ARCHITECTURE-SPINE.md#AD-8]
- **AD-10 — session-carried flow:** extend the `pending_trip` session payload (Story 1.2) with the resolved `canonical_place_name`/`latitude`/`longitude`. Story 1.4's single DB-only transaction reads this. `trip.user_id` not-null + no orphan trips is enforced there, not here. [Source: ARCHITECTURE-SPINE.md#AD-10]
- **Layering:** thin controller calls the port. Geocoding is **not** inside any transaction and **not** part of `CreateTrip`. [Source: ARCHITECTURE-SPINE.md#Design-Paradigm, #AD-8]
- **Config & secrets:** provider keys live in `.env`; binding in `AppServiceProvider`/dedicated provider. [Source: ARCHITECTURE-SPINE.md#Consistency-Conventions "Config & secrets"]

### Geocoding flow (concrete)
1. Landing form submit (Story 1.2 `POST /`) → `TripSetupRequest` validates → **then** `LandingController@store` calls `Geocoder::geocode($validated['destination'])` (outside any DB transaction).
2. **Success** → merge `canonical_place_name`, `latitude`, `longitude` into `session('pending_trip')`; redirect to `trip.detail`, which renders the passive confirm.
3. **Failure** (`GeocodingFailedException`) → `back()` to `home` with `withErrors(['destination' => "We couldn't find that place. Try a city and country — like 'Edinburgh, UK'."])` and preserved input; no coords stored.
4. **"Edit destination"** on the confirm page → link to `home` (the form still holds values via the visitor's back/Inertia state); editing + resubmit re-geocodes (a new submission — still "once" per submission). [Source: EXPERIENCE.md Flow 1; UX-DR3]
- **Pending state:** the "Finding that place…" copy is the landing submit's in-flight state (`form.processing` → button label swap + `disabled`), satisfying "disables submit so it can't double-fire." [Source: EXPERIENCE.md State Patterns "Resolving destination"; UX-DR3]

### UX — locked microcopy (use verbatim)
- Resolving (pending): **"Finding that place…"**
- Geocoding confirm: **"Watching {place}. Not right? Edit destination."** (e.g. "Watching Edinburgh, United Kingdom.")
- Geocoding failure: **"We couldn't find that place. Try a city and country — like 'Edinburgh, UK'."** + **"Edit destination"**
- Passive confirm only — **no "did you mean?" picker** in v1. [Source: EXPERIENCE.md Voice and Tone "Written strings", Component Patterns "Geocoding confirm", State Patterns "Geocoding ambiguous/failure"]

### GoogleGeocoder adapter specifics
- Endpoint: `GET https://maps.googleapis.com/maps/api/geocode/json` with `address` (the raw destination) + `key` (config). Use Laravel's `Http` client (timeout it). [Source: ARCHITECTURE-SPINE.md#Stack "Google Maps Geocoding API (HTTP)"]
- Map: `results[0].formatted_address` → `canonicalPlaceName`; `results[0].geometry.location.lat`/`lng` → coords. **Most-likely = the first result** (Google returns them ranked) — this is AC2's "most-likely locale, no picker."
- Treat `status !== 'OK'` or empty `results` (incl. `ZERO_RESULTS`) and any transport error as `GeocodingFailedException`. Do **not** leak the vendor exception past the adapter.
- Key handling: read `config('services.google.geocoding_key')`. **Binding rule:** `AppServiceProvider::register()` binds `Geocoder::class` → `GoogleGeocoder` when that key is non-empty, otherwise → `FakeGeocoder`. This lets local dev + CI run **before the real key exists** and keeps tests off the network. (Clayton is provisioning the key; nothing blocks on it.)

### FakeGeocoder (local dev + tests)
- Deterministic: return a `GeocodeResult` with a canonical name derived from the input (e.g. title-cased + a fixed country) and fixed plausible coords; for a sentinel input (e.g. `'__unfindable__'` or an empty-after-trim edge) throw `GeocodingFailedException`. Keep it dependency-free. Tests bind it explicitly via `$this->app->instance(Geocoder::class, …)` or rely on the empty-key default binding.

### Testing standards
- Pest feature tests against MySQL `tripcast_test` with `RefreshDatabase` (existing setup). Bind `FakeGeocoder` so no network. [Source: 1-1/1-2 test setup]
- Assert **no DB writes** (`expect(User::count())->toBe(0)`); do not reference a `trips` table/model (not created until 1.4).
- Adapter test uses `Http::fake([...])` with a representative Google payload + a `ZERO_RESULTS` payload; assert mapping + the thrown exception. Never call the real API in tests.
- Gates before "done": `./vendor/bin/pest`, `./vendor/bin/pint`, `./vendor/bin/phpstan analyse`, `npm run types:check`, `npm run lint:check`, `npm run build:ssr`.

### Project Structure Notes
- New: `app/Services/Geocoding/{Geocoder,GeocodeResult,GoogleGeocoder,FakeGeocoder,GeocodingFailedException}.php`, `resources/js/pages/TripDetail.vue`, tests; **modified:** `app/Http/Controllers/LandingController.php` (store geocodes; tripDetail renders `TripDetail`), `config/services.php`, `.env`/`.env.example`, `resources/js/app.ts` (rename placeholder page), `app/Providers/AppServiceProvider.php` (binding). Delete `resources/js/pages/TripDetailPlaceholder.vue`. [Source: ARCHITECTURE-SPINE.md#Structural-Seed "Services/Geocoding"]

### Previous story intelligence (Story 1.2)
- `pending_trip` session payload shape is `{destination, departure_date, return_date}` — **extend it**, don't replace it. `trip.detail` (`GET /trip`) already redirects home when the session lacks `pending_trip`; keep that guard. [Source: 1-2 LandingController, TripSetupTest]
- 1.2's `TripDetailPlaceholder.vue` is explicitly the thing this story replaces. The landing form already uses Inertia `useForm` — reuse its `processing` state for the "Finding that place…" pending behavior; reuse `InputError` (ink-secondary, `role="alert"`) for the failure message. [Source: 1-2 Landing.vue, File List]
- 1.2's `LandingController@store` currently only validates + stashes + redirects — this story inserts the geocode call between validation and redirect. Keep the locked validation messages and the no-DB-writes invariant. [Source: 1-2 LandingController]
- Quality lesson carried from 1.1/1.2: run **PHPStan**; keep vendor code isolated to the adapter; locked copy verbatim.

### References
- [Source: _bmad-output/planning-artifacts/epics.md#Story-1.3] (+ #Story-1.4 for the scope boundary)
- [Source: _bmad-output/planning-artifacts/architecture/architecture-tripcast-2026-06-28/ARCHITECTURE-SPINE.md#AD-1, #AD-8, #AD-10, #Consistency-Conventions, #Structural-Seed, #Stack]
- [Source: _bmad-output/planning-artifacts/ux-designs/ux-tripcast-2026-06-28/EXPERIENCE.md#Voice-and-Tone, #Component-Patterns, #State-Patterns, #Key-Flows Flow 1]
- [Source: _bmad-output/planning-artifacts/ux-designs/ux-tripcast-2026-06-28/DESIGN.md#Components "Trip-setup form"]
- [Source: _bmad-output/specs/spec-tripcast/SPEC.md#FR-10]

## Dev Agent Record

### Agent Model Used

### Debug Log References

### Completion Notes List

### File List
