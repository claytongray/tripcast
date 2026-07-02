---
baseline_commit: 4438067
---

# Story 9.10: Sign-in link at the trip limit

Status: done

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

As a visitor whose email already holds an account at its trip limit,
I want the at-limit error to also email me a sign-in link,
so that I can get into my account and manage the trips I didn't know I had.

## Acceptance Criteria

**AC1 — At-cap capture sends a sign-in link and says so** *(FR-30, AD-15)*
- **Given** a guest at the email-capture step (`POST /trip`) whose address belongs to an account already at the active-trip cap
- **When** they submit
- **Then** no Trip is created (AD-15 unchanged), a magic sign-in link is emailed to that address, and the inline email-field error tells them both things — they're at the limit **and** a sign-in link is in their inbox. The pending trip stays in the session for a retry after they free a slot.

**AC2 — The send rides the existing magic-link machinery** *(FR-30, AD-6)*
- **Given** the sign-in link send
- **When** it is issued
- **Then** the shared per-email + per-IP throttle buckets (`ThrottlesMagicLink`) guard it, same-browser reuse semantics hold (a still-valid pending link in this session is re-emailed unchanged rather than rotated), and `magic_link_pending` is stashed so a later `/login` resend reuses this link.

**AC3 — Throttled: standard error, no email, still no Trip** *(FR-30)*
- **Given** the magic-link throttle exhausted for that email or IP
- **When** an at-cap submit happens
- **Then** the standard "Too many requests…" validation error shows on the email field, no email is sent, and no Trip is created.

**AC4 — Everything else unchanged** *(FR-30, AD-15)*
- **Given** every other path
- **When** this change lands
- **Then** an under-cap capture still creates atomically and redirects to `login.sent` with signup intent; the logged-in dashboard add (`TripController@store`) still refuses over-cap with the default pause-or-remove message and sends nothing; `TripLimitReachedException`'s default message is untouched.

## Tasks / Subtasks

