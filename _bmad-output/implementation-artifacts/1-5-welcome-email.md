# Story 1.5: Welcome email

Status: ready-for-dev

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

- [ ] **Task 1 — `WelcomeMail` mailable + templates** (AC: 1, 2)
  - [ ] `app/Mail/WelcomeMail.php` (`implements ShouldQueue`, like `MagicLinkMail`): constructor takes the `Trip`; subject **"We're watching {placeShort}"** (city portion of the canonical name); `Content` with `view: emails.welcome` + `text: emails.welcome-text`
  - [ ] Compute view data: `place` (canonical place name), `placeShort` (text before the first comma), `dateRange` (friendly: same-month → "14–21 July", else "14 July – 2 August"), `firstDigestDate` (the Forecast-Window-open date — see Dev Notes), formatted "j F"
  - [ ] `resources/views/emails/welcome.blade.php` — calm HTML (web-safe font stack, `color-scheme` meta, table layout, inline styles, dark-pair aware), **no button/CTA**, locked body copy
  - [ ] `resources/views/emails/welcome-text.blade.php` — content-complete plain-text twin (same facts)
- [ ] **Task 2 — Queue the welcome from `CreateTrip` (honoring opt-out)** (AC: 1, 2)
  - [ ] In `CreateTrip::handle`, **after** the `DB::transaction` commits (outside it — AD-10), **queue** `WelcomeMail` to the trip owner **only if `! $user->email_opted_out`** (AD-13)
  - [ ] Update the Story-1.4 post-commit "welcome seam" comment in `LandingController@createTrip` to point at `CreateTrip` (the welcome now lives there, so every creation path — landing now, dashboard-add in 3.2 — sends it). The **magic link stays in the controller** (auth/transactional, always sent)
- [ ] **Task 3 — Tests** (AC: 1, 2)
  - [ ] `CreateTrip` queues `WelcomeMail` to the owner on creation (`Mail::fake` + `assertQueued`/`hasTo`); the existing magic-link + atomic-create tests still pass
  - [ ] An **opted-out** user (`email_opted_out = true`) adding a trip → `WelcomeMail` is **not** queued (`assertNotQueued`); the magic link still sends (transactional)
  - [ ] Atomicity preserved: a failed create queues **no** welcome (transaction throws before the post-commit queue)
  - [ ] `WelcomeMail` render: subject "We're watching {placeShort}"; HTML + text both contain the destination, the date range, the first-digest date, and the locked sentence; **no CTA/button**; plain-text twin present (`assertSeeInHtml`/`assertSeeInText`)
  - [ ] `firstDigestDate`: pre-window trip (departure > 7 days out) → `departure − 7 days`; in-window trip (departure ≤ 7 days out) → floored to **today (America/New_York)**

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

### Debug Log References

### Completion Notes List

### File List
