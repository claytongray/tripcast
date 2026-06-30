---
baseline_commit: d79abc2ac1f16cb1f587ede26659dfffae256e7f
---

# Story 2.4: Digest render + delivery with bounded retry

Status: review

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

As a traveler,
I want one clean morning email with my countdown and 7-day forecast,
so that I never open a weather app — and I never get a broken email.

## Acceptance Criteria

**AC1 — The digest renders from the persisted snapshot** *(FR-7, AD-7, AD-9, UX-DR5, UX-DR6, UX-DR16)*
- **Given** a claimed `email_logs` row with a persisted snapshot (Story 2.3)
- **When** the digest renders
- **Then** it shows the **Canonical Place Name**, a **countdown/position line** — pre-trip "N days until {place}", departure day "Today: {place}", during "Day N in {place}", last day "Last day in {place}" — and **7 day-rows**, each with high/low in **both °F and °C**, a conditions description, and precip probability; a **limited-data** day/forecast shows the calm "limited data" line, never fabricated values.

**AC2 — Delivery with bounded in-process retry, always terminal** *(FR-6, AD-4)*
- **Given** delivery via the Mailer with the job at `tries = 1`
- **When** delivery fails
- **Then** it retries **in-process ≤ 3×, delivery only** (weather is **not** re-fetched — the snapshot is already persisted), then reaches a **terminal state** (`email_logs.status = sent`, or `failed` + reason) and **defers recovery to the next day's run** — never sending a fabricated/stale/empty digest.

**AC3 — Content-complete plain-text twin, image-safe** *(NFR-2, UX-DR5, UX-DR17, UX-DR18)*
- **And** every send pairs a **content-complete plain-text twin** (position line + all 7 days with °F **and** °C + precip + the limited-data behavior) and the HTML is **fully legible with all images blocked** (conditions are text, never image-only).

## Tasks / Subtasks

- [x] **Task 1 — Countdown / position line (the locked boundary copy)** (AC: 1)
  - [x] `app/Digest/CountdownLine.php`: from the trip + the America/New_York "today", produce the **position line** using `CadencePredicate::daysUntilDeparture` (the one authority, AD-11): `>0` → "{N} days until {place}" (singular "1 day until"); `0` → "Today: {place}"; in-trip → "Day {N} in {place}"; `today == return` → "Last day in {place}". Also a **subject suffix**: "{N} days to go" / "today" / "day {N}" / "last day". Place = the short (city) name. Tz-safe (calendar-date strings, AD-7).
- [x] **Task 2 — `DigestMail` mailable + HTML/text templates** (AC: 1, 3)
  - [x] `app/Mail/DigestMail.php` (**not** `ShouldQueue` — it is sent synchronously inside the already-queued `SendTripDigest` so the job owns the retry/terminal-state, AD-4): constructor takes the `Trip` + the snapshot array (`email_logs.weather_snapshot`) + `sendDate`; subject **"{placeShort} — {subject suffix}"** (place leads, **weather verdict never in the subject**); `Content(view: emails.digest, text: emails.digest-text, with: [...])`
  - [x] View data: canonical place name, position line, and the 7 day-rows from the snapshot (`date`, `conditionText`, `precipChance`, `highC/F`, `lowC/F`) + the `limited` flag; format dates as the destination-local day label, temps with both units (tabular), precip in `ink-secondary`
  - [x] `resources/views/emails/digest.blade.php` — `surface-base` outer, fluid `surface-raised` card (max 600px), header (place name `display` + position line), 7 stacked day-rows (hairline divider, day label + condition **text** + high/low °F&°C + precip %), a "limited data" line when limited; calm footer; `color-scheme` meta + dark pairs, web-safe fonts, table layout, inline styles; **conditions are text** (legible with images blocked — no glyph image required in v1)
  - [x] `resources/views/emails/digest-text.blade.php` — content-complete plain-text twin (position line + all 7 days °F&°C + precip + condition + limited-data)
  - [x] **Footer seam:** include a stable physical postal-address line if `config('tripcast.postal_address')` is set; the **End-trip / Unsubscribe / Feedback links + `List-Unsubscribe` headers are Story 2.5/2.6** — leave a clearly-marked seam, do not build them here
