---
baseline_commit: e55b4942b7bd40da1f5f632023e2821b11e29a94
---

# Story 1.2: Inline trip-setup form on the landing hero

Status: review

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

As a visitor,
I want to enter a destination and dates in the landing hero and submit without signing up,
so that the product's value starts before any account exists.

## Acceptance Criteria

**AC1 — Inline validation with specific, non-destructive messages** *(FR-1, UX-DR3, UX-DR16)*
- **Given** the public landing page (SSR-rendered)
- **When** I enter a Destination + a Departure + a Return date and submit
- **Then** the form accepts it only if **Destination is non-empty**, **Return ≥ Departure**, and **Departure is not in the past**; otherwise it shows the **specific** locked inline message (empty / return-before-departure / past-departure) **without losing my other entries**.

**AC2 — Valid submission stashes to session; nothing is persisted** *(FR-1, AD-10)*
- **Given** a valid submission
- **When** I proceed
- **Then** the entered Destination + Departure + Return are **preserved in the server session** for the next (geocoding/email) step, and **no `Trip` and no `User` row is created** (no DB writes at all).

**AC3 — Landing hero presentation** *(UX-DR3, UX-DR14, UX-DR16, DESIGN landing)*
- **Given** the landing page
- **When** it renders
- **Then** the brand tagline **"The weather app you never have to open."** appears, and the trip-setup form is the hero — a `surface-raised` card on a `surface-wash` band — with a full-width Destination field, the Departure/Return date range, and a **single** accent submit, built on the existing Button + Input primitives.

**AC4 — Cross-cutting gates** *(UX-DR18, UX-DR19)*
- WCAG 2.2 AA (semantic landmarks, labeled controls, field-associated announced errors, visible focus, ≥44px targets, contrast) and responsive **mobile-first single column** with the form usable **above the fold on a phone**. Acceptance gates on this surface.

## Tasks / Subtasks

- [x] **Task 1 — Landing route + controller (replace the 1.1 placeholder)** (AC: 1, 2, 3)
  - [x] Replace the `Route::inertia('/', 'Welcome')` placeholder with `GET /` → `LandingController@show` (name `home`), rendering the new `Landing` page
  - [x] Add `POST /` → `LandingController@store` (name `trip-setup.store`)
  - [x] Add `GET trip` placeholder next-step route (name `trip.detail`) that redirects to `home` when the session has no pending trip, else renders a minimal placeholder page — **Story 1.3 replaces this with geocoding**
- [x] **Task 2 — Validation via a FormRequest with locked messages** (AC: 1)
  - [x] `app/Http/Requests/TripSetupRequest.php`: rules — `destination` required string (trimmed, max 255); `departure_date` required date `>= today` (America/New_York); `return_date` required date `>= departure_date`
  - [x] Custom messages = the **locked microcopy** (empty → "Where are you headed?"; return-before-departure → "Return is before departure — check the dates."; past-departure → "That date's already passed — pick a future trip.")
  - [x] Use `now('America/New_York')->toDateString()` as the past-departure floor (AD-7); server-side is authoritative
- [x] **Task 3 — Session stash, no persistence** (AC: 2)
  - [x] On valid submit, store `['destination', 'departure_date', 'return_date']` under the `pending_trip` session key; **no model writes**
  - [x] Redirect to `trip.detail` (the placeholder next step)
- [x] **Task 4 — Landing hero page on the primitives** (AC: 3, 4)
  - [x] `resources/js/pages/Landing.vue` (deleted the placeholder `Welcome.vue`; updated `app.ts` layout map): tagline + trip-setup form card (`surface-raised` on a `surface-wash` band), Destination `Input`, two date `Input`s, one accent `Button`
  - [x] Inertia `useForm` so values **survive a validation error**; bind `aria-invalid` + `aria-describedby`; associate `InputError` per field; semantic `<main>`/`<form>`, labels, ≥44px targets, mobile-first single column
  - [x] No auth required; guests and authenticated users can both see the landing
