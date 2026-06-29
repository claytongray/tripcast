---
baseline_commit: 74efb6dbe446a724fd3fe0e078601e225c8e8f7f
---

# Story 1.5: Welcome email

Status: review

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

As a new user,
I want a one-time welcome confirming what Tripcast will do,
so that I know my trip is being watched and when emails begin.

## Acceptance Criteria

**AC1 — One-time welcome on creation, immediate, window-independent, calm** *(FR-9, AD-11, UX-DR7)*
- **Given** a Trip is created (Story 1.4)
- **When** the welcome email sends — **immediately, independent of the Forecast Window**
- **Then** it states the **Destination**, the **dates**, and **when daily digests will begin** (Forecast-Window open), in the calm concierge voice with **no CTA and no celebration**.

**AC2 — Plain-text twin + account-level opt-out honored** *(FR-9, AD-13, UX-DR7)*
- **And** it includes a **content-complete plain-text twin**
- **And** it **honors `users.email_opted_out`** — a suppressed user gets **neither welcome nor digests** (no welcome is queued for an opted-out owner).

## Tasks / Subtasks

- [x] **Task 1 — `WelcomeMail` mailable + templates** (AC: 1, 2)
  - [x] `app/Mail/WelcomeMail.php` (`implements ShouldQueue`): takes the `Trip`; subject **"We're watching {placeShort}"**; `Content` with `view: emails.welcome` + `text: emails.welcome-text`
  - [x] View data: `place`, `placeShort` (before first comma), `dateRange` (same-month → "14–21 July", else "14 July – 2 August"), `firstDigestDate` (window-open floored to today, "j F")
  - [x] `resources/views/emails/welcome.blade.php` — calm HTML (web-safe fonts, `color-scheme` meta, table layout, inline styles, dark-pair `<style>`), **no button/CTA**, locked body
  - [x] `resources/views/emails/welcome-text.blade.php` — content-complete plain-text twin
- [x] **Task 2 — Queue the welcome from `CreateTrip` (honoring opt-out)** (AC: 1, 2)
  - [x] `CreateTrip::handle` queues `WelcomeMail` **after** the `DB::transaction` commits (outside it, AD-10), only if `! $trip->user->email_opted_out` (AD-13)
  - [x] Updated the Story-1.4 controller comment: welcome lives in `CreateTrip` (every creation path gets it); magic link stays in the controller (always sent)
- [x] **Task 3 — Tests** (AC: 1, 2)
  - [x] `CreateTrip` queues `WelcomeMail` to the owner (`assertQueued`/`hasTo`); existing 1.4 tests still green
  - [x] Opted-out owner → `WelcomeMail` **not** queued
  - [x] Failed create → no welcome queued; zero users
  - [x] Render: subject + locked sentence (destination, date range, first-digest date) in HTML + text; **no `<a>`/CTA**; plain-text twin present
  - [x] `firstDigestDate`: pre-window → `departure − 7 days` (7 July); in-window → floored to today (29 June)

## Dev Notes