- [x] **Task 3 — Deliver from the job with bounded retry → terminal state** (AC: 2)
  - [x] In `SendTripDigest@handle`, **after** the snapshot is persisted (Story 2.3): render + send `DigestMail` via the Mailer **in-process**, retrying **delivery only** up to `config('tripcast.send.max_delivery_attempts')` (default 3) on a transport throwable; **never re-fetch weather** (use the persisted snapshot)
  - [x] On success → `email_logs.status = sent`; on exhaustion → `status = failed` + reason. The job still runs `tries = 1` (no Laravel re-dispatch, AD-4); no row left in `sending` by normal flow
  - [x] Add `config('tripcast.send.max_delivery_attempts')` (default 3) to the `send` block
- [x] **Task 4 — Tests** (AC: 1, 2, 3)
  - [x] `CountdownLine`: pinned clock — pre-trip "5 days until Edinburgh", "1 day until", departure day "Today: Edinburgh", mid-trip "Day 2 in Edinburgh", last day "Last day in Edinburgh"; subject suffixes match
  - [x] `DigestMail` render: subject is "{place} — {suffix}" with **no** weather verdict; HTML + text both contain the canonical place, the position line, **all 7 days** with °F **and** °C + precip + condition; a limited snapshot renders the "limited data" line and **no fabricated** values for the limited day; the plain-text twin is present and content-complete
  - [x] Job success: with a claimed+snapshotted row, the job sends `DigestMail` (`Mail::fake`/`assertSent` to the owner) and sets `status = sent`; the `WeatherProvider` is fetched **once** total
  - [x] Job delivery failure: Mailer throws on every attempt → the job retries up to the cap, sets `status = failed` + reason, **does not re-fetch weather**, and no exception escapes
  - [x] End-to-end via the queue (sync) is exercisable: a due trip → `digests:send` → job → `sent` row + a sent `DigestMail`

## Dev Notes

