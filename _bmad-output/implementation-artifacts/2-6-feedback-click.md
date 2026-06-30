---
baseline_commit: d79abc2ac1f16cb1f587ede26659dfffae256e7f
---

# Story 2.6: Feedback Click

Status: review

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

As a traveler,
I want a one-tap "this helped / not helpful" in the footer,
so that I can react without a login or a survey.

## Acceptance Criteria

**AC1 — Signed feedback chips, confirm-then-POST, no login** *(FR-8, AD-6)*
- **Given** signed footer chips scoped to the Trip + send date
- **When** I tap 👍/👎 (a **GET → confirm → POST**, no login)
- **Then** the GET **only renders a confirmation page** (mutates nothing — prefetch/scanner-safe); the Feedback Click is recorded on the **POST** from that page. A tampered/unsigned link is **rejected (403)**.

**AC2 — Recorded against Trip + send date, last-reaction-wins** *(FR-8, AD-9)*
- **Given** a `feedback` table with `unique(trip_id, send_date)`
- **When** the POST runs
- **Then** a Feedback Click is recorded tied to that **Trip + send_date** with the chosen reaction (`helped` / `not_helpful`); a re-tap (same or other reaction) is **idempotent — last-reaction-wins** (the one row is upserted, never duplicated).

**AC3 — Calm confirmation copy** *(UX-DR11)*
- **And** the result collapses to the calm **"Thanks — noted."** — never a survey, never a login prompt. The chips carry **visible text labels** (not emoji-only) and the plain-text twin carries the literal tappable feedback URLs.

## Tasks / Subtasks

- [x] **Task 1 — `feedback` table + `Feedback` model + factory** (AC: 2)
  - [x] `database/migrations/…_create_feedback_table.php` (mirror `…_create_email_logs_table`): `id`; `foreignId('trip_id')->constrained()->cascadeOnDelete()`; `date('send_date')`; `string('reaction')` (`helped|not_helpful`); `timestamps()`; **`unique(['trip_id','send_date'])`** — the last-reaction-wins upsert key (AD-9). (Trips use soft-delete, so feedback is **not** cascaded away by a user delete — AD-9 keeps the metric trail; the cascade only fires on a true hard delete, exactly like `email_logs`.)
  - [x] `app/Models/Feedback.php`: **`protected $table = 'feedback'`** (Eloquent would otherwise guess `feedbacks`). Constants `REACTION_HELPED = 'helped'`, `REACTION_NOT_HELPFUL = 'not_helpful'`; `$fillable = ['trip_id','send_date','reaction']`; cast `send_date` → `date`; `belongsTo(Trip::class)`. Add the inverse `Trip::feedback(): HasMany` (mirror `Trip::emailLogs()`).
  - [~] `database/factories/FeedbackFactory.php` (+ `HasFactory` on the model): default a random reaction tied to a `Trip` factory; a state per reaction is optional. **Intentionally skipped** — the sibling `EmailLog` has no factory and there is no `TripFactory` for one to depend on; feedback rows are created via `$trip->feedback()->create(...)` in tests, matching the established convention (see Completion Notes).
