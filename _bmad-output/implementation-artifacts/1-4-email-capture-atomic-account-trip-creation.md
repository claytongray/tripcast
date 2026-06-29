# Story 1.4: Email capture + atomic account & trip creation

Status: ready-for-dev

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

As a visitor,
I want to give just my email to save my trip,
so that I get an account and Tripcast starts watching — with no password and no orphan data.

## Acceptance Criteria

**AC1 — One email field creates the account + trip atomically (DB-only)** *(FR-2, AD-8, AD-10)*
- **Given** trip details + a resolved geocode held in the session (Stories 1.2–1.3)
- **When** I submit a single email (**exactly one field, never a password**)
- **Then** a **single DB-only transaction** upserts the `User` (create-or-match by case-insensitive email) and inserts the `Trip` with **`user_id` not null** and the stored coordinates + canonical name — **no external calls inside the transaction**.

**AC2 — On commit: magic link sent + welcome queued + interstitial** *(FR-2, UX-DR4, UX-DR10)*
- **Given** a successful creation
- **When** the transaction commits
- **Then** a **Magic Link is sent immediately** (Story 1.1's `RequestMagicLink`) **and a Welcome Email is queued** (Story 1.5), and the **check-your-email interstitial** is shown ("we sent a link to {email}, expires in N min").

**AC3 — No orphan data; guards** *(AD-10, AD-8)*
- **Given** the email step is reached without a pending trip in the session (direct hit, expired session)
- **When** it is submitted
- **Then** no account/trip is created and the visitor is returned to the landing form. **A `Trip` never exists without an owner** and never without coordinates.

**AC4 — Cross-cutting gates** *(UX-DR18, UX-DR19)*
- WCAG 2.2 AA (labeled single email control, announced errors, visible focus, ≥44px target) and responsive mobile-first single column on the email-capture surface.

## Tasks / Subtasks

- [ ] **Task 1 — `trips` table + `Trip` model** (AC: 1, 3)
  - [ ] Migration `create_trips_table`: `user_id` (FK, **not null**, cascade on delete), `destination_raw` (string), `canonical_place_name` (string, **not null**), `latitude` + `longitude` (**not null**, double), `departure_date` + `return_date` (**date**, naive), `status` (string, default `active`), `deleted_at` (**soft delete**, AD-5), timestamps
  - [ ] `app/Models/Trip.php`: `belongsTo(User)`, `SoftDeletes`, casts (`departure_date`/`return_date` → `date`, `latitude`/`longitude` → `float`), fillable for the trip attributes; **status constants** `active|paused|completed` (default `active`) — the AD-5 state-transition method is **deferred to its owning stories (2.5/3.1)**; 1.4 only creates an `active` trip
  - [ ] `User::trips()` `hasMany(Trip)`
- [ ] **Task 2 — `CreateTrip` action (the single creation decision point, AD-10)** (AC: 1, 3)
  - [ ] `app/Actions/CreateTrip.php`: `handle(string $email, array $tripDetails): Trip` wrapping a **`DB::transaction`** that (a) `User::firstOrCreate(['email' => lowercased-trimmed])` and (b) inserts the `Trip` via the relationship with the stored coords/canonical/dates — **no external calls (mail/geocode/etc.) inside the transaction** (AD-10)
  - [ ] Free-tier cap (AD-15) is **NOT** added here yet — it routes through this same action in **Story 3.3**; leave a comment marking this as the single decision point
- [ ] **Task 3 — Email-capture controller + route** (AC: 1, 2, 3)
  - [ ] `EmailCaptureRequest` FormRequest: `email` required|string|email|max:255 (calm message; no password field accepted)
  - [ ] `POST /trip` → `LandingController@createTrip` (name `trip.store`): **guard** — if session lacks a complete `pending_trip` (with `canonical_place_name`/`latitude`/`longitude`), redirect to `home` (AC3); else call `CreateTrip`, then **after commit** call `RequestMagicLink::handle($email)` (Story 1.1) to send the link, **forget** `pending_trip`, redirect to `login.sent` with `magic_email` + `magic_ttl` (the 1.1 interstitial)
  - [ ] **Welcome email seam:** mark the post-commit point where Story 1.5 will `queue` the Welcome Email (see Scope note); do not invent the mailable here
- [ ] **Task 4 — Email-capture UI on the confirm step** (AC: 1, 4)
  - [ ] Extend `pages/TripDetail.vue` (Story 1.3): below the "Watching {place}. Edit destination" confirm, add a **single email `Input`** + accent submit ("Email me a link") posting to `trip.store` via `useForm`; one field, **never a password**
  - [ ] A11y: labeled email field, `aria-invalid`/`aria-describedby` + `InputError`, ≥44px submit, mobile-first single column; submit shows an in-flight/disabled state
- [ ] **Task 5 — Tests** (AC: 1, 2, 3)
  - [ ] Valid email + complete `pending_trip` session → **exactly one** `User` + **one** `Trip` created; `trip.user_id` not null; coords/canonical/dates persisted; `Trip.status === 'active'`; `MagicLinkMail` sent (`Mail::fake`); redirect to `login.sent`; `pending_trip` forgotten
  - [ ] **Create-or-match:** a pre-existing user with the same CI email → **no duplicate user**, a new Trip attached to that user
  - [ ] **Guard:** POST without a pending trip in session → redirect `home`, zero users/trips created
  - [ ] Invalid email → validation error, **zero** users/trips
  - [ ] **Atomicity:** simulate a Trip-insert failure (e.g. bind a Trip that throws / a malformed detail) → the `User` upsert is **rolled back** (no partial row); document the approach if a clean simulation isn't feasible
  - [ ] **No external call inside the transaction:** assert the magic-link mail is sent (post-commit) — and structurally that `CreateTrip` contains no Mail/Http calls

## Dev Notes

### Scope boundary (read first)
- This is the **convergence** story: it creates the `trips` table + `Trip` model, the `CreateTrip` action (atomic `User`+`Trip`), the email-capture step, and wires in Story 1.1's magic link + interstitial. It **consumes** the session set up by 1.2 (dates) and 1.3 (geocode).
- **Welcome Email content is Story 1.5.** 1.4 establishes the **post-commit seam** where the welcome is queued, but does **not** build the `WelcomeMail` (copy, plain-text twin, opt-out honoring all belong to 1.5). If you queue anything here, it must be 1.5's mailable — so prefer leaving a clearly-marked seam and let 1.5 plug in. The magic link + interstitial (AC2) **are** in 1.4 (they reuse Story 1.1, which exists). [Source: epics.md#Story-1.5]
- **AD-15 free-tier cap is Story 3.3** — do not add it; just make `CreateTrip` the single decision point it will later guard. [Source: epics.md#Story-3.3; ARCHITECTURE-SPINE.md#AD-15]

### Architecture (binding)
- **AD-10 — atomic, DB-only, no orphans:** on email submit, a **single `DB::transaction`** upserts the `User` (create-or-match by CI email) and inserts the `Trip`; **no external calls inside the transaction**; `trip.user_id` is **not nullable**. The pre-account details + resolved geocode were held in the **server session** (1.2/1.3) — read them here. Magic-link send + welcome-queue happen **after** commit, outside the transaction. [Source: ARCHITECTURE-SPINE.md#AD-10]
- **AD-8 — coordinates required, set once:** the Trip is created with the `latitude`/`longitude`/`canonical_place_name` already resolved in Story 1.3 (in session) — **never re-geocode here, never inside the transaction**. A Trip cannot exist without coordinates. [Source: ARCHITECTURE-SPINE.md#AD-8]
- **AD-5 — Trip status/soft-delete:** `status` defaults to `active`; `completed` is terminal; **delete is a soft delete** (`deleted_at`). The **single state-transition method** + transitions are owned by Stories 2.5/3.1 — 1.4 only inserts an `active` trip and adds the columns + `SoftDeletes` trait so later stories have them. No controller writes `status` directly beyond creation default. [Source: ARCHITECTURE-SPINE.md#AD-5, #Structural-Seed]
- **Naming/structure:** `Trip` (singular model, `trips` table, snake_case columns); `CreateTrip` Action in `app/Actions`; `LandingController` owns FR-1/FR-2. [Source: ARCHITECTURE-SPINE.md#Consistency-Conventions, #Structural-Seed, Capability Map FR-1/2]
- **Magic link reuse:** `RequestMagicLink::handle($email)` (Story 1.1) already does create-or-match + issue + email + throttle. Call it **after** `CreateTrip` commits. Because `CreateTrip` already upserted the user, `RequestMagicLink` will match that same user. The interstitial is the existing `auth/CheckEmail` reached via `redirect()->route('login.sent')->with(['magic_email' => …, 'magic_ttl' => …])`. [Source: 1-1 RequestMagicLink, MagicLinkController@sent]

### Trip schema (this story's only new table)
```
trips: id, user_id (FK→users, not null, cascadeOnDelete),
       destination_raw (string), canonical_place_name (string, not null),
       latitude (double, not null), longitude (double, not null),
       departure_date (date), return_date (date),
       status (string, default 'active'),            // active|paused|completed (AD-5)
       deleted_at (nullable, softDeletes),           // AD-5
       timestamps
```
- Other tables (`email_logs`, `feedback`, `promo_events`) belong to later epics — **do not create them**. [Source: ARCHITECTURE-SPINE.md#Structural-Seed]
- Store dates as naive `date` (AD-7); coords as `double` (spine says float) — model casts `latitude`/`longitude` → `float`, dates → `date`.

### UX (binding)
- **UX-DR4 — Email-capture step:** same card language as the setup form; **single email input + accent submit; one field, nothing else**; advances to the check-your-email interstitial. Place it on the confirm step (the Story 1.3 `TripDetail` page already shows the canonical name + "Edit destination") so the visitor confirms the place and gives their email in one calm card. [Source: EXPERIENCE.md IA "Email-capture step", Component Patterns "Email-capture"; DESIGN.md#Components "Email-capture step"]
- **UX-DR10 — interstitial:** reuse Story 1.1's centered `surface-raised` "check your inbox — sent a link to {email}, expires in N min" with resend. [Source: 1-1 auth/CheckEmail]
- **Voice:** no password ever; calm. Email validation message stays calm (no locked string exists for it — keep it brief, e.g. "Enter a valid email address."). [Source: EXPERIENCE.md Voice and Tone]
- **Flow 1 (climax):** click → dashboard with the trip saved + welcome waiting; for 1.4 the immediate outcome is the interstitial (the magic-link click → dashboard is Story 1.1, already working). [Source: EXPERIENCE.md Key Flows Flow 1]

### Testing standards
- Pest feature tests, MySQL `tripcast_test`, `RefreshDatabase` (existing). Use `Mail::fake()` to assert the magic-link send. [Source: 1-1/1-2/1-3 setup]
- Seed the session with a complete `pending_trip` (use a helper) before POSTing the email; assert `User::count()`/`Trip::count()` exactly.
- For create-or-match, pre-create a `User` with a differently-cased email and assert no duplicate (CI collation + lowercasing in `RequestMagicLink`/`CreateTrip` — keep them consistent). [Source: 1-3 email lowercasing decision]
- Gates before "done": `./vendor/bin/pest`, `./vendor/bin/pint`, `./vendor/bin/phpstan analyse`, `npm run types:check`, `npm run lint:check`, `npm run build:ssr`.

### Project Structure Notes
- New: `database/migrations/..._create_trips_table.php`, `app/Models/Trip.php`, `app/Actions/CreateTrip.php`, `app/Http/Requests/EmailCaptureRequest.php`, tests; **modified:** `app/Models/User.php` (`trips()`), `app/Http/Controllers/LandingController.php` (`createTrip`), `routes/web.php` (`POST /trip`), `resources/js/pages/TripDetail.vue` (email field), regenerated Wayfinder. [Source: ARCHITECTURE-SPINE.md#Structural-Seed]

### Previous story intelligence (Stories 1.1–1.3)
- **Session payload** `pending_trip` = `{destination, departure_date, return_date, canonical_place_name, latitude, longitude}` (1.2 + 1.3). Read all of it here; **forget it** after a successful create. [Source: 1-2/1-3 LandingController]
- **`RequestMagicLink::handle(string $email): array`** returns `['user', 'expires_at', 'ttl_minutes']` and lowercases/trims the email + create-or-matches the user. Reuse it post-commit; align `CreateTrip`'s email normalization (lowercase+trim) with it so both resolve the same user. [Source: 1-1 RequestMagicLink]
- **User model** is mass-assignment-guarded (`plan`/`is_admin` not fillable); `email`/`timezone`/`email_opted_out` are fillable. `firstOrCreate(['email' => …])` is fine. **Trip** must likewise not expose anything dangerous in fillable. [Source: 1-1 review: User fillable]
- The Story 1.3 `TripDetail` page already imports `home`/`Link` and uses tokens; extend it with the email form using the existing `Input`/`Button`/`InputError` primitives + `useForm` (mirror `Landing.vue`). [Source: 1-3 TripDetail.vue, 1-2 Landing.vue]
- **Quality lessons:** run **PHPStan**; keep external calls out of the transaction; locked/calm copy; ≥44px targets; `aria` wiring.

### References
- [Source: _bmad-output/planning-artifacts/epics.md#Story-1.4] (+ #Story-1.5, #Story-3.3 for boundaries)
- [Source: _bmad-output/planning-artifacts/architecture/architecture-tripcast-2026-06-28/ARCHITECTURE-SPINE.md#AD-10, #AD-8, #AD-5, #Structural-Seed, #Consistency-Conventions]
- [Source: _bmad-output/planning-artifacts/ux-designs/ux-tripcast-2026-06-28/EXPERIENCE.md#Information-Architecture, #Component-Patterns, #Key-Flows Flow 1; DESIGN.md#Components "Email-capture step"]
- [Source: _bmad-output/specs/spec-tripcast/SPEC.md#FR-2]

## Dev Agent Record

### Agent Model Used

### Debug Log References

### Completion Notes List

### File List