- [x] Task 1: Rework the `TripLimitReachedException` catch in `LandingController::createTrip` (AC: 1, 2, 3)
  - [x] Add `use ThrottlesMagicLink;` to `LandingController` (trait: `App\Http\Controllers\Concerns\ThrottlesMagicLink`)
  - [x] In the catch block, call `$this->ensureNotThrottled($request, $email)` — a throw here bubbles as a `ValidationException` on `email` (AC3, no code needed for that rendering)
  - [x] Issue via `$requestMagicLink->resendOrIssue($email, $pendingToken)` where `$pendingToken` comes from `session('magic_link_pending')['token']` (mirror `MagicLinkController@store` lines 48–49)
  - [x] Stash `magic_link_pending` = `['token' => $result['token'], 'intent' => $intent]` where `$intent` preserves a reused link's prior intent, else `'login'` (mirror `MagicLinkController@store` line 56)
  - [x] Return `back()->withErrors(['email' => …])` with the new copy (below); keep `pending_trip` in the session (already the case — don't forget it)
- [x] Task 2: Error copy (AC: 1)
  - [x] Message: `You're at your plan's trip limit — we emailed you a sign-in link. Use it to manage your trips, then add this one.`
  - [x] No frontend change: `TripDetail.vue` already renders `form.errors.email` via `InputError`
- [x] Task 3: Feature tests in `tests/Feature/Landing/EmailCaptureTest.php` (AC: 1, 2, 3, 4)
  - [x] At-cap submit: no new Trip, `MagicLinkMail` queued to the address, session `magic_link_pending` set with `intent: login`, redirect back with the email error containing "sign-in link", `pending_trip` still in session
  - [x] At-cap submit with a still-valid `magic_link_pending` token in the session: the same token is re-emailed (not rotated) — assert the `login_tokens` row count/hash is unchanged and `MagicLinkMail` queued
  - [x] At-cap submit with the per-email throttle exhausted (`RateLimiter` hits ≥ `tripcast.magic_link.throttle.max_attempts`): error is the throttle message, `Mail::assertNothingQueued()`, no Trip
  - [x] Dashboard add path unchanged: existing test in `tests/Feature/Trip/TripLimitTest.php` ('refuses an over-cap dashboard add…') keeps passing — extend it with `Mail::assertNothingQueued()` already present; no magic link on that path
- [x] Task 4: Verification gates
  - [x] `php artisan test --compact` (full suite) · `vendor/bin/pint --dirty --format agent` · `./vendor/bin/phpstan analyse` · `npm run types:check` · `npm run lint:check` · `npm run build:ssr` (SSR build required by Forge deploy — see forge-deploy memory; skip only if no frontend files changed, which is expected here)

### Review Findings

- [x] [Review][Patch] (was Decision — user chose to patch in 9.10) Logged-in resubmit re-enters the guest capture flow — RESOLVED: `createTrip` now short-circuits when the submitted email matches the signed-in account (case-insensitive): the trip is created, no magic link is sent, no interstitial — redirect to `trips.added`; an at-cap signed-in owner gets the default "pause or remove" message (actionable from their dashboard, no link). A mismatched email keeps the guest flow. Three tests pin all three branches. [app/Http/Controllers/LandingController.php]
- [x] [Review][Patch] Mail assertions don't pin recipient or count — RESOLVED: AC1/AC2 tests assert `hasTo('maya@example.com')` + `Mail::assertQueuedCount(1)` [tests/Feature/Landing/EmailCaptureTest.php]
- [x] [Review][Patch] Intent-reset and malformed-stash branches unpinned — RESOLVED: consumed-token stash → fresh issue with `intent => 'login'` and rotated token; malformed string stash → no crash, fresh issue as login [tests/Feature/Landing/EmailCaptureTest.php]
- [x] [Review][Patch] Throttle-path coverage gaps — RESOLVED: organic-consumption test (max_attempts=2: both boundary submits send, third is blocked with nothing queued, pending trip retained); AC3 test now also pins `pending_trip` retained + `magic_link_pending` untouched; mixed-case test pins reuse match + the lowercased shared bucket [tests/Feature/Landing/EmailCaptureTest.php]
- [x] [Review][Defer] Email bucket is hit before the IP-bucket check throws, so an IP-blocked attacker still drains a victim's per-email bucket [app/Http/Controllers/Concerns/ThrottlesMagicLink.php:21-31] — deferred, pre-existing trait behavior shared by /login and /sample
- [x] [Review][Defer] Single `magic_link_pending` session slot: a magic-link action for email B clobbers email A's stash, so a later /login resend for A falls to a fresh issue and rotates A's still-in-flight link [app/Http/Controllers/Auth/MagicLinkController.php:58, app/Http/Controllers/LandingController.php] — deferred, pre-existing single-slot design
- [x] [Review][Defer] A queue/DB failure inside the magic-link send 500s after the throttle hit is consumed (no friendly error path) [app/Actions/RequestMagicLink.php:78] — deferred, pre-existing on every magic-link surface

## Dev Notes

### Why this story exists (read first)

Trips are created at email capture (`LandingController::createTrip` → `CreateTrip::handle`), **before** any email confirmation — by design (AD-10: atomic user+trip create; unconfirmed trips never email, `CadencePredicate` gates on `email_verified_at`). Consequence: an owner whose confirmation email never lands can re-submit from the landing page a few times, fill their 3-slot cap (`tripcast.free_tier.max_active_trips`) with unconfirmed trips, and then hit a dead-end error — "Pause or remove one to add another" — with no way to sign in from that screen (the catch returns before `RequestMagicLink` runs). Hit in production 2026-07-02 by the builder's own account. This story turns the dead end into a sign-in path. The pre-confirmation quota accounting itself is deliberately **unchanged**.

### The change, precisely

One file of production code: `app/Http/Controllers/LandingController.php`, inside `createTrip`'s `catch (TripLimitReachedException $e)` (currently lines 121–124). Current state: returns `back()->withErrors(['email' => $e->getMessage()])`, keeping `pending_trip`. New state (order matters):

1. `$this->ensureNotThrottled($request, $email);` — shared buckets with login + sample sends (`magic-link:{email}`, `magic-link-ip:{ip}`), so this path can't be scripted to spam links. A throttle throw is a `ValidationException` on `email` → Inertia redirects back with the message; that IS the AC3 behavior, no catch needed.
2. `$pending = $request->session()->get('magic_link_pending'); $pendingToken = is_array($pending) ? ($pending['token'] ?? null) : null;`
3. `$result = $requestMagicLink->resendOrIssue($email, $pendingToken);` — NOT `handle()`: `resendOrIssue` re-emails a still-valid same-browser link unchanged (original expiry, no rotation), matching the resend semantics established by the 2026-07-01 sprint change (`MagicLinkResendTest`). A fresh issue rotates prior unconsumed tokens — fine.
4. `$intent = ($result['reused'] && is_array($pending)) ? ($pending['intent'] ?? 'login') : 'login';` then stash `magic_link_pending` with token + intent — so a follow-up `/login` resend reuses this exact link and keeps its copy.
5. `return back()->withErrors(['email' => "You're at your plan's trip limit — we emailed you a sign-in link. Use it to manage your trips, then add this one."]);`

Note the `$e` variable becomes unused — drop it from the catch (`catch (TripLimitReachedException)`), or pint/phpstan will flag it.

### Guardrails — do NOT

- **Don't touch `TripLimitReachedException`'s default message** — the dashboard add path (`TripController@store` → catch → `destination` error) uses it, where "pause or remove" is actionable because the user is signed in and looking at the pause/remove buttons.
- **Don't change `CreateTrip`** — the cap check and its single-decision-point invariant (AD-15) are exactly right; this story is purely about the refusal surface.
- **Don't clear `pending_trip` on the limit path** — the existing comment ("Keep the pending trip so they can retry after pausing/removing one") still holds, and now the retry story is real: free a slot in this browser after signing in, come back to `/trip`, resubmit.
- **Don't redirect to `login.sent`** — the user must see the *error* in context (they tried to add a trip and it didn't save). The inline email-field error carrying both facts is the spec. The interstitial redirect stays exclusive to successful capture.
- **Don't add frontend changes** — `TripDetail.vue` renders `form.errors.email` already. No new component, no new page.
- **Enumeration note (accepted):** the current error already discloses that an email has an at-cap account; this story adds no new disclosure. The emailed link goes only to the address owner.