- [x] **Task 5 — Tests** (AC: 1, 2, 3)
  - [x] Feature: `GET /` renders the `Landing` Inertia component
  - [x] Feature: valid POST stashes `pending_trip` in session, redirects to `trip.detail`, and creates **zero** `users` rows
  - [x] Feature: empty destination / return-before-departure / past-departure each return the **exact** locked message on the right field, with other inputs preserved (old input flashed)
  - [x] Feature: a past departure relative to America/New_York "today" is rejected (clock pinned with `Carbon::setTestNow`); a departure of today is accepted (boundary); placeholder redirects home without a pending trip

## Dev Notes

### Scope boundary (read first)
- This story is **only** the landing hero form + validation + session hand-off. **No geocoding** (that's Story 1.3, `Geocoder` port) and **no DB writes / account / trip creation** (that's Story 1.4, `CreateTrip` action + atomic transaction). Do **not** create the `trips` table, the `User`+`Trip` insert, or call any external API here. [Source: epics.md#Story-1.3, #Story-1.4]
- The `trip.detail` route you add is a **temporary placeholder** so the flow is visible/testable; Story 1.3 owns its real content (the "Finding that place…" geocoding confirm step). [Source: epics.md#Story-1.3; EXPERIENCE.md State Patterns "Resolving destination"]

### Architecture & data flow (binding)
- **Layered, thin controller → (later) Action:** Presentation (Inertia Page) → thin `LandingController` → *(Story 1.4)* `CreateTrip` Action. In 1.2 the controller validates and stashes the session; it must **not** grow domain logic. [Source: ARCHITECTURE-SPINE.md#Design-Paradigm; Capability Map FR-1/2 → `Pages/Landing`, `Http/Controllers/LandingController`]
- **AD-10 (the reason for the session):** pre-account trip details are held in the **server session** through the email-capture step; the `User`+`Trip` insert is a single DB-only transaction **later** (Story 1.4). 1.2 establishes the first half of that session-carried flow — "a Trip never exists without an owner," so nothing is written until email capture. [Source: ARCHITECTURE-SPINE.md#AD-10]
- **AD-7 (date semantics):** `departure_date` / `return_date` are **timezone-naive `DATE`** (no time component). "Departure not in the past" uses the **America/New_York calendar date as 'today'** (the fixed send clock) — `now('America/New_York')->toDateString()`. Do not use the server's default TZ or a user TZ for this check. [Source: ARCHITECTURE-SPINE.md#AD-7; Consistency Conventions "Dates & times"]
- **No new migrations or models in this story.** [Source: epics.md#Story-1.2 ACs]

### UX (binding)
- **UX-DR3 — Trip-setup form (landing hero):** `surface-raised` card on the `surface-wash` band, `rounded/lg`; Destination full-width; date range below; **single** accent submit; inline validation with specific copy; the form **is** the hero with the tagline above it. (The "show Canonical Place Name back / Finding that place…" passive-confirm behavior is **Story 1.3**, not here.) [Source: DESIGN.md#Components "Trip-setup form"; EXPERIENCE.md Component Patterns "Trip-setup form"]
- **UX-DR14 — primitives:** reuse the existing `Button` (accent fill, white text, one primary per surface) and `Input` (`surface-raised`, hairline, `rounded/sm`, visible 2px accent focus ring, inline validation text in `ink-secondary`) built in Story 1.1 — do **not** re-create them. [Source: DESIGN.md#Components "Buttons", "Inputs"; Story 1.1 `resources/js/components/ui/{button,input}`]
- **Tagline is a brand asset and must appear on the landing page:** "The weather app you never have to open." [Source: DESIGN.md#Brand & Style]
- **UX-DR16 — locked microcopy (use verbatim):** empty destination → **"Where are you headed?"**; return-before-departure → **"Return is before departure — check the dates."**; past departure → **"That date's already passed — pick a future trip."** [Source: EXPERIENCE.md Voice and Tone "Written strings"]
- **State pattern:** preserve other entered values on a validation error (don't clear the form). [Source: EXPERIENCE.md State Patterns "Past / invalid dates — preserve other entered values"]

### Implementation guidance
- **Form + error preservation:** use Inertia `useForm` and submit via the Wayfinder route helper for `POST /`; on a 422 the validation errors return and `useForm` keeps the field values automatically (satisfies "without losing my other entries"). Associate each error with its field (`aria-describedby` + `InputError`, which already renders in `ink-secondary` with `role="alert"`). [Source: Story 1.1 `resources/js/components/InputError.vue`; resources/js auth pages as the established pattern]
- **Dates:** native `<input type="date">` via the `Input` primitive is the simplest accessible, mobile-friendly choice for v1; set the Departure `min` to today as a client convenience, but the **FormRequest is authoritative**. Two separate date fields (departure, return), not a range picker.
- **Validation rules sketch (TripSetupRequest):** `destination` ⇒ `['required','string','max:255']`; `departure_date` ⇒ `['required','date','after_or_equal:'.now('America/New_York')->toDateString()]`; `return_date` ⇒ `['required','date','after_or_equal:departure_date']`. Map each failing rule to its locked message via `messages()`. (Trim the destination; `'required'` on a whitespace-only string should still fail — Laravel trims via `TrimStrings` middleware by default, so `"   "` → empty → required fires the "Where are you headed?" message.)
- **Session key:** one structured key, e.g. `session(['pending_trip' => [...]])`; Story 1.4 reads it. Keep the shape minimal and documented.
- **Routing note:** `GET /` currently maps to the `Welcome` Inertia placeholder from Story 1.1 (`routes/web.php`). Replace it with `LandingController@show` keeping the route name `home` (the auth layout, the magic-link result resend, and `useAppearance`/header link all reference `home`). Regenerate Wayfinder types after changing routes (`php artisan wayfinder:generate`) and re-run `npm run build:ssr`. [Source: Story 1.1 routes/web.php, resources/js/routes]

### Testing standards
- Pest feature tests against the MySQL `tripcast_test` DB with `RefreshDatabase` (already configured in Story 1.1: `phpunit.xml`, `tests/Pest.php`). [Source: Story 1.1 test setup]
- Assert **no DB writes**: `expect(User::count())->toBe(0)` and (since `trips` doesn't exist yet) simply assert no `users` rows — do not reference a `trips` table/model that this story does not create.
- Pin the clock with `Illuminate\Support\Carbon::setTestNow(...)` for the past-departure test so it is deterministic regardless of when CI runs; use an America/New_York-anchored date in the assertion.
- Use Inertia assertions (`assertInertia(fn ($page) => $page->component('Landing'))`) following the Story 1.1 pattern; assert the locked messages with `assertSessionHasErrors(['destination' => 'Where are you headed?'])` etc. (or `assertInvalid`).
- Frontend gates before "done": `npm run types:check`, `npm run lint:check`, `npm run build:ssr`, and `./vendor/bin/pint` + `./vendor/bin/phpstan analyse` (PHPStan is a real gate — Story 1.1's review caught that it had been skipped).

### Project Structure Notes
- New/changed (per the architecture source tree): `app/Http/Controllers/LandingController.php`, `app/Http/Requests/TripSetupRequest.php`, `routes/web.php`, `resources/js/pages/Landing.vue` (replacing `Welcome.vue`), `resources/js/app.ts` (layout map for the renamed page), tests. [Source: ARCHITECTURE-SPINE.md#Structural-Seed source tree: `Pages/Landing`, `Http/Controllers/LandingController`]
- The placeholder `Welcome.vue` and its `Route::inertia('/', 'Welcome')` from Story 1.1 are explicitly meant to be replaced here. The Story 1.1 placeholder Dashboard remains untouched.

### Previous story intelligence (Story 1.1)
- Design tokens, dark-mode (`.dark`/`.light` + `prefers-color-scheme`), and the **Button/Input primitives are already built and token-bound** — reuse them; the Input is `surface-raised`/hairline/`rounded-sm`/2px accent focus ring, the InputError renders in `ink-secondary` with `role="alert"`. [Source: 1-1 File List: resources/css/app.css, components/ui/input/Input.vue, components/InputError.vue]
- Established patterns to mirror: Inertia `useForm` + Wayfinder route helpers, semantic `<main>` landmark, `h-11` (≥44px) buttons, labeled inputs with `aria-invalid`/`aria-describedby` (see the auth pages). [Source: 1-1 resources/js/pages/auth/*]
- Auth-stack reminder: long-lived sessions are configured; the landing must work for **both** guests and logged-in users (no `auth`/`guest` middleware on `/`). [Source: 1-1 config/session.php, routes/web.php]
- Quality gate lesson from the 1.1 code review: **run PHPStan** (it was failing unnoticed); keep mass-assignment tight and validation messages exact.

### References
- [Source: _bmad-output/planning-artifacts/epics.md#Story-1.2] (and #Story-1.3/#Story-1.4 for the scope boundary)
- [Source: _bmad-output/planning-artifacts/architecture/architecture-tripcast-2026-06-28/ARCHITECTURE-SPINE.md#AD-10, #AD-7, #Design-Paradigm, #Structural-Seed, #Consistency-Conventions]
- [Source: _bmad-output/planning-artifacts/ux-designs/ux-tripcast-2026-06-28/DESIGN.md#Components, #Brand & Style]
- [Source: _bmad-output/planning-artifacts/ux-designs/ux-tripcast-2026-06-28/EXPERIENCE.md#Component-Patterns, #State-Patterns, #Voice-and-Tone]
- [Source: _bmad-output/specs/spec-tripcast/SPEC.md#FR-1]

## Dev Agent Record

### Agent Model Used

Amelia (Senior Software Engineer) — claude-opus-4-8[1m]

### Debug Log References

- TDD: wrote `tests/Feature/Landing/TripSetupTest.php` first (8 red) → implemented → 8 green.
- Full suite: `./vendor/bin/pest` 33 passed / 137 assertions. Pint clean, PHPStan 0, ESLint clean, `vue-tsc` clean, `build:ssr` green.

### Completion Notes List

- Scope held to FR-1 exactly: landing hero form + validation + `pending_trip` session hand-off. **No** geocoding, **no** DB writes, **no** new tables/models (those are Stories 1.3/1.4).
- `GET /` now renders `Landing` (replaced the Story 1.1 `Welcome` placeholder, route name `home` preserved); `POST /` → validate + stash + redirect to `trip.detail`.
- `trip.detail` (`GET /trip` → `TripDetailPlaceholder`) is an explicit placeholder for Story 1.3's geocoding confirm step; it redirects to `home` when no `pending_trip` is in session.
- Past-departure validated against `now('America/New_York')->toDateString()` (AD-7); tests pin the clock with `Carbon::setTestNow` for determinism. Boundary (departure = today) is accepted.
- Locked microcopy used verbatim for the three inline messages; other entries survive a validation error via Inertia `useForm` (client) + Laravel's flashed old input (server, asserted).
- A11y/responsive: `<main>` + `<form aria-label>`, labeled inputs with `aria-invalid`/`aria-describedby`, `InputError` (ink-secondary, `role="alert"`), 44px full-width submit, mobile-first single column on the `surface-wash` hero band.

### File List

**Created**
- `app/Http/Controllers/LandingController.php`
- `app/Http/Requests/TripSetupRequest.php`
- `resources/js/pages/Landing.vue`
- `resources/js/pages/TripDetailPlaceholder.vue`
- `tests/Feature/Landing/TripSetupTest.php`

**Modified**
- `routes/web.php` — landing `GET`/`POST` + `trip.detail` placeholder (replaces the `Welcome` inertia route)
- `resources/js/app.ts` — layout map (`Landing`/`TripDetailPlaceholder` → no layout)
- `resources/js/routes/*`, `resources/js/actions/*` — regenerated Wayfinder types

**Deleted**
- `resources/js/pages/Welcome.vue` — Story 1.1 placeholder, superseded by `Landing.vue`

### Change Log

| Date | Change |
| --- | --- |
| 2026-06-29 | Story 1.2 implemented: landing hero trip-setup form (FR-1) with inline validation (locked microcopy, AD-7 date frame) and `pending_trip` session hand-off (AD-10), no persistence. 8 new feature tests (33 total passing). Status → review. |

## Review Findings (Epic 1 batch review — 2026-06-29)

**Applied (High/Medium)**
- [x] [Review][Patch] Per-IP throttle on `POST /` (`throttle:20,1`) [routes/web.php]
- [x] [Review][Patch] "Edit destination" repopulates the form from the session (FR-1) [LandingController@show, resources/js/pages/Landing.vue]
- [x] [Review][Patch] `date_format:Y-m-d` rejects relative date strings ("today"/"tomorrow") [app/Http/Requests/TripSetupRequest.php]
