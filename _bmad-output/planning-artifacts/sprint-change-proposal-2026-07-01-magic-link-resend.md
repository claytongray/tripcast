# Sprint Change Proposal — Magic-link resend reuse (AD-6 refinement)

**Date:** 2026-07-01
**Author:** Clayton (with Dev agent)
**Scope classification:** Minor — code already implemented + tested; this proposal reconciles the planning artifacts to match.
**Trigger:** Behaviour change requested and implemented in-session for the magic-link **resend** path; discovered it diverges from the adopted AD-6 wording, which has no referable spec.

---

## Section 1 — Issue Summary

**Problem.** When a user requests a magic sign-in link and the email is delayed, they may click **Resend** before the first email arrives. Under the original behaviour, every request (including a resend) rotates the token — so the first email, when it finally lands, carries an **already-invalidated** link and fails. That is a confusing dead-end for a legitimate user.

**Change implemented.** A **same-browser resend within the link's lifetime now re-emails the _still-valid_ link unchanged** — same token, **original expiry (never extended)** — instead of rotating. Only when there is no reusable link (consumed, expired, or none stashed for this browser) does a resend mint a fresh one. The resend email advertises the **remaining** minutes, not a fresh full TTL.

**How discovered.** During implementation + live verification. When checking for a referable spec, we found the change contradicts the adopted **AD-6** rule ("requesting a new link invalidates prior unconsumed tokens") stated in `ARCHITECTURE-SPINE.md`, `epics.md`, and story `1-1`. The behaviour currently lives only in code + tests.

**Evidence.**
- Code: `app/Actions/RequestMagicLink.php` (`resendOrIssue()`, `issue()` now returns the raw token), `app/Http/Controllers/Auth/MagicLinkController.php` (`store()` reads/stashes the session token; `consume()` clears it).
- Tests: `tests/Feature/Auth/MagicLinkResendTest.php` — 6 tests covering reuse, no-expiry-extension + remaining-minutes, regenerate-on-consumed, regenerate-on-expired, no-reuse-cross-session, and the exact browser sequence (request → sent page → resend). Full suite: **307 passing**.
- Live verification: request → resend in the browser reused the identical token/URL (hash unchanged, one row).

---

## Section 2 — Impact Analysis

**Epic impact.** Epic 1 (Foundation / passwordless auth) only. No new epics, no scope change. Post-MVP epics unaffected.

**Story impact.** Story `1-1-project-foundation-passwordless-magic-link-authentication` (status: done). AC4 and AC6 wording is refined; no new story required.

**Artifact conflicts (must reconcile).**
- `ARCHITECTURE-SPINE.md` — **AD-6** rule (`[ADOPTED]`): "requesting a new link invalidates prior unconsumed tokens."
- `epics.md` — AD-6 summary (line ~67) and the Epic-1 acceptance line (line ~200).
- Story `1-1` — AC4 (line ~39) and AC6 (line ~49).

**Technical impact.**
- One behavioural carve-out on the login resend path; `issue()` (the rotate path) is unchanged, so **genuinely new / cross-browser requests still rotate** — the core single-use, replay-resistant invariant is intact.
- **Security-property note:** AD-6 / story 1-1 state the raw token lives "only in the emailed URL." Reuse requires the raw token at resend time, so it is now **also retained transiently in the server-side session** (`SESSION_DRIVER=database`). The durable `login_tokens` table remains **hash-only** — no raw token at rest there, no migration.
- **Accepted boundary:** reuse is **session-scoped** (same browser). A re-request from a different device within the window has nothing stashed and rotates. Cross-device reuse would need a cache-by-email or an encrypted-token column — out of scope, noted as a future option.

---

## Section 3 — Recommended Approach

**Direct Adjustment.** Amend AD-6 and the derived epics/story wording to record the resend-reuse refinement. No rollback, no MVP change. The code is already implemented, tested, and verified — the only remaining work is the documentation reconciliation below.

**Effort:** low (doc edits). **Risk:** low (behaviour is contained + covered by tests). **Timeline:** none.

---

## Section 4 — Detailed Change Proposals

### 4.1 — Architecture · AD-6 rule
**File:** `_bmad-output/planning-artifacts/architecture/architecture-tripcast-2026-06-28/ARCHITECTURE-SPINE.md`

**OLD (excerpt of the Rule):**
> … (hashed token, expiry, `consumed_at`); requesting a new link invalidates prior unconsumed tokens for that user. **The emailed login link is itself confirm-then-POST** …