### Existing machinery being reused (verified current 2026-07-02)

| Piece | Where | What it gives you |
|---|---|---|
| `ThrottlesMagicLink` trait | `app/Http/Controllers/Concerns/ThrottlesMagicLink.php` | `ensureNotThrottled($request, $email)`; per-email + per-IP `RateLimiter` buckets shared with `MagicLinkController@store` and `SampleController@store`; throws `ValidationException` on `email` with "Too many requests. Try again in N minute(s)." |
| `RequestMagicLink::resendOrIssue` | `app/Actions/RequestMagicLink.php:95` | Reuse-or-fresh issue; returns `{user, url, token, expires_at, ttl_minutes, reused}`; queues `MagicLinkMail` on either branch; lowercases/trims email itself |
| `magic_link_pending` session convention | `MagicLinkController@store:48–58`, `LandingController@createTrip:142` | `['token' => raw, 'intent' => 'signup'|'login']`; consumed link forgets it (`MagicLinkController@consume:143`) |
| Throttle config | `config/tripcast.php` → `tripcast.magic_link.throttle.{max_attempts,ip_max_attempts,decay_minutes}` | Read these in tests rather than hardcoding |
| Cap config | `tripcast.free_tier.max_active_trips` (default 3, env `TRIPCAST_MAX_ACTIVE_TRIPS`) | Tests set `config(['tripcast.free_tier.max_active_trips' => 1])` to stay cheap (see `TripLimitTest`) |

Downstream (no changes needed, but know it): consuming the link (`MagicLinkController@consume`) confirms the email on first use, sends the held welcome emails for all trips, lands a just-confirmed user on `trips.added` for their newest trip — so the locked-out-unconfirmed owner gets the full activation flow, then can pause/remove from the dashboard.

### Testing notes

