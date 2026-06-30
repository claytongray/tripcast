---
baseline_commit: d79abc2ac1f16cb1f587ede26659dfffae256e7f
---

# Story 2.5: Login-free end-trip / unsubscribe footer links

Status: review

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

As a traveler,
I want to end a trip or unsubscribe straight from the email,
so that stopping is one tap and never needs a login.

## Acceptance Criteria

**AC1 — Signed link, confirm-then-POST (prefetch-safe)** *(FR-5, AD-6)*
- **Given** a signed, trip-scoped footer link
- **When** I click it (a GET)
- **Then** it **only renders a confirmation page** — no state changes; the state change happens on a **POST** from that page (so mail-scanner/prefetch GETs from Gmail/Outlook/Apple cannot auto-fire the action). A tampered or unsigned link is **rejected (403)**.

**AC2 — End this trip → completed via the one transition method** *(FR-5, AD-5)*
- **Given** I confirm "End this trip"
- **When** the POST runs
- **Then** that **one** Trip transitions to `completed` through the **single Trip state-transition method** (no controller writes `status` directly), it stops receiving digests (the cadence predicate excludes it), and `completed` is **terminal**. A re-POST is idempotent (already-completed stays completed, calm confirmation either way).

**AC3 — Unsubscribe is account-level suppression** *(FR-5, AD-13, UX-DR17)*
- **Given** I confirm "Unsubscribe"
- **When** the POST runs
- **Then** `users.email_opted_out` is set, excluding **all** of my Trips from the cadence predicate (AD-11) — not just the one in the link — and the change is **idempotent** (re-submitting stays opted-out). My other trips' status is untouched (unsubscribe ≠ end-trip).

**AC4 — One-click List-Unsubscribe + in-body footer links, image-safe & text-complete** *(UX-DR17, NFR-2; the deliverability seam left open by Story 2.4)*
- **And** every digest send carries a `List-Unsubscribe` header (both a `mailto:` **and** an HTTPS target) **and** `List-Unsubscribe-Post: List-Unsubscribe=One-Click`, so Gmail/Apple render native unsubscribe; the HTTPS one-click target is a **signed POST that is CSRF-exempt and idempotent** (no human, no confirmation page) and honors the opt-out immediately. The HTML footer carries plain-text **"End this trip"** and **"Unsubscribe"** links (legible with images blocked), and the **plain-text twin carries the literal tappable URLs**.

## Tasks / Subtasks