**NEW (excerpt of the Rule):**
> … (hashed token, expiry, `consumed_at`); requesting a **new** link invalidates prior unconsumed tokens for that user — **except a same-browser _resend_ within the link's lifetime, which re-emails the still-valid link unchanged (same token, original expiry — never extended) rather than rotating, so a delayed first email is not silently invalidated; the resend advertises the remaining minutes. To enable that reuse the raw token is retained only in the server-side session; the `login_tokens` table stays hash-only. A resend with no reusable link (consumed/expired/none stashed) issues a fresh one (rotating as usual).** **The emailed login link is itself confirm-then-POST** …

**Plus a new reconciliation note (matching the existing `>` note style):**
> Resend reuse (2026-07-01, `sprint-change-proposal-2026-07-01.md`): the "invalidate prior unconsumed" rule governs genuinely *new* link requests; a same-browser resend of a still-valid link **reuses** it (no rotation, no expiry extension). The raw token is retained only in the server-side session (`SESSION_DRIVER=database`) for the resend window; `login_tokens` stays hash-only. Cross-device re-request has nothing stashed and rotates. See story 1-1 AC4/AC6.

**Rationale:** Records the behavioural carve-out on the adopted decision and the raw-token-in-session property, keeping the spine the single source of truth.

### 4.2 — Epics · AD-6 summary (line ~67)
**File:** `_bmad-output/planning-artifacts/epics.md`

**OLD:** "magic-link login via single-use hashed `login_tokens` (requesting a new link invalidates prior unconsumed);"
**NEW:** "magic-link login via single-use hashed `login_tokens` (requesting a new link invalidates prior unconsumed; a **same-browser resend reuses the still-valid link unchanged**, no rotation/expiry-extension — AD-6 resend-reuse);"

### 4.3 — Epics · Epic-1 acceptance line (line ~200)
**File:** `_bmad-output/planning-artifacts/epics.md`

**OLD:** "issues a single-use, time-limited link and **invalidates prior unconsumed tokens** for that email; login is throttled per email. *(FR-3, AD-6)*"
**NEW:** "issues a single-use, time-limited link and **invalidates prior unconsumed tokens** for that email (except a **same-browser resend**, which re-emails the still-valid link unchanged — no rotation, original expiry); login is throttled per email. *(FR-3, AD-6)*"

### 4.4 — Story 1-1 · AC4 (line ~39)
**File:** `_bmad-output/implementation-artifacts/1-1-project-foundation-passwordless-magic-link-authentication.md`

**OLD:** "**Then** it issues a **single-use, time-limited** link (hashed token stored, raw token only in the emailed URL), **invalidates prior unconsumed tokens** for that email, and request is **throttled per email**."
**NEW:** "**Then** it issues a **single-use, time-limited** link (hashed token stored; the raw token appears in the emailed URL and — for resend reuse — is retained only in the **server-side session**, never in `login_tokens`), **invalidates prior unconsumed tokens** for that email, and request is **throttled per email**. *(A **same-browser resend** within the link's lifetime re-emails the **still-valid link unchanged** — same token, original expiry, never extended — rather than rotating; a resend with no reusable link issues a fresh one. See AC6.)*"

### 4.5 — Story 1-1 · AC6 (line ~49)
**File:** `_bmad-output/implementation-artifacts/1-1-project-foundation-passwordless-magic-link-authentication.md`

**OLD:** "**Then** a calm result page is shown with **one-tap resend** (no error stack, no dead end). The check-your-email interstitial shows "sent a link to {email}, expires in N min" with a resend affordance."
**NEW:** "**Then** a calm result page is shown with **one-tap resend** (no error stack, no dead end). The check-your-email interstitial shows "sent a link to {email}, expires in N min" with a resend affordance. **Resend re-emails the _still-valid_ link unchanged when one exists for that browser (original expiry, showing the remaining minutes); otherwise it issues a fresh link.** *(Covered by `tests/Feature/Auth/MagicLinkResendTest.php`.)*"

---

## Section 5 — Implementation Handoff

**Scope:** Minor — implement directly (documentation edits).
**Recipient:** Dev agent (behavioural change already shipped).
**Actions:** Apply edits 4.1–4.5.
**Success criteria:** AD-6 and the derived epics/story wording match the shipped behaviour; the raw-token-in-session property and the session-scoped (same-browser) boundary are recorded; tests remain green (307).
**Noted future option (not scheduled):** cross-device resend reuse via cache-by-email or an encrypted-token column, if ever desired.