### Scope boundary (read first)
- This story builds **only** the Welcome email + its trigger. It closes Epic 1. The **Daily Digest** template/cadence (FR-6/FR-7) is **Epic 2** — do not build it. Full deliverability headers (`List-Unsubscribe` one-click, the in-body unsubscribe/end-trip links, physical postal address footer) are **UX-DR17 / Epic 2** concerns (Stories 2.4/2.5) — the welcome stays minimal per UX-DR7 (calm body, plain-text twin, opt-out honored). [Source: epics.md#Epic-2; UX-DR7 vs UX-DR17]

### Architecture (binding)
- **FR-9 / AD-11 — fires once, immediately, at creation, independent of the Forecast Window.** The Capability Map routes FR-9 to **`Actions/CreateTrip → mail`** — so queue the welcome **inside `CreateTrip`**, after the transaction commits. This co-locates it with creation so every add path (landing now; dashboard-add Story 3.2) sends it. [Source: ARCHITECTURE-SPINE.md#Capability-Map FR-9, #AD-11]
- **AD-10 — no external calls inside the transaction.** Queue the mail **after** `DB::transaction(...)` returns (outside it). [Source: ARCHITECTURE-SPINE.md#AD-10]
- **AD-13 — account-level opt-out.** Both Welcome and digests honor `users.email_opted_out`. A brand-new user defaults to `false` (so first signup always gets the welcome); the check matters for an existing opted-out owner adding another trip. The **magic link is transactional auth and still sends** regardless of opt-out. [Source: ARCHITECTURE-SPINE.md#AD-13]
- **Mail driver:** queued (`ShouldQueue`), processed by the worker (`composer run dev` runs `queue:listen`; Forge runs the worker daemon). Local mail driver is `log`. MailerSend is the prod driver (deferred wiring). [Source: 1-1 MagicLinkMail (queued); tripcast dev env]
- **Naming:** `WelcomeMail` in `app/Mail`; Blade emails in `resources/views/emails/`. [Source: ARCHITECTURE-SPINE.md#Structural-Seed]

### When digests begin — the "first forecast" date
- The Forecast Window opens at **`departure_date − 7 days`** (AD-11's due window is `[Departure−7d, Return]`). The welcome's "Your first morning forecast arrives {date}" = that window-open date, **floored to today (America/New_York)** for trips created inside the window: `firstDigestDate = max(departure_date->subDays(7), now('America/New_York')->startOfDay())`. [Source: ARCHITECTURE-SPINE.md#AD-11, #AD-7; Deferred "same-day-before-9am first send edge" — defaults to the next scheduled run]
- This is the window-open **boundary**, a simple derivation — **not** a re-implementation of the AD-11 due predicate (that single predicate is Story 2.2). Keep the computation small and local to the welcome (mailable or a tiny helper); 2.2 owns the authoritative predicate. Note the cross-reference so 2.2 can reconcile. [Source: ARCHITECTURE-SPINE.md#AD-11]
- All date math uses **America/New_York** (AD-7). Trip dates are naive `date` casts (Carbon).

### UX — locked copy (use verbatim, adapt the variables)
- **Body** (UX-DR16): *"We're watching {place}, {dateRange}. Your first morning forecast arrives {firstDigestDate}. Nothing to do until then — we'll be in your inbox."* — **no CTA, no celebration**. [Source: EXPERIENCE.md Voice and Tone "Written strings → Welcome email body"]
- **Subject:** *"We're watching {placeShort}"* (e.g. "We're watching Edinburgh"); **never** put a weather verdict in the subject; no emoji/all-caps/exclamation. [Source: EXPERIENCE.md Voice and Tone "Subject lines & preheaders → Welcome"]
- **Preheader** (optional, calm): *"Your first morning forecast arrives {firstDigestDate}."* [Source: EXPERIENCE.md Subject/preheader table]
- **Tone:** calm concierge, one idea per line, never alarmist. [Source: EXPERIENCE.md Voice and Tone; DESIGN.md Brand & Style]
- **Email visual rules (UX-DR7 + DESIGN email constraints):** web-safe font stack (no web fonts), `surface-base` outer / `surface-raised` card (max 600px), `color-scheme` meta + dark-pair aware, table layout + inline styles, fully legible with images blocked (this email has no images anyway), radius capped at `sm`–`md`. Mirror the structure of the Story 1.1 `magic-link.blade.php` (same dark-mode `<style>` block + table shell), minus the button. [Source: DESIGN.md#Components, #Colors dark-mode rendering; 1-1 emails/magic-link.blade.php]

### Testing standards
- Pest feature tests, MySQL `tripcast_test`, `RefreshDatabase`, `Mail::fake()`. Use the `UserFactory` (`->optedOut()` state exists) and create trips via `CreateTrip` (or the factory + relationship). [Source: 1-1 UserFactory states; 1-4 CreateTrip]
- Mailable rendering: build `new WelcomeMail($trip)` and use `assertSeeInHtml(...)`, `assertSeeInOrderInText([...])`, `assertHasSubject(...)`, `assertDontSeeInHtml('<a ')`/no button. Pin the clock with `Carbon::setTestNow` for deterministic `firstDigestDate`.
- The existing 1.4 tests must stay green (adding the welcome queue alongside the magic link — both `assertQueued`).
- Gates before "done": `./vendor/bin/pest`, `./vendor/bin/pint`, `./vendor/bin/phpstan analyse`, `npm run types:check` (unchanged frontend, but run it), `npm run lint:check`, `npm run build:ssr`.

### Project Structure Notes
- New: `app/Mail/WelcomeMail.php`, `resources/views/emails/welcome.blade.php`, `resources/views/emails/welcome-text.blade.php`, tests. **Modified:** `app/Actions/CreateTrip.php` (queue welcome post-commit, honor opt-out), `app/Http/Controllers/LandingController.php` (update the seam comment). No migrations, no new routes, no frontend pages. [Source: ARCHITECTURE-SPINE.md#Structural-Seed]

### Previous story intelligence (Stories 1.1–1.4)
- **`MagicLinkMail`** is the template to mirror: `implements ShouldQueue`, `envelope()`/`content()` with HTML + `text:` twin, dark-mode `<style>` block, web-safe fonts, inline styles, `color-scheme` meta. Copy that shell for `welcome.blade.php` and drop the button. [Source: 1-1 app/Mail/MagicLinkMail.php, resources/views/emails/magic-link*.blade.php]
- **`CreateTrip::handle(string $email, array $tripDetails): Trip`** returns the Trip (with `->user`). It already lowercases the email and runs a DB-only transaction. Add the welcome queue **after** the transaction returns; do not put mail inside it. The 1.4 controller seam comment marks the intent — relocate the actual send to `CreateTrip`. [Source: 1-4 CreateTrip, LandingController@createTrip]
- **`User`** has `email_opted_out` (bool cast, default false) and the `optedOut()` factory state. Trip casts `departure_date`/`return_date` → `date` (Carbon). [Source: 1-3 User model; 1-4 Trip model; 1-1 UserFactory]
- **Mail is queued** project-wide — assert with `Mail::assertQueued`, not `assertSent` (a 1.4 gotcha). [Source: 1-4 EmailCaptureTest]
- Quality lessons: run **PHPStan**; locked copy verbatim; calm voice; no CTA in the welcome.

### References
- [Source: _bmad-output/planning-artifacts/epics.md#Story-1.5]
- [Source: _bmad-output/planning-artifacts/architecture/architecture-tripcast-2026-06-28/ARCHITECTURE-SPINE.md#AD-11, #AD-13, #AD-10, #AD-7, #Capability-Map, #Structural-Seed]
- [Source: _bmad-output/planning-artifacts/ux-designs/ux-tripcast-2026-06-28/EXPERIENCE.md#Voice-and-Tone (Written strings, Subject lines); DESIGN.md#Components, #Colors]
- [Source: _bmad-output/specs/spec-tripcast/SPEC.md#FR-9]

## Dev Agent Record

### Agent Model Used

Amelia (Senior Software Engineer) — claude-opus-4-8[1m]

### Debug Log References

- TDD: `WelcomeMailTest` (5) written, implemented, green. Full suite: `./vendor/bin/pest` 50 passed / 196 assertions. Pint clean, PHPStan 0, vue-tsc clean, build:ssr green.

### Completion Notes List

- `WelcomeMail` (queued, like `MagicLinkMail`): subject "We're watching {city}"; calm HTML (no CTA, dark-pair `<style>`, web-safe fonts, table+inline styles) + content-complete plain-text twin; locked body copy verbatim.
- Date logic in the mailable: `dateRange` ("14–21 July" / "14 July – 2 August"); `firstDigestDate` = `max(departure − 7 days, today America/New_York)` (window-open boundary, AD-11/AD-7; the authoritative cadence predicate is Story 2.2). All math via `CarbonImmutable` (matches the `date` cast).
- Welcome is queued **inside `CreateTrip`** after the transaction commits (AD-10), suppressed for `email_opted_out` owners (AD-13) — so every creation path (landing now, dashboard-add Story 3.2) gets it. The magic link stays in `LandingController` (transactional, always sent).
- Deferred (boundary): the full digest deliverability footer — `List-Unsubscribe` one-click, in-body unsubscribe/end-trip, physical postal address — is UX-DR17 / Epic 2 (Stories 2.4/2.5). Welcome stays minimal per UX-DR7.
- **Epic 1 is now complete** (all five stories implemented; the setup spine runs landing → geocode → email → atomic create → magic link + welcome).

### File List

**Created**
- `app/Mail/WelcomeMail.php`
- `resources/views/emails/welcome.blade.php` · `resources/views/emails/welcome-text.blade.php`
- `tests/Feature/Mail/WelcomeMailTest.php`

**Modified**
- `app/Actions/CreateTrip.php` — queue `WelcomeMail` post-commit, honoring opt-out
- `app/Http/Controllers/LandingController.php` — welcome-seam comment now points at `CreateTrip`

### Change Log

| Date | Change |
| --- | --- |
| 2026-06-29 | Story 1.5 implemented: one-time calm `WelcomeMail` (FR-9, locked copy, plain-text twin, no CTA) queued from `CreateTrip` post-commit (AD-11), honoring `email_opted_out` (AD-13); first-digest date = window-open floored to today (AD-7). 5 new tests (50 total). Closes Epic 1. Status → review. |

## Review Findings (Epic 1 batch review — 2026-06-29)

**Applied (High/Medium)**
- [x] [Review][Patch] `firstDigestDate` normalized to the America/New_York send clock (AD-7) [app/Mail/WelcomeMail.php]

**Action items (Low — open)**
- [ ] [Review][Patch] `dateRange`: add the year on cross-year trips; collapse a same-day trip to one date [app/Mail/WelcomeMail.php]

**Deferred**
- [x] [Review][Defer] `WelcomeMail` + `SerializesModels` fails if the trip is soft-deleted before the job runs — no soft-delete path until Story 3.1. See deferred-work.md.

**Dismissed**
- Welcome "7 July" vs spec "9 July" — code is correct per AD-11's `[departure−7d]` window; the EXPERIENCE.md prose was corrected to 7 July.