- [x] **Task 1 — The single Trip state-transition method (AD-5)** (AC: 2)
  - [x] `app/Models/Trip.php`: add the **one** state-transition surface AD-5 mandates — no controller/job writes `status` directly. Implement a guarded transition where `completed` is **terminal** (no transition leaves it), `active ⇄ paused` is allowed (dashboard, Epic 3, will reuse it), and `→ completed` is allowed from `active`/`paused`. Story 2.5 uses only the **`→ completed`** path (e.g. a `complete(): void` that no-ops if already `completed`, else sets `status = completed`). Keep it a thin domain method on the model (mirror the project's action/model conventions); throw on an illegal transition (e.g. completed → active).
  - [x] Do **not** build the dashboard pause/resume UI or the `CompleteExpiredTrips` sweep here — they are Epic 3 / their own stories; only the transition method + the end-trip caller belong to 2.5.
- [x] **Task 2 — Signed routes + `EmailAction` controller (confirm-then-POST)** (AC: 1, 2, 3, 4)
  - [x] `routes/web.php`: add **public, unauthenticated** routes (no `auth` middleware). Use Laravel **signed URLs** (AD-6 — *not* the `login_tokens` table, which is login-only):
    - `GET email/trip/{trip}/end` → `EmailAction@confirmEnd` (name `email.trip.end`), `middleware('signed')` → renders the confirm page.
    - `POST email/trip/{trip}/end` → `EmailAction@end` (name `email.trip.end.post`), `middleware('signed')` (CSRF via Inertia) → transitions the trip.
    - `GET email/user/{user}/unsubscribe` → `EmailAction@confirmUnsubscribe` (name `email.unsubscribe`), `middleware('signed')` → renders the confirm page.
    - `POST email/user/{user}/unsubscribe` → `EmailAction@unsubscribe` (name `email.unsubscribe.post`), `middleware('signed')` (CSRF via Inertia).
    - `POST email/user/{user}/unsubscribe/one-click` → `EmailAction@unsubscribeOneClick` (name `email.unsubscribe.one_click`), `middleware('signed')` **and CSRF-exempt** (the `List-Unsubscribe-Post` target hit directly by the mail client — no session, no CSRF token).
  - [x] `app/Http/Controllers/EmailAction.php` (new; mirror `Auth/MagicLinkController` GET-confirm / POST-act split, `LandingController` Inertia style): GET handlers `Inertia::render` a calm confirmation page with the bound trip/user (and the props the page needs to POST back). POST handlers run the change via the Trip transition method (end) or set `email_opted_out = true` (unsubscribe) and render a calm **result** page. All idempotent (re-POST → same calm confirmation).
  - [x] CSRF: the in-body POSTs come from the Inertia confirm page (CSRF token present). The **one-click** POST must be excluded from CSRF verification (e.g. `bootstrap/app.php` `$middleware->validateCsrfTokens(except: ['email/*/unsubscribe/one-click'])`) — keep the exclusion **narrow** to that path only.
  - [x] Throttle the public action routes (mirror the magic-link `middleware('throttle:N,1')`) as defense-in-depth — signed URLs prevent forgery, the throttle bounds abuse/replay volume.
- [x] **Task 3 — Inertia confirmation + result pages** (AC: 1, 2, 3)
  - [x] New Vue pages under `resources/js/pages/email/` mirroring `auth/MagicLinkConfirm.vue` (a `useForm({}).post(...)` confirm) and `auth/MagicLinkResult.vue` (calm message). Suggested: `EndTripConfirm.vue` + `EndTripResult.vue`, `UnsubscribeConfirm.vue` + `UnsubscribeResult.vue` (or one confirm/result pair parameterized by action). Single root element; calm copy (see locked copy below).
  - [x] Wire the POST with **Wayfinder** typed routes (import from `@/routes`/`@/actions`, run `php artisan wayfinder:generate` if needed) — do not hardcode URLs. The signed query string must be carried through to the POST: pass the signed POST URL (with signature) from the controller to the page as a prop and post to it, OR sign the POST route and carry its `signature` — confirm the signed middleware passes on the POST.
  - [x] Confirm pages are reachable while **logged out**; no `auth` middleware.
- [x] **Task 4 — DigestMail: signed footer URLs + List-Unsubscribe headers** (AC: 4)
  - [x] `app/Mail/DigestMail.php`: build **permanent** signed URLs (`URL::signedRoute(...)`, scoped to `trip->id` / `user->id`) for the in-body **End this trip** and **Unsubscribe** links and the HTTPS **one-click** unsubscribe target; pass them into the view `with(...)`. Add the headers via the `Envelope(headers: new Headers(text: [...]))`:
    - `List-Unsubscribe: <https://…one-click>, <mailto:UNSUB_MAILTO?subject=unsubscribe>`
    - `List-Unsubscribe-Post: List-Unsubscribe=One-Click`
  - [x] `mailto` target from config (e.g. `config('tripcast.unsubscribe_mailto')`, default to the `MAIL_FROM_ADDRESS`); add the config key. Signed routes need a correct `APP_URL`.
  - [x] Note: once `email_opted_out` is set, the cadence predicate (AD-11) excludes every trip, so **no further digest is sent** — that is what "suppressing the List-Unsubscribe target" means in practice; the one-click endpoint also stays idempotent on re-hit (AC3).
- [x] **Task 5 — Digest templates: footer links (HTML + content-complete text twin)** (AC: 4)
  - [x] `resources/views/emails/digest.blade.php`: in the footer seam (currently the `@if ($postalAddress)` block), add the **"End this trip"** and **"Unsubscribe"** anchors in `meta`/`ink-secondary` (≥13–14px, AA contrast on the rendered/inverted background — never tiny grey). Keep the **Feedback line a seam for Story 2.6** (do not build feedback here). No image-only meaning.
  - [x] `resources/views/emails/digest-text.blade.php`: append the **literal** End-trip and Unsubscribe URLs (tappable plain URLs) so the text part stays a content-complete mirror (UX-DR17 / deliverability).
- [x] **Task 6 — Tests** (AC: 1, 2, 3, 4)
  - [x] Signed GET renders the confirm page and **mutates nothing** (trip still `active`, `email_opted_out` still false); an **unsigned / tampered** GET and POST are **403**.
  - [x] End-trip POST transitions the trip to `completed` via the transition method, the trip **leaves cadence** (assert `CadencePredicate::isDue` false after), other trips of the same user are untouched, and a **second** POST is idempotent (stays `completed`). Illegal transition (completed → active) throws.
  - [x] Unsubscribe POST sets `email_opted_out`, **all** the user's trips drop out of `CadencePredicate::dueOn`/`isDue`, trip statuses are unchanged, and a re-POST stays opted-out.
  - [x] One-click `List-Unsubscribe-Post` endpoint: a **signed POST with no CSRF token** succeeds and sets `email_opted_out` (idempotent); unsigned → 403.
  - [x] `DigestMail`: the rendered message carries `List-Unsubscribe` (with both `https` one-click and `mailto:`) and `List-Unsubscribe-Post: List-Unsubscribe=One-Click`; the HTML footer shows "End this trip" + "Unsubscribe" as text links; the plain-text twin contains the literal URLs. (Use a signed-route assertion or `Str::contains` on the header value.)
  - [x] Gates: `./vendor/bin/pest`, `./vendor/bin/pint`, `./vendor/bin/phpstan analyse`, `npm run types:check`, `npm run lint:check`, `npm run build:ssr`.

## Dev Notes

### Scope boundary (read first)
- This story is **the end-trip + unsubscribe footer links + the List-Unsubscribe one-click deliverability headers** — the seam Story 2.4 deliberately left in the digest footer. The **Feedback click** (the 👍/👎 footer line and its signed route) is **Story 2.6** — leave it a clearly-marked seam, do not build it. The **dashboard** pause/resume/delete UI and the **`CompleteExpiredTrips`** daily sweep are **Epic 3 / AD-5's sweep story** — this story adds only the Trip transition *method* and the end-trip caller. **Promo** (Epic 5) and **AI narration** (4.2) slots remain untouched placeholders. [Source: epics.md#Story-2.6, #Story-2.4; ARCHITECTURE-SPINE.md#AD-5]

### Architecture (binding)
- **AD-6 — email actions are signed, confirm-then-POST:** email **action** links (end-trip, unsubscribe) are **Laravel signed URLs scoped to the id** — *not* the single-use `login_tokens` table (that is login-only). A signed **GET only renders a confirmation page**; the state change happens on a **CSRF-protected POST** from that page, because mail clients (Gmail/Outlook/Apple) prefetch and link-scan GETs and would otherwise auto-fire. The `List-Unsubscribe-Post` one-click path is the exception that *is* a direct POST (from the mail client) — it must be **idempotent and scanner-safe** and is therefore **CSRF-exempt + signed**. [Source: ARCHITECTURE-SPINE.md#AD-6]
- **AD-5 — one owner for Trip status:** every surface that changes `Trip.status` (dashboard, **email end-trip link**, daily job, admin) goes through a **single state-transition method on `Trip`**. `status` defaults to `active`; `active ⇄ paused` by user; `→ completed` by system or end-trip link; **`completed` is terminal**. **No controller/job writes `status` directly.** (Completion of *expired* trips is a separate daily `CompleteExpiredTrips` sweep — not this story.) [Source: ARCHITECTURE-SPINE.md#AD-5]
- **AD-13 — unsubscribe is account-level, not trip-scoped:** "End this trip" completes one Trip (AD-5); the footer **Unsubscribe** sets the account-level `users.email_opted_out` flag that the cadence predicate (AD-11) excludes for **all** of that user's trips and which suppresses the `List-Unsubscribe` target. Prevents a multi-trip user unsubscribing once yet still receiving mail for other trips (a CAN-SPAM / one-click violation that wrecks deliverability). The Welcome Email and digests both honor it (they already do — see code intel). [Source: ARCHITECTURE-SPINE.md#AD-13]
- **AD-11 — cadence authority:** due ⟺ `status == active` AND `deleted_at` null AND owner confirmed AND **owner not opted out** AND in window. So both end-trip (status) and unsubscribe (opt-out) take effect purely by flipping state this predicate already reads — **no second exclusion list**. [Source: ARCHITECTURE-SPINE.md#AD-11; app/Digest/CadencePredicate.php]

### UX (binding)
- **Inbox invariants (UX-DR17 / NFR-2):** every send carries `List-Unsubscribe` (mailto + HTTPS) **and** `List-Unsubscribe-Post: List-Unsubscribe=One-Click` so Gmail/Apple render native unsubscribe; honor it immediately. **Scanner-safe links** — no bare GET performs a state change; end-trip resolves through a confirmation landing / POST-on-confirm, and the one-click path is idempotent. **Plain-text completeness** — the text part includes literal tappable URLs for end-trip (and feedback, in 2.6). **Type minimums** — footer/unsubscribe line ≥ 13–14px, never below AA on the *rendered* (possibly inverted) background (tiny grey unsubscribe text reads as a dark pattern to filters). [Source: EXPERIENCE.md Email Delivery & Inbox Invariants]
- **Locked copy (calm "watching" motif; never the over-clever anti-examples):**
  - End-trip link label: **"End this trip"**. End-trip confirmation result: **"Your trip is wrapped. We've stopped watching — safe travels."**
  - Unsubscribe link label: **"Unsubscribe"**. Unsubscribe confirmation result: keep the same calm voice (e.g. **"You're unsubscribed — we've stopped emailing you."**). **Never** "You have been unsubscribed (code 200)." (the documented anti-example). The confirm-page action button extends the "watching" motif (e.g. "End this trip" / "Keep watching"). [Source: EXPERIENCE.md Voice and Tone — Written strings, "Trip ended via email link"; DESIGN.md#Components "Digest email" footer]
- **Confirm/result pages:** calm `surface-raised` card pages, mirror the existing `auth/MagicLinkConfirm.vue` (a single `useForm({}).post()` button) and `auth/MagicLinkResult.vue` (quiet message). Single root element per Vue component. [Source: DESIGN.md#Components; resources/js/pages/auth/*]

### Code intel (exact patterns to reuse — from a codebase sweep)
- **Trip status:** `app/Models/Trip.php` — constants `STATUS_ACTIVE/PAUSED/COMPLETED` (lines ~34–38), `use SoftDeletes`. **No transition method exists yet — you create it (Task 1).** Migration `database/migrations/2026_06_29_000002_create_trips_table.php` (`status` default `active`, soft deletes).
- **Confirm-then-POST precedent:** `app/Http/Controllers/Auth/MagicLinkController.php` — `confirm()` GET renders `Inertia::render('auth/MagicLinkConfirm', …)` without mutating; `consume()` POST does the atomic change then renders/redirects. Routes split GET/POST in `routes/auth.php:18-19`. **Reuse the shape, but use signed URLs (not `login_tokens`) for actions.**
- **Inertia controller style:** `app/Http/Controllers/LandingController.php` — GET → `Inertia::render`, POST → action/redirect. Public routes in `routes/web.php` have no `auth`; `auth`-guarded routes are wrapped in `middleware('auth')`; throttles via `middleware('throttle:N,1')`.
- **Opt-out reads (already wired — just flip the flag):** `app/Digest/CadencePredicate.php:32` (`$user->email_opted_out`) and `:62` (`->where('email_opted_out', false)`); `app/Actions/SendWelcomeEmail.php:18` (welcome honors it). `User` model: `email_opted_out` is fillable + cast bool (`app/Models/User.php`).
- **Mail header pattern:** `app/Mail/DigestMail.php` `envelope()` returns `new Envelope(subject: …)`. Add `headers: new Headers(text: ['List-Unsubscribe' => …, 'List-Unsubscribe-Post' => …])` (`Illuminate\Mail\Mailables\Headers`). The view-data plumbing (`content()->with([...])`) and the `dayRows()` projection are already there — add the signed URLs alongside.
- **Footer seam:** `resources/views/emails/digest.blade.php:74-82` — the `{{-- Footer … Story 2.5/2.6 — seam --}}` comment + `@if ($postalAddress)` block; insert the links there, before the closing `</td>`. Text twin: `resources/views/emails/digest-text.blade.php`.
- **Tests:** `tests/Feature/Auth/MagicLinkTest.php` shows the GET-does-not-mutate then POST-mutates pattern and route() helpers; `tests/Feature/Mail/WelcomeMailTest.php` and `tests/Feature/Digest/DigestMailTest.php` show Mailable render assertions (`assertSeeInHtml`, `assertSeeInText`, header inspection via `$mail->render()` / building the message). Signed URLs in tests: `URL::signedRoute(...)` to build a valid link; hit an unsigned one to assert 403.

### Signed-URL specifics (concrete)
- Generate with `URL::signedRoute('email.trip.end', ['trip' => $trip->id])` — **permanent** signature (an emailed link should not expire; the action is idempotent + confirm-gated, so no TTL needed). The `signed` middleware validates the signature and returns 403 on tamper.
- The confirm page must be able to POST to a still-signed URL. Simplest: the controller passes the **signed POST URL** (string, including `?signature=…`) to the Inertia page as a prop; the page posts to that exact URL. Verify the `signed` middleware on the POST route accepts the carried signature. (Alternative: exclude the POST from `signed` and rely on the signed GET + CSRF — but AD-6's intent is the action stays signature-bound; prefer carrying the signature.)
- One-click route uses route-model binding on `{user}` + `signed`; it is CSRF-exempt so the mail client's bare POST works. Bind narrowly (only that path) in the CSRF exception.

### Project Structure Notes
- **New:** `app/Http/Controllers/EmailAction.php`; `resources/js/pages/email/*.vue` (confirm + result); tests under `tests/Feature/Email/`. **Modified:** `app/Models/Trip.php` (transition method), `routes/web.php` (signed routes), `bootstrap/app.php` (narrow CSRF exception for one-click), `app/Mail/DigestMail.php` (signed URLs + headers), `resources/views/emails/digest.blade.php` + `digest-text.blade.php` (footer links), `config/tripcast.php` (`unsubscribe_mailto`). Run `wayfinder:generate` after adding routes. No new DB columns (status + `email_opted_out` already exist). [Source: ARCHITECTURE-SPINE.md#Structural-Seed lines 305-332]

### Previous story intelligence (Stories 2.1–2.4, 1.x)
- **Story 2.4** built `DigestMail` + `digest.blade.php`/`digest-text.blade.php` and **left the exact footer seam** this story fills; it also already plumbs `config('tripcast.postal_address')` into the footer and uses `Envelope(subject: …)`. Reuse its `placeShort`/snapshot plumbing untouched — only add URLs + headers. [Source: 2-4 DigestMail + digest templates]
- **Magic-link auth (Epic 1)** is the canonical confirm-then-POST + Inertia confirm/result precedent; **but action links use signed URLs, not the token table** — do not reach for `LoginToken`. [Source: app/Http/Controllers/Auth/MagicLinkController.php]
- **CadencePredicate (2.2)** already excludes opted-out + non-active; this story just flips the flags it reads — assert behavior **through the predicate**, don't re-implement exclusion. [Source: 2-2 CadencePredicate]
- Quality lessons carried forward: pin the clock for any date-dependent test; run **PHPStan** (typed Mailable props bit us in 2.4 — keep header arrays typed); keep the email legible with images blocked; never let a bare GET mutate.

### Testing standards
- Pest, MySQL `tripcast_test`, `RefreshDatabase`. Build a confirmed `User` + `Trip` via factories (`User::factory()->confirmed()`), and a second trip to prove unsubscribe is account-wide and end-trip is single-trip. Use `URL::signedRoute()` to forge valid links and a hand-built unsigned URL to assert 403. For the one-click POST, `post(route(...))` **without** a CSRF token (Pest feature tests don't send one by default; ensure the exception path is what's exercised). Mailable: assert headers off `$mail` (e.g. build the Symfony message or assert on `$mail->render()` for body links + inspect `Headers`). [Source: tests/Feature/Auth/MagicLinkTest.php, tests/Feature/Digest/DigestMailTest.php]

### References
- [Source: _bmad-output/planning-artifacts/epics.md#Story-2.5] (+ #Story-2.6 for the feedback seam, #Story-2.4 for the footer seam)
- [Source: _bmad-output/planning-artifacts/architecture/architecture-tripcast-2026-06-28/ARCHITECTURE-SPINE.md#AD-5, #AD-6, #AD-11, #AD-13, #Structural-Seed, #Cross-cutting (Auth surfaces)]
- [Source: _bmad-output/planning-artifacts/ux-designs/ux-tripcast-2026-06-28/EXPERIENCE.md#Email-Delivery-&-Inbox-Invariants, #Voice-and-Tone; DESIGN.md#Components ("Digest email" footer)]
- [Source: _bmad-output/specs/spec-tripcast/SPEC.md#FR-5]

## Dev Agent Record

### Agent Model Used

claude-opus-4-8 (1M context)

### Debug Log References

- `Envelope` has no `headers:` parameter in this Laravel version — moved the `List-Unsubscribe` / `List-Unsubscribe-Post` headers to a dedicated `DigestMail::headers(): Headers` method (the supported Mailable hook).
- Story 2.4's `DigestMailTest` built an **unsaved** Trip; 2.5's signed footer URLs need `trip->id` + `trip->user->id`, so its `digestTrip()` helper now persists via the factory with an owner.

### Completion Notes List

- **Task 1 — Trip transition (AD-5):** added the single `transitionTo(string $status)` surface on `Trip` (idempotent same-status, `completed` terminal, unknown target throws) plus a thin `complete()`; new `App\Models\InvalidTripTransitionException` (a `DomainException`). Covered by 7 boundary tests.
- **Task 2 — Signed routes + `EmailAction`:** five public routes under `middleware(['signed','throttle:20,1'])`; the one-click path is additionally CSRF-exempt via a **narrow** `validateCsrfTokens(except: ['email/*/unsubscribe/one-click'])` in `bootstrap/app.php`. Controller mirrors the magic-link GET-confirm / POST-act split; opt-out via a new idempotent `User::optOut()`.
- **Task 3 — Confirm/result pages:** four Inertia Vue pages under `pages/email/`, routed through `AuthLayout` (added an `email/` case to `app.ts`'s layout resolver). **Signature-carry approach** (the task's allowed alternative to Wayfinder typed routes): the controller passes `request()->fullUrl()` (the signed URL) as `postUrl` and the page posts back to it — a Laravel signature validates the same path under both GET and POST, so the in-body POST stays signature-bound. Wayfinder types regenerated for route sync.
- **Task 4 — DigestMail:** `headers()` emits `List-Unsubscribe` (signed HTTPS one-click + `mailto:` from `config('tripcast.unsubscribe_mailto')`) and `List-Unsubscribe-Post: List-Unsubscribe=One-Click`; permanent `URL::signedRoute` end-trip + unsubscribe links passed to the views.
- **Task 5 — Templates:** HTML footer now renders "End this trip · Unsubscribe" text links (≥14px, AA `ink-secondary`, dark-mode aware) above the postal address; the text twin carries the literal URLs. Feedback line left as the Story 2.6 seam.
- **Verification:** full suite **120 passed** (16 new across `TripTransitionTest`, `EmailActionTest`, and the `DigestMailTest` header/footer cases). `pint` clean, `phpstan` 0 errors, `npm run types:check` / `lint:check` / `build:ssr` all green. `php artisan route:list --path=email` confirms all five routes with `signed` + throttle.

### File List

**New:**
- `app/Models/InvalidTripTransitionException.php`
- `app/Http/Controllers/EmailAction.php`
- `resources/js/pages/email/EndTripConfirm.vue`
- `resources/js/pages/email/EndTripResult.vue`
- `resources/js/pages/email/UnsubscribeConfirm.vue`
- `resources/js/pages/email/UnsubscribeResult.vue`
- `tests/Feature/Trip/TripTransitionTest.php`
- `tests/Feature/Email/EmailActionTest.php`

**Modified:**
- `app/Models/Trip.php` (single `transitionTo` + `complete` transition surface, AD-5)
- `app/Models/User.php` (idempotent `optOut()`, AD-13)
- `app/Mail/DigestMail.php` (`headers()` List-Unsubscribe + signed footer URLs)
- `routes/web.php` (signed + throttled email-action routes)
- `bootstrap/app.php` (narrow CSRF exception for the one-click path)
- `config/tripcast.php` (`unsubscribe_mailto`)
- `resources/views/emails/digest.blade.php` + `resources/views/emails/digest-text.blade.php` (footer links)
- `resources/js/app.ts` (route `email/` pages through `AuthLayout`)
- `tests/Feature/Digest/DigestMailTest.php` (persisted trip + header/footer assertions)
- Wayfinder-generated `resources/js/actions/*`, `resources/js/routes/*` (route sync)

### Change Log

- 2026-06-29 — Implemented Story 2.5: login-free signed end-trip + account-level unsubscribe footer links (confirm-then-POST, prefetch-safe) and the RFC 8058 one-click `List-Unsubscribe` headers. Added the single Trip transition method (AD-5), `EmailAction` controller + signed routes, four Inertia confirm/result pages, and the digest footer links. All gates green.
