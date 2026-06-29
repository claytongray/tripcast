# Sprint Change Proposal — Email-confirmation gating for new signups

**Date:** 2026-06-29 · **Author:** Amelia (dev) · **Trigger:** browser testing of the Epic 1 setup flow

## 1. Issue Summary

During testing, the activation model was found under-specified. Today a new (logged-out) visitor who submits their email on `/trip` gets an **immediately active** trip — the account + trip are created and the welcome email queued on submit, and (once Epic 2 ships) digests would send **without the email ever being confirmed**. Clicking the magic link only logs them in; it does nothing to "activate" the trip.

That means a typo'd or someone-else's email starts receiving mail with no confirmation — a deliverability and abuse risk (the Epic 1 review already flagged an unthrottled welcome path). The desired behavior: **a new user's tripcast is pending until they click the link in the email**, which confirms the address and activates both the account and the trip.

## 2. Decision (the target flow)

**New / logged-out user**
1. `/trip` → "You're almost there. Enter your email to start receiving your first tripcast." → submit.
2. Account + trip are created, but the owner is **unconfirmed** → the trip is **pending** (does not send).
3. The **magic-link email is the activation step**. The check-your-email screen says plainly: *"Click the link we sent to {email} to start your tripcast."*
4. Clicking the link **confirms the email → activates the account and the trip** (and only now is the welcome email sent). They land on a success state: *"You're all set — your first forecast goes out {date}."*

**Returning / logged-in user (Epic 3 dashboard add-trip)**
- Already confirmed → entering a trip adds it **immediately and active**, no email step → the same success screen.

## 3. Model (keeps AD-5 unchanged)

Gate on **email confirmation at the user level**, not a new Trip status:

- Re-introduce **`users.email_verified_at`** (nullable; null until the first magic-link consume).
- **AD-6 (consume):** the first successful consume sets `email_verified_at = now()` — this is the confirmation. (Login for an already-confirmed user is unchanged.)
- **AD-11 (cadence):** add one clause — a trip is due only if **`owner.email_verified_at IS NOT NULL`** — so unconfirmed users' trips never send. (Epic 2 / Story 2.2 — not yet built; this is a spec note + one predicate clause.)
- **Welcome email fires when the trip becomes real-for-sending:** `CreateTrip` queues the welcome **only if the owner is already confirmed** (logged-in path). For a new (unconfirmed) signup, the welcome is queued **on the confirming consume** — so no welcome ever goes to an unconfirmed address; the only pre-confirmation email is the activation link.
- **"Pending" is a derived UI state** (trip exists + owner unconfirmed), not a Trip status value — AD-5's `active|paused|completed` machine is untouched.

## 4. Impact Analysis

| Artifact | Change | Severity |
|---|---|---|
| **ARCHITECTURE-SPINE AD-6** | First consume confirms email (`email_verified_at`) | wording + behavior |
| **ARCHITECTURE-SPINE AD-11** | Cadence predicate gains "owner confirmed" clause | wording (Epic 2) |
| **ARCHITECTURE-SPINE AD-13 / Structural Seed** | `users` gains `email_verified_at` | schema note |
| **Story 1.1** (`review`) | `users` migration adds `email_verified_at`; `User` cast; consume sets it; interstitial copy clarified (signup vs login intent) | Moderate |
| **Story 1.4** (`review`) | `CreateTrip` welcomes only confirmed owners; new-signup welcome deferred to consume | Moderate |
| **Story 1.5** (`review`) | Welcome trigger moves from "on creation" to "on activation" (creation if already confirmed, else confirmation) | Moderate |
| **Story 2.2** (`backlog`) | Cadence predicate includes `email_verified_at` (spec note for when it's built) | Note only |
| **Epic 3 / Story 3.x** (`backlog`) | Polished "trip added — first forecast in N days" success screen; dashboard shows pending/confirm state | Note only |
| **EXPERIENCE.md** | Add the confirmation requirement + interstitial/success microcopy | wording |

**Code touched now:** `database/migrations/...users`, `app/Models/User.php`, `app/Http/Controllers/Auth/MagicLinkController@consume`, `app/Actions/CreateTrip.php`, `app/Actions/RequestMagicLink.php` (no change expected), `app/Http/Controllers/LandingController@createTrip`, `resources/js/pages/auth/CheckEmail.vue` (intent-aware copy), `resources/js/pages/Dashboard.vue` (success/all-set copy for first confirmation), tests. No new external deps.

## 5. Recommended Approach — Direct Adjustment (Moderate)

Implement now within the existing stories (all in `review`), update the two ADs + EXPERIENCE.md, and leave Epic 2/3 notes for when those stories are built. No rollback, no MVP scope change.

**Sub-decisions for sign-off:**
- **A. Welcome timing** — fire on confirmation for new users, on creation for already-confirmed (logged-in) users. *(Recommended; avoids welcoming unconfirmed addresses.)*
- **B. Migration** — add `email_verified_at` by **editing the Story 1.1 `users` migration** (no prod data; keeps the base schema clean) rather than a follow-up migration. *(Recommended; requires `migrate:fresh` locally.)*
- **C. Success screen** — Epic 1 lands a confirmed new user on the placeholder **Dashboard** with an "all set — first forecast goes out {date}" message; the polished, reusable success screen is **Epic 3** with the real dashboard. *(Recommended; keeps Epic 1 scoped.)*

## 6. Handoff

**Scope: Moderate** → Developer (Amelia) implements directly across stories 1.1/1.4/1.5 + the two AD edits + EXPERIENCE.md, with notes added to Story 2.2 and Epic 3. Re-run the full gate suite; the affected stories stay in `review`.

**Success criteria:** a new signup's trip does not send until the magic link is clicked; the check-email screen states the click requirement; the welcome email only goes to confirmed addresses; logged-in adds remain immediate; AD-6/AD-11 + EXPERIENCE.md reflect the rule; all gates green.