- File: extend `tests/Feature/Landing/EmailCaptureTest.php` (its `pendingTripSession()` helper + pinned `Carbon::setTestNow('2026-06-29 12:00', 'America/New_York')` + `Mail::fake()` in `beforeEach` are what you need). Trip factory has `paused()`/`completed()` states; `User::factory()->confirmed()` exists.
- At-cap setup: `$user = User::factory()->create(['email' => 'maya@example.com']); Trip::factory()->count(3)->for($user)->create();` — or cap=1 via config for speed. Note: `confirmed()` is NOT required — the production case is an unconfirmed owner; test with an unconfirmed one.
- Reuse assertion: seed a valid pending link by first calling `app(RequestMagicLink::class)->issue('maya@example.com')`, put `magic_link_pending => ['token' => $result['token'], 'intent' => 'signup']` in the session alongside `pending_trip`, submit at cap, then assert `LoginToken::count() === 1` and the stored hash still equals `RequestMagicLink::hash($result['token'])`, and session intent stays `'signup'` (reused branch preserves it).
- Throttle test: `RateLimiter::hit('magic-link:maya@example.com', 60)` in a loop up to `config('tripcast.magic_link.throttle.max_attempts')`, then submit and assert the "Too many requests" error + `Mail::assertNothingQueued()`.
- Session assertions ride Pest's `withSession([...])` + `expect(session('pending_trip'))->not->toBeNull()` after the redirect.
- Reminder from project-context: whole suite green ≠ correct — manually reason the two catch orders (throttle BEFORE issue, or a throttled attacker still rotates the victim's tokens via `firstOrCreate`+rotation in `issue()`).

### Project Structure Notes

- Backend-only story; touches `app/Http/Controllers/LandingController.php` (UPDATE) + `tests/Feature/Landing/EmailCaptureTest.php` (UPDATE). No new files, routes, tables, or dependencies.
- Copy voice: "sign-in" (matches `RequestLink.vue` "Sign in to tripcast"), em-dash rhythm, lowercase "tripcast" as product noun, no "watching".

### References

- [Source: _bmad-output/planning-artifacts/epics.md#Story 9.10 / FR-30 / AD-15 / AD-6]
- [Source: app/Http/Controllers/LandingController.php#createTrip — the catch being reworked]
- [Source: app/Actions/CreateTrip.php — cap enforcement, unchanged]
- [Source: app/Actions/RequestMagicLink.php#resendOrIssue — reuse semantics]
- [Source: app/Http/Controllers/Auth/MagicLinkController.php#store — the pattern to mirror for pending-token + intent]
- [Source: _bmad-output/planning-artifacts/sprint-change-proposal-2026-07-01-magic-link-resend.md — why resend never rotates]

## Dev Agent Record

### Agent Model Used

claude-fable-5

### Debug Log References

- Red phase: 3 new tests failed against baseline exactly as expected (old "Pause or remove" copy, no mail queued, no throttle error).
- Green phase: all 3 pass after the catch-block rework; `TripLimitTest` (dashboard path) untouched and green.

### Completion Notes List

- **One production file changed:** `LandingController` — `use ThrottlesMagicLink`, and the `TripLimitReachedException` catch now (in order) throttle-checks via the shared magic-link buckets, issues via `resendOrIssue` with the session's pending token (same-browser reuse, no rotation), stashes `magic_link_pending` (reused link keeps its prior intent, fresh issue is `login`), and returns the new two-fact error copy. Throttle order matters and is the one implemented: `ensureNotThrottled` runs BEFORE `resendOrIssue`, so a throttled attacker cannot rotate a victim's live tokens through this surface.
- **Copy:** "You're at your plan's trip limit — we emailed you a sign-in link. Use it to manage your trips, then add this one." ("sign-in" matches the auth surfaces; no frontend change — `TripDetail.vue` renders `form.errors.email` already.)
- **Untouched by design (AC4):** `TripLimitReachedException` default message (dashboard add path still uses it), `CreateTrip` cap enforcement, `pending_trip` retention on the limit path, the `login.sent` redirect for successful captures.
- **Tests:** 3 new feature tests in `EmailCaptureTest` (AC1 send+copy+session, AC2 reuse-not-rotate with intent preservation, AC3 throttle blocks send). AC4 covered by the existing suite (`TripLimitTest`, `EmailCaptureTest` happy paths) passing unchanged.
- **Gates:** full suite 495 passed (1997 assertions) · pint clean · phpstan 0 errors · vue-tsc clean · eslint clean. `build:ssr` skipped — no frontend files changed (the pre-existing `resources/js/app.ts` working-tree modification predates this story and was left alone).

### Change Log

- 2026-07-02: Story 9.10 implemented (FR-30) — at-cap email capture now emails a sign-in link via the existing magic-link machinery and says so inline; throttled sends show the standard error; no Trip created on any refused path.
- 2026-07-02: Code review (Blind Hunter + Edge Case Hunter + Acceptance Auditor) — 4 items resolved (1 decision→patch: signed-in owner resubmit is now an authenticated add straight to `trips.added`, no second magic link; 3 test-hardening patches), 3 pre-existing items deferred to deferred-work.md, 8 dismissed as noise. Post-review gates: 502 tests / 2046 assertions, pint, phpstan, vue-tsc, eslint all clean.

### File List

**Modified:**
- `app/Http/Controllers/LandingController.php`
- `tests/Feature/Landing/EmailCaptureTest.php`
- `_bmad-output/planning-artifacts/epics.md` (FR-30, coverage row, Epic 9 FR list, Story 9.10 section, collision-note renumber hint)
- `_bmad-output/implementation-artifacts/sprint-status.yaml`

**New:**
- `_bmad-output/implementation-artifacts/9-10-sign-in-link-at-the-trip-limit.md` (this story)