- [x] **Task 2 — Signed routes + controller (confirm-then-POST)** (AC: 1, 2, 3)
  - [x] `routes/web.php`: inside the existing `['signed','throttle:20,1']` email group, add (route-model bind `{trip}`, constrain `{reaction}`):
    - `GET email/trip/{trip}/feedback/{reaction}` → `confirmFeedback` (name `email.trip.feedback`) → renders the confirm page.
    - `POST email/trip/{trip}/feedback/{reaction}` → `feedback` (name `email.trip.feedback.post`) → records.
    - Constrain reaction to the two values: `->whereIn('reaction', ['helped','not_helpful'])` on both.
  - [x] Extend `app/Http/Controllers/EmailAction.php` (cohesive with end-trip/unsubscribe — same signed-confirm-then-POST pattern + the `placeShort` helper): `confirmFeedback(Request, Trip, string $reaction)` → `Inertia::render('email/FeedbackConfirm', ['reaction' => $reaction, 'reactionLabel' => …, 'place' => placeShort, 'postUrl' => $request->fullUrl()])`. `feedback(Request, Trip, string $reaction)` → upsert then render `email/FeedbackResult`.
  - [x] **The upsert** (AD-9, last-reaction-wins): `Feedback::updateOrCreate(['trip_id' => $trip->id, 'send_date' => $sendDate], ['reaction' => $reaction])` where `$sendDate` comes from the **signed `send_date` query param** (validate `Y-m-d`; the signature covers it so it can't be tampered). Idempotent by construction.
- [x] **Task 3 — Inertia confirm + result pages** (AC: 1, 3)
  - [x] `resources/js/pages/email/FeedbackConfirm.vue` (mirror `EndTripConfirm.vue`): a `useForm({}).post(props.postUrl)` button; calm copy naming the reaction (e.g. "Mark today's forecast as helpful?" / "…as not helpful?"). Single root element; routed through `AuthLayout` (already covers `email/`).
  - [x] `resources/js/pages/email/FeedbackResult.vue` (mirror `UnsubscribeResult.vue`): the locked **"Thanks — noted."** confirmation.
- [x] **Task 4 — DigestMail: signed feedback URLs** (AC: 1, 2)
  - [x] `app/Mail/DigestMail.php`: expose the raw `send_date` (store the constructor `$sendDate` string alongside the existing `$today`) and build **permanent** signed feedback URLs for both reactions — `URL::signedRoute('email.trip.feedback', ['trip' => $this->trip->id, 'reaction' => 'helped', 'send_date' => $this->sendDate])` and the `not_helpful` twin — passing `helpedUrl` / `notHelpfulUrl` into the view `with(...)`. (The `send_date` extra param lands in the query string and is covered by the signature.)
- [x] **Task 5 — Digest templates: feedback chips (HTML + text twin)** (AC: 1, 3)
  - [x] `resources/views/emails/digest.blade.php`: add the **feedback line above** the End-trip/Unsubscribe line (it's the Story 2.6 seam left in the footer). Two chips with **visible text labels** — "👍 This helped" and "👎 Not helpful" — as `<a>` links to the signed URLs, **≥44px tap targets** (generous padding), AA contrast, legible with images blocked (the emoji is decorative; the text label carries the meaning).
  - [x] `resources/views/emails/digest-text.blade.php`: add the two literal feedback URLs (labeled) so the text twin stays content-complete.
- [x] **Task 6 — Tests** (AC: 1, 2, 3)
  - [x] Signed GET renders the confirm page and writes **no** `feedback` row; an unsigned GET/POST → **403**.
  - [x] POST records one `feedback` row keyed `(trip_id, send_date)` with the chosen reaction; the result page shows "Thanks — noted.".
  - [x] Re-tap is **last-reaction-wins**: POST `helped` then `not_helpful` for the same `(trip, send_date)` → **one** row, `reaction = not_helpful` (assert `Feedback::count() === 1`). Re-POST the same reaction → still one row.
  - [x] The `unique(trip_id, send_date)` index is enforced at the DB (a second raw insert throws).
  - [x] `DigestMail`: the HTML footer shows both chips with text labels linking to signed `.../feedback/helped?...&send_date=…&signature=…` and `.../feedback/not_helpful?...`; the text twin contains both literal URLs.
  - [x] Gates: `./vendor/bin/pest`, `./vendor/bin/pint`, `./vendor/bin/phpstan analyse`, `npm run types:check`, `npm run lint:check`, `npm run build:ssr`.

## Dev Notes

### Scope boundary (read first)
- This is the **last footer seam**: after 2.6 the digest footer carries Feedback (this story) + End-trip + Unsubscribe (Story 2.5) + the postal address. The **Promo unit / affiliate-click attribution** (`promo_events`, FR-18) is **Epic 5** — a separate slot, not built here. There is **no dashboard surfacing** of feedback in v1 (it is a metric/audit signal, SM-x); do not build a feedback report. [Source: epics.md#Story-2.6, #Epic-5; ARCHITECTURE-SPINE.md#AD-18]

### Architecture (binding)
- **AD-6 — feedback is a signed, confirm-then-POST email action:** the chips are Laravel **signed URLs** scoped to the trip id (+ `send_date`, + `reaction`); the **GET only renders a confirmation page**, the write happens on the **POST** — because mail clients prefetch/link-scan GETs and would otherwise auto-fire. "Feedback writes are upserts under unique keys so a re-click is idempotent." (Feedback is **not** the promo redirect — that one, FR-18, is the lone signed GET; feedback is confirm-then-POST like end-trip.) [Source: ARCHITECTURE-SPINE.md#AD-6]
- **AD-9 — one row per (trip_id, send_date), upserted:** `feedback` is `unique(trip_id, send_date)` upserted **last-reaction-wins**; it joins `email_logs` on `(trip_id, send_date)` and **survives** the forecast-retention purge and a trip soft-delete (the audit/metric trail). [Source: ARCHITECTURE-SPINE.md#AD-9, #Consistency-Conventions "Idempotency keys", #ERD (FEEDBACK)]
- **Naming/structure:** singular Eloquent model `Feedback`, snake_case table **`feedback`** (set `$table` explicitly), columns `trip_id`, `send_date`, `reaction`. Controller seam is `Http/Controllers/EmailAction` (FR-8 row). [Source: ARCHITECTURE-SPINE.md#Consistency-Conventions "Naming", #Structural-Seed lines 299/311/323, #ERD line 238 `reaction "helped|not_helpful"`]

### UX (binding)
- **UX-DR11 — feedback chips:** digest footer, one-tap **👍 "This helped" / 👎 "Not helpful"** — a **visible text label is mandatory (not emoji-only)**, **≥44px tap targets** in email, **no login**. A single low-friction line, **not a survey**. Tap → feedback landing **confirms quietly**. [Source: EXPERIENCE.md Component Patterns "Feedback chips"; DESIGN.md#Components "Digest email" footer ("feedback line 👍/👎 with text labels")]
- **Locked copy:** result is **"Thanks — noted."** (the documented calm confirmation; never "A multi-step survey", never a code/jargon line). Keep the chip labels exactly "This helped" / "Not helpful". [Source: EXPERIENCE.md Voice and Tone — Written strings ("Thanks — noted." after feedback)]
- **Confirm/result pages** route through `AuthLayout` like the 2.5 email pages (calm centered card, single root element). [Source: resources/js/pages/email/*, resources/js/app.ts layout resolver]

### Code intel (exact patterns to reuse — established in Story 2.5)
- **Signed email-action group:** `routes/web.php` already has `Route::middleware(['signed','throttle:20,1'])->group(...)` with the end-trip/unsubscribe routes — **add the two feedback routes inside it**. The `signed` middleware 403s a bad signature; the in-body POST reuses the GET's signature via `request()->fullUrl()` passed as `postUrl` (a Laravel signature validates the same path+query under both GET and POST).
- **Controller pattern:** `app/Http/Controllers/EmailAction.php` — GET handler `Inertia::render('email/…Confirm', [... 'postUrl' => $request->fullUrl()])` (mutates nothing); POST handler does the write then renders a `…Result` page; `placeShort(Trip)` helper already present.
- **Mailable URL + view plumbing:** `app/Mail/DigestMail.php` — `content()->with([...])` already passes `endTripUrl`/`unsubscribeUrl` via `URL::signedRoute(...)`; add `helpedUrl`/`notHelpfulUrl` the same way. **The constructor currently keeps only `$this->today` (a CarbonImmutable) — also store the raw `$sendDate` string** for the feedback URL's `send_date` param. `headers()` (List-Unsubscribe) is unaffected.
- **Footer templates:** `resources/views/emails/digest.blade.php` footer table (End-trip · Unsubscribe links + postal address from Story 2.5) — add the feedback chip line **above** it. `resources/views/emails/digest-text.blade.php` already lists End-trip/Unsubscribe URLs — add the feedback URLs.
- **Vue pages:** `resources/js/pages/email/EndTripConfirm.vue` (confirm: `useForm({}).post(props.postUrl)` + `Button`) and `UnsubscribeResult.vue` (calm message) are the exact templates to copy for `FeedbackConfirm.vue` / `FeedbackResult.vue`.
- **Migration pattern:** `database/migrations/2026_06_29_000003_create_email_logs_table.php` — `foreignId('trip_id')->constrained()->cascadeOnDelete()`, `date('send_date')`, `$table->unique(['trip_id','send_date'])`.
- **Tests:** `tests/Feature/Email/EmailActionTest.php` is the model — `URL::signedRoute(...)` to forge valid links, a raw unsigned path to assert 403, Inertia `assertInertia(fn (Assert $page) => $page->component('email/…'))`, and DB assertions. `tests/Feature/Digest/DigestMailTest.php` shows footer-link + `assertSeeInHtml/Text` assertions.

### Concrete signed-URL / send_date plumbing
- Build: `URL::signedRoute('email.trip.feedback', ['trip' => $trip->id, 'reaction' => 'helped', 'send_date' => $sendDate])`. `trip` + `reaction` are path segments; `send_date` is a query param — **all** covered by the signature, so the recorded `(trip, send_date)` can't be forged.
- Read back in the POST: `$sendDate = $request->query('send_date')`; validate it's a `Y-m-d` string before the upsert (a malformed value should 404/422, not write garbage). The `send_date` is the digest's send-clock date (the same value `email_logs.send_date` carries).

### Project Structure Notes
- **New:** `database/migrations/…_create_feedback_table.php`, `app/Models/Feedback.php`, `database/factories/FeedbackFactory.php`, `resources/js/pages/email/FeedbackConfirm.vue`, `resources/js/pages/email/FeedbackResult.vue`, `tests/Feature/Email/FeedbackTest.php`. **Modified:** `app/Http/Controllers/EmailAction.php` (feedback methods), `routes/web.php` (2 routes in the signed group), `app/Mail/DigestMail.php` (feedback URLs + store `$sendDate`), `app/Models/Trip.php` (`feedback()` relation), `resources/views/emails/digest.blade.php` + `digest-text.blade.php` (chips), and the Wayfinder-generated route files (regenerate). No config changes. [Source: ARCHITECTURE-SPINE.md#Structural-Seed]

### Previous story intelligence (Stories 2.4–2.5)
- **Story 2.5** built the entire signed confirm-then-POST machinery this story extends: `EmailAction`, the `['signed','throttle:20,1']` route group, the `email/` Inertia pages routed through `AuthLayout`, and the digest footer. **Reuse all of it** — add feedback as a third action, do not invent a parallel mechanism. The signature-carry-through-`postUrl` approach is proven. [Source: 2-5 EmailAction + routes + email pages]
- **Story 2.4** built `DigestMail` (+ `$sendDate` constructor param, already present) and the digest templates; the footer seam for feedback was explicitly left. [Source: 2-4 DigestMail + digest templates]
- Quality lessons: run **PHPStan** (typed model/props); the `Feedback` model **must** set `$table = 'feedback'` or Eloquent looks for `feedbacks`; pin nothing clock-dependent here except via the passed `send_date`; keep chips legible with images blocked (text labels, not emoji-only).

### Testing standards
- Pest, MySQL `tripcast_test`, `RefreshDatabase`. Build a confirmed `User` + `Trip` (factories) and forge links with `URL::signedRoute('email.trip.feedback', ['trip' => $trip->id, 'reaction' => 'helped', 'send_date' => '2026-06-29'])`. Assert: GET writes nothing; POST creates exactly one row; a second POST with the other reaction updates the same row (`Feedback::count() === 1`, `reaction` flipped); unsigned → 403. Mailable: `new DigestMail($trip, $snapshot, '2026-06-29')` then `assertSeeInHtml`/`assertSeeInText` for both chip URLs + labels. [Source: tests/Feature/Email/EmailActionTest.php, tests/Feature/Digest/DigestMailTest.php]

### References
- [Source: _bmad-output/planning-artifacts/epics.md#Story-2.6]
- [Source: _bmad-output/planning-artifacts/architecture/architecture-tripcast-2026-06-28/ARCHITECTURE-SPINE.md#AD-6, #AD-9, #Consistency-Conventions, #Structural-Seed, #ERD]
- [Source: _bmad-output/planning-artifacts/ux-designs/ux-tripcast-2026-06-28/EXPERIENCE.md#Component-Patterns ("Feedback chips"), #Voice-and-Tone; DESIGN.md#Components ("Digest email")]
- [Source: _bmad-output/specs/spec-tripcast/SPEC.md#FR-8]

## Dev Agent Record

### Agent Model Used

claude-opus-4-8 (1M context)

### Debug Log References

- None — clean run. The feedback machinery slots onto Story 2.5's signed-confirm-then-POST infrastructure with no surprises.

### Completion Notes List

- **Task 1 — `feedback` store:** migration mirrors `email_logs` (`foreignId('trip_id')->constrained()->cascadeOnDelete()`, `date('send_date')`, `unique(['trip_id','send_date'])`); `Feedback` model sets `$table = 'feedback'` (avoids the `feedbacks` pluralization), reaction constants, `send_date` date cast; added `Trip::feedback()` HasMany. **`FeedbackFactory` intentionally omitted** — its sibling `EmailLog` has no factory and there is no `TripFactory` for it to associate with; tests build rows via `$trip->feedback()->create(...)`, the established convention. No `HasFactory` added (consistent with `EmailLog`).
- **Task 2 — Routes + controller:** two routes added inside Story 2.5's `['signed','throttle:20,1']` email group, `{reaction}` constrained to `helped|not_helpful` (an out-of-range reaction 404s before the controller). `EmailAction::confirmFeedback` renders the confirm page (mutates nothing); `feedback` validates the signed `send_date` (`date_format:Y-m-d`) then `Feedback::updateOrCreate([trip_id, send_date], [reaction])` — last-reaction-wins, idempotent by the unique index.
- **Task 3 — Pages:** `email/FeedbackConfirm.vue` (confirm → `useForm({}).post(postUrl)`) and `email/FeedbackResult.vue` (the locked "Thanks — noted."), routed through `AuthLayout` via the existing `email/` case.
- **Task 4 — DigestMail:** stored the raw `$sendDate` string; `feedbackUrl()` builds permanent `URL::signedRoute` chips for both reactions (with the `send_date` query param under the signature); passed `helpedUrl`/`notHelpfulUrl` to the views.
- **Task 5 — Templates:** HTML footer now leads with two bordered chips — "👍 This helped" / "👎 Not helpful" (text labels carry the meaning; ~44px tap targets via padding; legible images-blocked) — above the End-trip/Unsubscribe line; the text twin lists both literal feedback URLs. This closes the last footer seam.
- **Verification:** full suite **127 passed** (6 new in `FeedbackTest` + 1 new `DigestMailTest` case): signed GET writes nothing, unsigned → 403, POST records one row, re-tap is last-reaction-wins (single row, reaction flipped), unknown reaction → 404, DB unique enforced, and the digest renders both signed chips in HTML + text. `pint` clean, `phpstan` 0 errors, `types:check` / `lint:check` / `build:ssr` green. `route:list --path=feedback` confirms both routes.

### File List

**New:**
- `database/migrations/2026_06_29_000004_create_feedback_table.php`
- `app/Models/Feedback.php`
- `resources/js/pages/email/FeedbackConfirm.vue`
- `resources/js/pages/email/FeedbackResult.vue`
- `tests/Feature/Email/FeedbackTest.php`

**Modified:**
- `app/Models/Trip.php` (`feedback()` HasMany)
- `app/Http/Controllers/EmailAction.php` (`confirmFeedback` + `feedback` upsert)
- `app/Mail/DigestMail.php` (raw `sendDate` + signed feedback chip URLs)
- `routes/web.php` (two signed feedback routes)
- `resources/views/emails/digest.blade.php` + `resources/views/emails/digest-text.blade.php` (feedback chips)
- `tests/Feature/Digest/DigestMailTest.php` (feedback chip assertions)
- Wayfinder-generated `resources/js/actions/*`, `resources/js/routes/*` (route sync)

### Change Log

- 2026-06-29 — Implemented Story 2.6: one-tap signed feedback chips (confirm-then-POST, no login), the `feedback` table + model (one row per trip+send_date, upserted last-reaction-wins), the calm "Thanks — noted." result, and the digest footer chips. Closes the final footer seam. All gates green.
