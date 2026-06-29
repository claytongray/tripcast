---
baseline_commit: bf4312988881c3126bf9bfc4e744859d3af55068
---

# Story 1.3: One-time geocoding at the trip-detail step

Status: review

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

- [x] **Task 1 — `Geocoder` port + result type + typed failure** (AC: 1, 2, 3)
  - [x] `app/Services/Geocoding/Geocoder.php` — interface: `geocode(string $destination): GeocodeResult` (capability-noun port, AD-1)
  - [x] `app/Services/Geocoding/GeocodeResult.php` — readonly DTO: `canonicalPlaceName`, `latitude`, `longitude`
  - [x] `app/Services/Geocoding/GeocodingFailedException.php` — thrown on no-result/unusable; caught at the controller boundary → inline error
- [x] **Task 2 — `GoogleGeocoder` adapter + `FakeGeocoder` + binding** (AC: 1, 2, 3)
  - [x] `app/Services/Geocoding/GoogleGeocoder.php` — calls Google Maps Geocoding API via Laravel `Http` (vendor HTTP **only here**); maps `results[0].formatted_address` → canonical name, `geometry.location.{lat,lng}` → coords; `ZERO_RESULTS`/empty/non-OK/HTTP-error → throw `GeocodingFailedException`; **most-likely = `results[0]`** (no picker, AC2)
  - [x] `app/Services/Geocoding/FakeGeocoder.php` — deterministic stub for local dev + tests; canonical name + fixed coords for known inputs, throws for an "unfindable" sentinel
  - [x] `config/services.php` — `google.geocoding_key` from `GOOGLE_GEOCODING_KEY`; var added to `.env` (real key, gitignored) / `.env.example` (empty)
  - [x] Bind `Geocoder` in `AppServiceProvider`: **`GoogleGeocoder` when the key is set, else `FakeGeocoder`** (AD-1)
- [x] **Task 3 — Run geocoding at the trip-detail step (landing submit)** (AC: 1, 2, 3)
  - [x] In `LandingController@store` (after validation, **outside any DB transaction**), call `Geocoder::geocode($destination)`; on success **merge** `canonical_place_name` + `latitude` + `longitude` into `pending_trip` and redirect to `trip.detail`
  - [x] On `GeocodingFailedException`, redirect back to `home` with the **locked failure message** on `destination` and preserved input — **no coords, no Trip**
  - [x] Geocode **once** per submission; `GET trip.detail` does not re-geocode (reads the session result)
- [x] **Task 4 — Trip-detail passive-confirm UI** (AC: 1, 4)
  - [x] `pages/TripDetail.vue` (replaced `TripDetailPlaceholder.vue`): "Watching {canonical}. Not right? **Edit destination**" link to `home`, plus dates — passive confirm, no picker
  - [x] **"Finding that place…"** pending state: landing submit shows it and is **disabled** while `form.processing`
  - [x] A11y: labeled controls, announced error/pending, visible focus, ≥44px target, mobile-first single column
- [x] **Task 5 — Tests** (AC: 1, 2, 3)
  - [x] `FakeGeocoder` bound in tests (deterministic, network-free); valid submit **merges canonical/lat/lng into `pending_trip`** and lands on `trip.detail` showing the canonical name
  - [x] Ambiguous "Paris" → single most-likely "Paris, France" (no picker)
  - [x] "Unfindable Place" → inline failure message on `destination`, **no coords in session**, redirect to `home`, zero `users`
  - [x] No DB writes anywhere (`User::count() === 0`); `GoogleGeocoder` never hit in feature tests (FakeGeocoder bound; phpunit key empty)
  - [x] Adapter unit (`Http::fake()`): maps a Google payload → `GeocodeResult`; throws on `ZERO_RESULTS` and on HTTP 500

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

Amelia (Senior Software Engineer) — claude-opus-4-8[1m]

### Debug Log References

- TDD: wrote `GeocodingTest` + `GoogleGeocoderTest` first (4 feature red / 3 adapter green) → implemented → all green.
- Full suite: `./vendor/bin/pest` 40 passed / 163 assertions. Pint clean, PHPStan 0, ESLint clean, vue-tsc clean, build:ssr green.
- Live smoke (real key): `app(Geocoder::class)` → `GoogleGeocoder`; `geocode('Edinburgh')` → "Edinburgh, UK" (55.953252, -3.188267).

