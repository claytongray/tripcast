---
baseline_commit: 226f37bfeec8909ddf16c535b2f278d3268f9660
---

# Story 10.2: Welcome + first tripcast on signup

Status: done

<!-- Backfilled 2026-07-03 after implementation. Built via the superpowers workflow
(brainstorm → spec → plan → subagent-driven development), then documented here in the
house format for the BMAD paper trail. Design spec + implementation plan live at
docs/superpowers/specs/2026-07-03-welcome-first-tripcast-design.md and
docs/superpowers/plans/2026-07-03-welcome-first-tripcast.md. -->

## Story

As someone who just signed up for tripcast by creating a trip,
I want my welcome email to show me a real tripcast right away when my trip is already inside the forecast window (and a clear heads-up plus a sample when it isn't),
so that I see the product's value immediately instead of waiting for the next scheduled send.

## Context & Provenance

- Design approved by Clayton 2026-07-03 in brainstorming. Driving principle: **if we have the data, deliver value on the first email** rather than making a new user wait for the next 7am run.
- **Evolution of shipped behavior, not a greenfield story.** It amends **FR-9 Welcome Email** (Epic 1, Story 1.5 — previously "one calm welcome, no forecast, independent of the window") and reuses the **FR-21 public sample** surface (Epic 6, Story 6.4 — `SampleController` / `SampleDigestMail`). No new FR was minted (mirrors Story 10.1, which registered Epic 10 via the story file + sprint-status.yaml, not epics.md).
- Second story under **Epic 10 ("Lean-launch listening")**. Branch: `feature/welcome-first-tripcast` off `main` (226f37b); merged to `main` (e1ed908) and deployed 2026-07-03.
- **Confirmed-first is preserved (AD-6).** The trigger stays the magic-link click for new signups — no forecast data is ever emailed to an unconfirmed address. The welcome path only ever runs for a confirmed owner.

## Acceptance Criteria

1. **Given** a confirmed owner whose trip is in-window today (`CadencePredicate::isDue($trip, now America/New_York)` — `departure − horizon_days ≤ today ≤ return`, active, confirmed, not opted out), **when** `SendWelcomeEmail::handle($trip)` runs (at trip creation for a logged-in owner, or per pending trip at magic-link confirmation), **then** instead of the plain welcome it dispatches `SendTripDigest::dispatch($trip, today, welcome: true)`, which claims the `(trip_id, send_date)` slot — so the same-day 7am `digests:send` run selects the trip but aborts at `claim()` before re-sending or re-fetching weather (**exactly one tripcast per trip per calendar day**; normal cadence resumes the next morning).
2. **Given** the in-window welcome-mode send, **then** the delivered `DigestMail` (welcome mode) renders a welcome intro block — "You're all set for {city}" + "Here's your first tripcast:" — **above** the normal countdown/forecast, with subject `You're all set for {city}`; forecast rows, footer, and legal footer are byte-identical to a normal digest. In normal mode (`welcome=false`, the daily path) nothing changes.
3. **Given** a confirmed owner whose trip is out-of-window, **when** the welcome path runs, **then** the existing `WelcomeMail` heads-up sends (already stating the first-tripcast date via `CadencePredicate::firstSendDate()`), now including a **"See a sample tripcast now"** CTA.
4. **Given** the out-of-window welcome CTA, **then** it is a **temporary** signed GET (`email.sample.send`, TTL = `config('tripcast.sample.magic_link_ttl_minutes')`, 2880 min) that queues the existing generic Reykjavik sample (`SampleForecast` + `SampleDigestMail`) to the trip owner, records a landing-sourced `SampleRequest`, and renders an Inertia `email/SampleSent` confirmation page. The send is **per-user rate-limited (3/hour, key `sample-welcome:{id}`)**: over-limit hits are absorbed silently (page still renders, no mail/row). A tampered/unsigned/expired signature → **403 and nothing queued**.
5. **Given** an owner who has opted out (`email_opted_out`), **when** the welcome path runs, **then** nothing is sent (early return, both branches). Confirmed-first (AD-6) is unchanged — no forecast email before confirmation; a hypothetical unconfirmed owner routes to `WelcomeMail` (heads-up only) because `isDue()` returns false.
6. **Given** multiple trips created while unconfirmed, **when** the owner confirms via magic link, **then** each trip is evaluated independently — an in-window trip gets a welcome-mode tripcast, an out-of-window trip gets the heads-up welcome (a single confirmation can produce a mix).
7. All verification gates pass: `php artisan test --compact` (542/542 on the merged tree) and `vendor/bin/pint --dirty --format agent` clean.

## Tasks / Subtasks

- [x] Task 1: `DigestMail` welcome mode (AC: 2)
  - [x] Trailing `bool $welcome = false` on the constructor (default keeps every caller unchanged); welcome subject `You're all set for {placeShort}` in `envelope()`; `'welcome' => $this->welcome` added to the `content()` `with` array
  - [x] Welcome intro block (gated on `$welcome`) at the top of `resources/views/emails/digest.blade.php` and `digest-text.blade.php`; forecast/footer/legal untouched
  - [x] TDD: welcome-mode render asserts the intro + real subject; normal mode asserts the intro is absent
- [x] Task 2: Thread welcome mode through `SendTripDigest` (AC: 1)
  - [x] Trailing `bool $welcome = false` on the job constructor; passed as the last arg to `new DigestMail(...)` in `deliver()`; claim/fetch/snapshot/retry logic unchanged
- [x] Task 3: Branch `SendWelcomeEmail` on the forecast window (AC: 1, 3, 5)
  - [x] Inject `CadencePredicate`; opt-out early return first; in-window → `SendTripDigest::dispatch($trip, $today->toDateString(), welcome: true)`; else queue `WelcomeMail`. `$today = CarbonImmutable::now('America/New_York')` — identical key format to `SendDailyDigests`
  - [x] `handle(Trip): void` signature unchanged; both callers (`CreateTrip`, `MagicLinkController::consume`) untouched
- [x] Task 4: Out-of-window sample CTA (AC: 3, 4)
  - [x] Signed route `email.sample.send` (`sample/from-welcome/{user}`, `signed` middleware); `SampleController::sendFromWelcome` reuses `sampleTrip()` + `SampleForecast` + `SampleDigestMail`; `WelcomeMail` passes `sampleUrl`; CTA added to `welcome.blade.php` (button) + `welcome-text.blade.php` (link)
  - [x] Task 4b: confirmation page rendered via Inertia `email/SampleSent` (matches sibling `email/*Result` pages) instead of a standalone Blade view — Clayton's call during review
- [x] Task 5: End-to-end wiring + same-day dedup proof (AC: 1, 6)
  - [x] `WelcomeFirstTripcastFlowTest`: confirmed in-window add → welcome-mode dispatch; out-of-window add → heads-up welcome; **dedup** — welcome-mode claims today's slot, `digests:send` runs and the second job aborts at `claim()` before any second `fetchForecast` (strict `->once()` weather mock + `email_logs` count === 1 + `DigestMail` sent === 1)
- [x] Task 6: Whole-branch review fixes (AC: 4, 7)
  - [x] Sample CTA rate-limited (3/hr, absorb over-limit) + switched to a **temporary** signed URL (TTL from `sample.magic_link_ttl_minutes`) — Clayton chose "keep one-click GET, guard it" over confirm-then-POST
  - [x] `MagicLinkTest` pinned to a fixed clock (was real wall-clock; a `2026-07-14` departure would flip out-of-window → in-window on 2026-07-07 and break)
  - [x] Stale `WelcomeMailTest` "no CTA" title corrected
- [x] Task 7: Copy (AC: 2)
  - [x] Welcome-mode intro "Here's your first forecast:" → "Here's your first tripcast:" (both templates + the asserting test), per Clayton's email review

### Review Findings

- [x] [Review][Decision→Patch] Signed sample CTA was a **one-click GET with side effects** (queued mail + wrote a `SampleRequest`) with no rate limit and a permanent signature — every other email link in the app deliberately splits GET-confirm / POST-act because mail scanners/prefetchers auto-fetch URLs (`EndTripConfirm`/`UnsubscribeConfirm`/`FeedbackConfirm` comments). Clayton chose to **keep the one-click GET but guard it**: per-user rate limit (3/hr, `sample-welcome:{id}`, over-limit absorbed) + temporary signature (TTL = sample nurture-link config). Blast radius is self-inflicted only — it mails just the bound `$user->email`, never an arbitrary address, no forecast data, no confirmed-first bypass. [app/Http/Controllers/SampleController.php, app/Mail/WelcomeMail.php]
- [x] [Review][Patch] `tests/Feature/Auth/MagicLinkTest.php` ran on the real clock — a `2026-07-14` departure becomes in-window on 2026-07-07 (horizon 7), flipping `WelcomeMail` → `SendTripDigest` and breaking the assertion. Pinned the whole file's clock in `beforeEach` (all 14 cases pass; none rely on real elapsed time). [tests/Feature/Auth/MagicLinkTest.php]
- [x] [Review][Patch] Stale test title "…and no CTA" in `WelcomeMailTest` corrected — the out-of-window welcome now has the sample CTA. [tests/Feature/Mail/WelcomeMailTest.php]
- [x] [Review][Confirmed] Double-send invariant verified end-to-end: `SendWelcomeEmail` and `SendDailyDigests` both emit `now('America/New_York')->toDateString()` (byte-identical `Y-m-d` ET), so the `email_logs (trip_id, send_date)` unique index guarantees the collision the dedup test proves.

## Dev Notes

### Critical guardrails (read first)

- **Single cadence authority (AD-11).** In/out-of-window is `CadencePredicate::isDue($trip, now ET)` — never re-derive window math. The dispatched `send_date` MUST be `CarbonImmutable::now('America/New_York')->toDateString()` so its slot key is byte-identical to `SendDailyDigests`'s, or the same-day dedup silently breaks.
- **One tripcast per (trip_id, send_date) (AD-3).** The immediate welcome-mode send is a real tripcast that claims today's slot via the existing claim-first path; it is not a second email channel. Never bypass `SendTripDigest`'s claim.
- **Confirmed-first (AD-6) is untouched.** The welcome path only runs for confirmed owners; do not add or move confirmation logic here.
- **Welcome flag is additive.** `bool $welcome = false` trails the constructor on both `DigestMail` and `SendTripDigest`; default false = zero behavior change for the daily path and every existing caller.
- **Reuse the generic sample — no new sample/forecast logic.** The CTA is a thin entry point over `SampleForecast` + `SampleDigestMail`; the sample is still the fixed Reykjavik demo (`tripcast.sample.destination`).
- **Signed email links can be prefetched.** Mail gateways / SafeLinks / prefetchers auto-fetch URLs in inbound email. The `sample-welcome:{id}` rate limit + temporary signature are the guard chosen instead of a confirm-then-POST split (Clayton's call, Review Finding 1).

### Existing patterns to copy (file:line)

| What | Where |
| --- | --- |
| Cadence window / first-send date authority | app/Digest/CadencePredicate.php (`isDue`, `firstSendDate`) |
| Claim-first `(trip_id, send_date)` dedup + bounded retry | app/Jobs/SendTripDigest.php (`claim`, `deliver`) |
| Daily send_date key format (must match) | app/Console/Commands/SendDailyDigests.php (`now('America/New_York')->toDateString()`) |
| Per-user limiter + calm absorb | app/Http/Controllers/SampleController.php (`storeForSelf`) |
| Sample trip + forecast + mailable reuse | app/Http/Controllers/SampleController.php (`sampleTrip`, `SampleForecast`, `SampleDigestMail`) |
| Signed email-link route + Inertia result page | routes/web.php (`email.*`), app/Http/Controllers/EmailAction.php, resources/js/pages/email/*Result.vue |
| Weather mock + Forecast/ForecastDay in tests | tests/Feature/Digest/SendTripDigestTest.php |

### Architecture & stack constraints

- Laravel 13 / PHP 8.3, Inertia v3, Vue 3, Tailwind v4, Pest 4. Strict types + return types; constructor property promotion; curly braces always [Source: CLAUDE.md php rules].
- Queued path: `SendTripDigest` stays `tries = 1` (the queue never re-dispatches; retry is delivery-only, weather never re-fetched). `WelcomeMail` is `ShouldQueue`; the caller queues it.
- **No migrations, no schema change, no new config knob** (TTL reuses the existing `tripcast.sample.magic_link_ttl_minutes`). Additive deploy.
- Never cache PHP objects in Redis [Source: docs/deployment.md] — this story caches nothing.
- New Vue page `email/SampleSent.vue` mirrors the sibling `email/*Result.vue` (single root, `<script setup lang="ts">`, design tokens, `defineProps`).

### What NOT to do

- No second email for the same trip on the same day — the welcome-mode send IS today's tripcast.
- No re-derived window/first-send math — go through `CadencePredicate`.
- No forecast email before confirmation (AD-6).
- No new sample destination or forecast logic — reuse the generic Reykjavik sample.
- No confirm-then-POST rebuild of the CTA (Clayton chose the guarded one-click GET); but never leave it unguarded (rate limit + TTL required).

### Previous story intelligence

- Story 1.5 (welcome email) originally fired one calm welcome independent of the window; this story makes it window-aware. Story 6.4 (public sample) built the reusable sample; this story adds an authenticated email-link entry point over it.
- Story 10.1 (feedback form) established the `sample-self`-style per-user in-controller limiter and the "register Epic 10 via sprint-status, not epics.md" precedent — both followed here.
- Milestone 1 (timezone-aware sends) moved the global send hour to 7am ET; `CadencePredicate`/`SendDailyDigests` are the shared authority this story keys off.

### Testing standards

- Pest 4 feature tests, `RefreshDatabase`; factories; `Mail::fake()` / `Bus::fake()` before acting; pin the clock with `Carbon::setTestNow(...'America/New_York')` for any window-dependent test (clear in `afterEach`).
- The dedup test's `fetchForecast()->once()` mock is load-bearing — it fails if the same-day 7am run reaches a second fetch. Never relax it to make the test pass; a failure there is a real defect.

### Project Structure Notes

- app/Actions/SendWelcomeEmail.php — UPDATE (branch on the window)
- app/Jobs/SendTripDigest.php — UPDATE (welcome flag)
- app/Mail/DigestMail.php — UPDATE (welcome mode)
- app/Mail/WelcomeMail.php — UPDATE (temporary-signed sample CTA)
- app/Http/Controllers/SampleController.php — UPDATE (`sendFromWelcome` + limiter)
- routes/web.php — UPDATE (one signed route)
- resources/views/emails/digest.blade.php, digest-text.blade.php — UPDATE (welcome intro)
- resources/views/emails/welcome.blade.php, welcome-text.blade.php — UPDATE (CTA)
- resources/js/pages/email/SampleSent.vue — NEW (Inertia confirmation page)
- tests/Feature/Mail/SendWelcomeEmailTest.php, tests/Feature/Sample/WelcomeSampleCtaTest.php, tests/Feature/Trip/WelcomeFirstTripcastFlowTest.php — NEW
- tests/Feature/Digest/DigestMailTest.php, SendTripDigestTest.php, tests/Feature/Mail/WelcomeMailTest.php, tests/Feature/Auth/MagicLinkTest.php — UPDATE

### References

- [Source: docs/superpowers/specs/2026-07-03-welcome-first-tripcast-design.md — approved design]
- [Source: docs/superpowers/plans/2026-07-03-welcome-first-tripcast.md — implementation plan + manual test guide]
- [Source: app/Digest/CadencePredicate.php — single cadence authority (AD-11)]
- [Source: app/Jobs/SendTripDigest.php — claim-first dedup (AD-3), bounded retry (AD-4)]
- [Source: docs/deployment.md — push-to-main auto-deploys; mail/queue change → verify the worker restarts]
- [Source: _bmad-output/implementation-artifacts/1-5-welcome-email.md — the FR-9 story this amends]

## Dev Agent Record

### Agent Model Used

claude-opus-4-8[1m] (controller) with subagent-driven development — per-task implementer + task reviewer subagents (sonnet/haiku by task size), opus whole-branch review.

### Debug Log References

- Each task RED→GREEN with a failing-first test (welcome named-arg "Unknown named parameter" confirmed the tests ran pre-implementation). Full suite green after each task.
- Whole-branch review (opus) verdict "merge with fixes": double-send invariant, confirmed-first, opt-out, and welcome-flag threading verified correct; 2 Important fixes applied (CTA rate-limit + TTL; MagicLinkTest clock pin) + 1 Minor (stale title). Fix wave re-reviewed clean.
- Merged into a `main` that had advanced (Story 10.1 feedback form): clean auto-merge (4 co-touched files, non-overlapping hunks); full suite 542/542 on the merged tree.

### Completion Notes List

- In-window signups now get their first real tripcast immediately, claiming today's slot so the 7am run skips the trip (dedup proven with a strict `->once()` weather mock).
- Out-of-window signups get the heads-up welcome + a guarded ("keep one-click, rate-limit + expiring signature") sample CTA reusing the generic Reykjavik sample, landing on an Inertia confirmation page.
- Additive, no migrations; deploy builds the new Vue page via `npm run build:ssr` and `$RESTART_QUEUES()` picks up the new job code.
- Two decision points were Clayton's: (a) Inertia confirmation page over plain Blade (consistency with sibling email pages); (b) guarded one-click CTA over confirm-then-POST.
- Copy: welcome-mode intro reads "Here's your first tripcast:" (reviewed against rendered emails).

### File List

- app/Actions/SendWelcomeEmail.php (modified)
- app/Jobs/SendTripDigest.php (modified)
- app/Mail/DigestMail.php (modified)
- app/Mail/WelcomeMail.php (modified)
- app/Http/Controllers/SampleController.php (modified)
- routes/web.php (modified — `email.sample.send`)
- resources/views/emails/digest.blade.php (modified)
- resources/views/emails/digest-text.blade.php (modified)
- resources/views/emails/welcome.blade.php (modified)
- resources/views/emails/welcome-text.blade.php (modified)
- resources/js/pages/email/SampleSent.vue (new)
- tests/Feature/Mail/SendWelcomeEmailTest.php (new)
- tests/Feature/Sample/WelcomeSampleCtaTest.php (new)
- tests/Feature/Trip/WelcomeFirstTripcastFlowTest.php (new)
- tests/Feature/Digest/DigestMailTest.php (modified)
- tests/Feature/Digest/SendTripDigestTest.php (modified)
- tests/Feature/Mail/WelcomeMailTest.php (modified)
- tests/Feature/Auth/MagicLinkTest.php (modified — clock pin)
- docs/superpowers/specs/2026-07-03-welcome-first-tripcast-design.md (new — design spec)
- docs/superpowers/plans/2026-07-03-welcome-first-tripcast.md (new — implementation plan)
- _bmad-output/implementation-artifacts/sprint-status.yaml (modified — status tracking)
- _bmad-output/implementation-artifacts/10-2-welcome-first-tripcast.md (this file)

## Change Log

- 2026-07-03: Story 10.2 implemented via subagent-driven development — `DigestMail` welcome mode, `SendTripDigest` welcome flag, `SendWelcomeEmail` window branch, out-of-window sample CTA, and an end-to-end/dedup test suite. Per-task reviews clean.
- 2026-07-03: Whole-branch review (opus). Confirmed the double-send/confirmed-first/opt-out invariants; applied 2 Important fixes (guarded sample CTA — rate limit + expiring signature, Clayton's chosen approach; MagicLinkTest clock pin) and 1 Minor (stale title). Re-review clean.
- 2026-07-03: Copy — welcome-mode intro "first forecast" → "first tripcast" after Clayton reviewed the rendered emails.
- 2026-07-03: Merged to `main` (e1ed908, clean auto-merge onto the Story 10.1 tree, 542/542 green) and deployed to production (Forge auto-deploy). Status → done. Backfilled into this BMAD story artifact for the paper trail.