### Scope boundary (read first)
- This story is **render the snapshot + deliver with bounded retry + terminal state**. The **footer action links** — End-trip/Unsubscribe (Story 2.5) and Feedback (Story 2.6) — and the **`List-Unsubscribe` headers** (deliverability, 2.5) are **seams**, not built here. **AI narration** (the optional line, Story 4.2) and the **affiliate promo slot** (Epic 5) are also later slots in the same template — leave clearly-marked placeholders. Weather is already fetched + snapshotted (Story 2.3) — **never re-fetch** here. [Source: epics.md#Story-2.5, #Story-2.6, #Story-4.2, #Epic-5]

### Architecture (binding)
- **AD-4 — bounded delivery retry, always terminal, never broken:** the Laravel job stays `tries = 1` (Laravel must never re-dispatch — it would hit its own claim). Retry is **in-process, ≤3×, on delivery only** — the forecast snapshot is already persisted (AD-3), so weather is **not** re-fetched. The job **always reaches a terminal state**: `email_logs.status = sent`, or `failed` + reason; recovery is the **next day's run** (a new `send_date`). A digest is **never** sent with fabricated/stale values. [Source: ARCHITECTURE-SPINE.md#AD-4]
- **AD-9 — read snapshot, write outcome on the same row:** render from `email_logs.weather_snapshot` (do not re-fetch, do not cache elsewhere); write the `sent`/`failed`+reason to the same claimed row. [Source: ARCHITECTURE-SPINE.md#AD-9]
- **AD-7 — time frames + temperatures:** the **countdown/position uses the America/New_York "today"** via `CadencePredicate::daysUntilDeparture` (AD-11 single authority — never re-implement the day math); the **7 forecast rows are the destination-local days exactly as stored** in the snapshot. Render **both °F and °C** (the snapshot carries both; tabular numerals so columns align). [Source: ARCHITECTURE-SPINE.md#AD-7, #AD-11, #Consistency-Conventions "Temperatures"]
- **Mailer:** deliver via Laravel's `Mailer` (a `Mailable`), driver-agnostic — local is Mailtrap SMTP, prod is MailerSend (the `mailersend/laravel-driver` install is deferred; the `Mailable` works on any driver). The `DigestMail` is **not** `ShouldQueue` (the job already runs on the queue and owns the bounded retry). [Source: ARCHITECTURE-SPINE.md#Stack; tripcast dev env (Mailtrap)]

### UX (binding)
- **UX-DR5 — digest template:** `surface-base` outer, fluid `surface-raised` content card (max 600px); header = place name (`display`) + countdown/position line (optional sunrise glyph); 7 stacked day-rows; **(optional) narration line slot** (4.2) and **(optional) promo slot below the forecast** (Epic 5) — leave the slots; footer = (later) feedback + end-trip + unsubscribe + **stable physical postal address** + the plain-text twin always paired. [Source: DESIGN.md#Components "Digest email"; EXPERIENCE.md Email Delivery & Inbox Invariants]
- **UX-DR6 — forecast day-row:** hairline divider, no fill; day label (`meta`) · condition + short description (`body`) · high/low in **both °F and °C** (`temp`, tabular) · precip % in **`ink-secondary`** (not the rain color). Glyphs are hosted PNGs in the final design, but **meaning never lives in an image alone** and the email must be legible with images blocked — so v1 renders the **condition as text** (no glyph asset needed yet; glyph PNGs are a later polish). [Source: DESIGN.md#Components "Forecast day-row"; UX-DR6]
- **UX-DR16 — locked copy:** countdown/position boundaries — "4 days until Edinburgh", "Today: Edinburgh.", "Day 2 in Edinburgh", "Last day in Edinburgh." Limited data — "Limited data today — we'll have the full picture tomorrow." Subjects — place leads, countdown is the hook, **weather verdict never in the subject**, no emoji/all-caps/exclamation (e.g. "Edinburgh — 5 days to go", "Edinburgh — day 2"). Rough-weather lexicon stays calm (not this story's data, but keep condition text plain). [Source: EXPERIENCE.md Voice and Tone (Written strings, Subject lines, Component Patterns "Countdown/position line")]
- **UX-DR17/DR18 (NFR-2) — inbox invariants:** plain-text twin is a **content-complete** mirror (countdown + all 7 days °F&°C + precip + limited-data); body ≥16px; legible with images blocked; `role="presentation"` layout tables; dark-mode aware. (Full `List-Unsubscribe` + one-click is Story 2.5.) [Source: EXPERIENCE.md Email Delivery & Inbox Invariants; UX-DR17, UX-DR18]

### Position-line / subject logic (concrete, tz-safe)
- `daysUntil = CadencePredicate::daysUntilDeparture(trip, today)` (today = `now('America/New_York')`).
- Position line: `daysUntil > 1` → "{daysUntil} days until {place}"; `== 1` → "1 day until {place}"; `== 0` → "Today: {place}"; `< 0` → if `today == return_date` → "Last day in {place}", else "Day {abs(daysUntil)+1} in {place}" (departure day = Day 1). Place = the city (text before the first comma of the canonical name, same as `WelcomeMail`).
- Subject suffix mirrors it: `>1` "{n} days to go", `==1` "1 day to go", `==0` "today", last day "last day", else "day {n}". Subject = "{place} — {suffix}".
- Compare dates as Y-m-d strings (the cadence predicate already does — reuse `daysUntilDeparture`; for the last-day check compare `today->toDateString()` to `trip->return_date->toDateString()`).

### Snapshot → rows
- Read `email_logs.weather_snapshot` (array cast, shape from `Forecast::toArray()`: `{days: [{date, conditionText, precipChance, highC, highF, lowC, lowF}], limited: bool}`). For each day render the destination-local `date` as a weekday/short label, condition text, `highF°/highC°` + `lowF°/lowC°`, precip `precipChance%`. A day with null temps/condition (limited) renders the calm limited marker instead of values; if `limited` (or any day limited), include the forecast-level "Limited data today — we'll have the full picture tomorrow." line. **Never** fabricate.

### Testing standards
- Pest, MySQL `tripcast_test`, `RefreshDatabase`. Build a trip + a claimed `email_logs` row with a known `weather_snapshot` (use `Forecast::toArray()` from a constructed `Forecast`, or an inline array). Pin the clock (`Carbon::setTestNow`, ET) for countdown boundaries.
- Mailable render: `new DigestMail(...)` + `assertHasSubject`, `assertSeeInHtml`, `assertSeeInOrderInText([...])`, `assertDontSeeInHtml('background-image')` etc. Assert both °F and °C appear for each day.
- Job delivery: `Mail::fake()` + `Mail::assertSent(DigestMail::class, fn ($m) => $m->hasTo($trip->user->email))` and `email_logs.status === 'sent'`; mock the `WeatherProvider` to assert **one** fetch. For the failure path, make the Mailer throw (e.g. `Mail::shouldReceive(...)` / a transport stub) on every attempt → assert `status = failed` + reason and the attempt count, no exception escaping, no re-fetch.
- Gates: `./vendor/bin/pest`, `./vendor/bin/pint`, `./vendor/bin/phpstan analyse`, `npm run types:check`, `npm run lint:check`, `npm run build:ssr` (frontend unchanged).

### Project Structure Notes
- New: `app/Digest/CountdownLine.php`, `app/Mail/DigestMail.php`, `resources/views/emails/digest.blade.php`, `resources/views/emails/digest-text.blade.php`, tests; **modified:** `app/Jobs/SendTripDigest.php` (render + deliver + terminal), `config/tripcast.php` (`send.max_delivery_attempts`, optional `postal_address`). No migrations/routes/frontend. [Source: ARCHITECTURE-SPINE.md#Structural-Seed]

### Previous story intelligence (Stories 2.1–2.3, 1.5)
- **`SendTripDigest`** already claims + fetches once + persists `weather_snapshot` and handles the weather-failure terminal (Story 2.3). This story appends the **render + deliver + terminal sent/failed** after the snapshot persist; the success path must set `sent` (2.3 left it `sending`). Keep `tries = 1`. [Source: 2-3 SendTripDigest]
- **`CadencePredicate::daysUntilDeparture(trip, date)`** (Story 2.2) is the day-math authority — reuse it for the countdown; don't recompute. [Source: 2-2 CadencePredicate]
- **`weather_snapshot`** is the `Forecast::toArray()` shape (Story 2.3) — render from this array; note JSON normalizes whole floats (20.0 → 20), so format defensively (`number_format`/cast). [Source: 2-3 toArray + JSON note]
- **Email Blade pattern**: mirror `resources/views/emails/welcome.blade.php` / `magic-link.blade.php` (dark-mode `<style>`, web-safe fonts, table shell, `color-scheme` meta) — and reuse the `placeShort` (before-comma) helper idea from `WelcomeMail`. [Source: 1-5 WelcomeMail + welcome.blade.php]
- **Mailtrap is live** for local; the digest will land there when a due trip is processed (`composer run dev` runs the worker). MailerSend driver install deferred. [Source: tripcast dev env]
- Quality lessons: run **PHPStan**; pin the clock for date tests; never re-fetch weather on delivery retry; keep the condition legible without images.

### References
- [Source: _bmad-output/planning-artifacts/epics.md#Story-2.4] (+ #Story-2.5/2.6, #Story-4.2, #Epic-5 for the seams)
- [Source: _bmad-output/planning-artifacts/architecture/architecture-tripcast-2026-06-28/ARCHITECTURE-SPINE.md#AD-4, #AD-9, #AD-7, #AD-11, #Stack, #Send-pipeline]
- [Source: _bmad-output/planning-artifacts/ux-designs/ux-tripcast-2026-06-28/DESIGN.md#Components ("Digest email", "Forecast day-row"); EXPERIENCE.md#Voice-and-Tone, #Component-Patterns, #Email-Delivery-&-Inbox-Invariants]
- [Source: _bmad-output/specs/spec-tripcast/SPEC.md#FR-7, #FR-6]

## Dev Agent Record

### Agent Model Used

claude-opus-4-8 (1M context)

### Debug Log References

- PHPStan flagged dead `?? false` / `?? []` on the typed `array{days, limited}` snapshot property (`nullCoalesce.initializedProperty`) — removed; the declared shape guarantees the keys.
- The 2.3 snapshot test left the row in `sending`; 2.4's delivery now drives it terminal, so that test was updated to fake mail and assert `sent` + `assertSent`.

### Completion Notes List

- **Task 1 — CountdownLine:** already drafted in the context story; covered by a new unit test pinning the ET clock across all five boundaries (pre-trip plural/singular, departure-day "Today", mid-trip "Day N", last day) and the subject suffixes. Built on unsaved `Trip` instances (no DB), bound to `Tests\TestCase` so the app boots.
- **Task 2 — DigestMail + templates:** `DigestMail` is intentionally **not** `ShouldQueue` (the job owns retry/terminal state, AD-4). Subject is `"{placeShort} — {suffix}"` with the weather verdict never present. Day-rows are projected in the mailable (`dayRows()`): temps rounded to whole degrees in both units, limited days carry nulls (never fabricated). HTML mirrors the welcome template (table layout, `color-scheme` meta + dark pairs, `role="presentation"`, real `<h1>`, `lang`/`dir`, ≥16px body, **text-only conditions** — no `<img>`/`background-image`). Postal-address footer is wired off `config('tripcast.postal_address')`; unsubscribe/feedback/`List-Unsubscribe` and the narration/promo slots are clearly-marked seams for 2.5/2.6/4.2/Epic 5.
- **Task 3 — Delivery:** `SendTripDigest::deliver()` runs after the snapshot persists, retrying the Mailer in-process up to `config('tripcast.send.max_delivery_attempts')` (default 3) on any `Throwable`, then terminal `sent` or `failed` + `'delivery: …'` reason. Weather is never re-fetched. Job stays `tries = 1`.
- **Task 4 — Tests:** CountdownLine unit, DigestMail render (subject/no-verdict, both units per day, condition+precip, limited marker + no fabrication via `Low ` count, images-blocked, postal address), job success (`assertSent` + `sent`, one fetch), job delivery-failure (3 attempts → `failed`, snapshot kept, one fetch, no escape), and an end-to-end `digests:send` → sync job → `sent` row + sent `DigestMail`.
- **Gates:** `./vendor/bin/pest` (98 passed), `./vendor/bin/pint` (clean), `./vendor/bin/phpstan` (0 errors), `npm run types:check`, `npm run lint:check`, `npm run build:ssr` all green.

### File List

**New:**
- `app/Digest/CountdownLine.php`
- `app/Mail/DigestMail.php`
- `resources/views/emails/digest.blade.php`
- `resources/views/emails/digest-text.blade.php`
- `tests/Unit/Digest/CountdownLineTest.php`
- `tests/Feature/Digest/DigestMailTest.php`

**Modified:**
- `app/Jobs/SendTripDigest.php` (render + deliver + terminal sent/failed after snapshot persist)
- `config/tripcast.php` (`send.max_delivery_attempts`, `postal_address`)
- `tests/Feature/Digest/SendTripDigestTest.php` (2.3 snapshot test → 2.4 terminal `sent`; delivery-failure test)
- `tests/Feature/Digest/SendDailyDigestsTest.php` (end-to-end delivery test)

### Change Log

- 2026-06-29 — Implemented Story 2.4: digest render from the persisted snapshot + in-process bounded delivery retry to a terminal `sent`/`failed`. Added `DigestMail`, HTML + content-complete text templates, `CountdownLine` tests, and delivery/e2e tests. All gates green.