### Completion Notes List

- `Geocoder` port + `GeocodeResult` DTO + typed `GeocodingFailedException` in `app/Services/Geocoding/`; vendor HTTP isolated to `GoogleGeocoder` (AD-1). `GoogleGeocoder` takes `results[0]` (Google-ranked = most-likely, no picker — AC2) and maps `ZERO_RESULTS`/empty/non-OK/transport errors to the typed exception.
- **Binding (AppServiceProvider):** `GoogleGeocoder` when `services.google.geocoding_key` is set, else `FakeGeocoder`. `phpunit.xml` sets `GOOGLE_GEOCODING_KEY=""` so feature tests use the fake (no network); feature tests also bind `FakeGeocoder` explicitly for clarity.
- Geocoding runs in `LandingController@store` after validation, **outside any DB transaction** (AD-8); success merges `canonical_place_name`/`latitude`/`longitude` into the Story-1.2 `pending_trip` session payload (AD-10); failure → `back()->withInput()->withErrors(['destination' => …])` with the locked copy. **Still no DB writes / no Trip** (that's Story 1.4).
- `trip.detail` now renders `TripDetail` (passive confirm: "Watching {canonical}. Not right? Edit destination."); the Story-1.2 redirect-home-without-pending-trip guard is retained. "Finding that place…" is the landing submit's in-flight state (button label + disabled), preventing double-fire.
- The real key lives in `.env` (gitignored, never committed); `.env.example` carries an empty placeholder. **Recommend restricting the key (Geocoding API + IP/referrer) and rotating before prod** — it appeared in chat.

### File List

**Created**
- `app/Services/Geocoding/Geocoder.php` · `GeocodeResult.php` · `GeocodingFailedException.php` · `GoogleGeocoder.php` · `FakeGeocoder.php`
- `resources/js/pages/TripDetail.vue`
- `tests/Feature/Landing/GeocodingTest.php` · `tests/Feature/Geocoding/GoogleGeocoderTest.php`

**Modified**
- `app/Http/Controllers/LandingController.php` — geocode on store + render `TripDetail`
- `app/Providers/AppServiceProvider.php` — Geocoder port binding
- `config/services.php` — `google.geocoding_key`
- `.env.example` — empty `GOOGLE_GEOCODING_KEY` placeholder (real key in gitignored `.env`)
- `phpunit.xml` — empty `GOOGLE_GEOCODING_KEY` (FakeGeocoder in tests)
- `resources/js/app.ts` — layout map (`TripDetail` → no layout)
- `resources/js/pages/Landing.vue` — "Finding that place…" pending submit state

**Deleted**
- `resources/js/pages/TripDetailPlaceholder.vue` — Story 1.2 placeholder, superseded by `TripDetail.vue`

### Change Log

| Date | Change |
| --- | --- |
| 2026-06-29 | Story 1.3 implemented: `Geocoder` port + `GoogleGeocoder`/`FakeGeocoder` (AD-1), geocode-once at the trip-detail step outside any DB transaction (AD-8) with session hand-off (AD-10), passive confirm UI + locked microcopy, no persistence. 7 new tests (40 total). Live key verified. Status → review. |

## Review Findings (Epic 1 batch review — 2026-06-29)

**Applied (High/Medium)**
- [x] [Review][Patch] `FakeGeocoder` fail-fast in production when `GOOGLE_GEOCODING_KEY` is missing [AppServiceProvider]
- [x] [Review][Patch] `tripDetail` uses the stricter `pendingTripIsComplete()` guard [LandingController@tripDetail]
- [x] [Review][Patch] `canonical_place_name` truncated to 255 in the adapter (guards the varchar column) [GoogleGeocoder]

**Action items (Low — open)**
- [ ] [Review][Patch] TripDetail confirm renders raw ISO dates; use the friendly format (UX-DR16) [resources/js/pages/TripDetail.vue]
- [ ] [Review][Patch] GoogleGeocoder: guard a non-JSON 200 body + branch/log transient statuses (OVER_QUERY_LIMIT/REQUEST_DENIED) vs ZERO_RESULTS [app/Services/Geocoding/GoogleGeocoder.php]

**Dismissed**
- Geocoding runs on the landing POST rather than a separate trip-detail submit — deliberate; AD-8 (once, outside any transaction, pending state on the submit) satisfied.
