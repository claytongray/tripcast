# Sprint Change Proposal — 2026-06-30

**Author:** Clayton (via Developer / Correct Course)
**Date:** 2026-06-30
**Mode:** Batch
**Scope classification:** Moderate (backlog reorganization — new epic + stories; three items already implemented, one ready-for-dev)

---

## Section 1 — Issue Summary

Four pieces of product work were designed and (mostly) built this session through a lightweight design flow (`docs/superpowers/specs` + `docs/superpowers/plans`), **outside the BMad planning artifacts**. They introduce net-new scope that no current FR or epic covers, so the BMad record — the FR inventory, the FR Coverage Map (which asserts *"every live FR maps to exactly one epic"*), the epic list, and `sprint-status.yaml` — no longer reflects reality.

**How it was discovered:** while deciding how to hand off the sample-tripcast implementation plan, we recognized the project runs on BMad as its source of truth (code references `FR-*`/`AD-*`; readiness reports and retros key off these artifacts), yet this session's work lived only in the parallel superpowers docs.

**The four items:**

| Item | State | Design source |
| --- | --- | --- |
| Authenticated `/` → dashboard redirect | Shipped (commit `6c84d1e`) | `docs/superpowers/specs/2026-06-30-redirect-authenticated-home-to-dashboard-design.md` |
| Account settings page (temp unit, email, logout) | Shipped (commit `84359d4`) | `.../2026-06-30-user-settings-page-design.md` |
| Dashboard per-trip next-send status | Shipped (commit `ac6ffe1`) | `.../2026-06-30-dashboard-next-send-status-design.md` |
| Public sample tripcast (MVP) | Specced + planned, not built | `.../2026-06-30-sample-tripcast-mvp-design.md` + `docs/superpowers/plans/2026-06-30-sample-tripcast-mvp.md` |

**Issue type:** New requirements emerged (feature scope added mid-execution). Not a technical failure, rollback, or MVP reduction.

---

## Section 2 — Impact Analysis

### Epic impact
- **No existing epic is invalidated or blocked.** Epics 1–5 stand.
- **A new epic is warranted.** The four items are cohesive (returning-user experience, account self-management, and top-of-funnel acquisition) and do not belong to any of Epics 1–5. → **New Epic 6: Growth & Account.**
- Epic sequencing unaffected: Epic 6 extends existing, hardened seams (dashboard from Epic 3; magic-link auth from Epic 1; weather port from Epic 2) and can be built independently.

### Requirements (FR) impact
- **New FRs:** FR-19 (account settings), FR-20 (dashboard next-send status), FR-21 (public sample tripcast).
- **Clarified FR:** FR-4 (persistent sessions) — the returning-user experience now includes an explicit `/`→dashboard redirect for authenticated visitors. Captured as a story under Epic 6, no wording change required to FR-4 itself.
- FR Coverage Map gains three rows + Epic 6.

### Artifact conflicts
- **epics.md** — needs the 3 new FRs in the inventory, 3 rows + Epic 6 in the coverage map, Epic 6 in the epic list, and an Epic 6 detailed stories section. **(Edited in this proposal.)**
- **sprint-status.yaml** — needs `epic-6` and its four stories. **(Edited in this proposal.)**
- **PRD** (`planning-artifacts/prds/prd-tripcast-2026-06-28/`) and **SPEC** (`specs/spec-tripcast/SPEC.md`) — restate requirements at a higher level. Recommended follow-up: append FR-19/20/21 to the PRD `addendum.md` for completeness. Listed here as handoff; not blocking, since epics.md is the operational requirements + coverage record the build tracks against.
- **Architecture spine** — no new architectural decisions; Epic 6 reuses existing ports/ADs (AD-1 weather, AD-6 magic-link/signed, AD-7 send clock, AD-11 cadence authority, AD-13 account email suppression). One new table, `sample_requests` (acquisition tracking), analogous to existing singular models — no spine change.
- **UX** — settings page, dashboard beacon/next-send line, and landing "Send me a sample" modal are new surfaces built in the existing design-token system; no UX-DR conflict.

### Technical impact
- Three items are already implemented, tested (full suite green at each commit), and Pint/lint/build clean.
- The sample tripcast has a complete 7-task TDD implementation plan; one new table (`sample_requests`), one refactor extracting `RequestMagicLink::issue()` and a shared throttle trait, a `SampleForecast` service (cached-live + static fallback), a `SampleDigestMail`, a controller/route, and the landing modal.

---

## Section 3 — Recommended Approach

**Option 1 — Direct Adjustment (add a new epic + stories within the existing plan). — SELECTED.**

- **Effort:** Low (docs only; three items already coded, one already planned).
- **Risk:** Low (no rollback, no rework, no changes to Epics 1–5).
- **Rationale:** The work is done or fully specified; this is a documentation-reconciliation, not a replan. A new Epic 6 cleanly homes cohesive growth/account scope and keeps the "every FR maps to one epic" invariant true.

**Option 2 — Rollback:** Not applicable (nothing to undo; the shipped work is wanted).
**Option 3 — MVP review:** Not applicable (scope is additive, not over-budget).

**Going-forward recommendation:** treat **BMad as the single source of truth**; keep the superpowers specs/plans as design inputs/scratch, and capture new features in BMad (correct-course for scope, create-story for units) rather than maintaining two parallel systems.

---

## Section 4 — Detailed Change Proposals

### 4.1 New FRs (epics.md → Functional Requirements inventory)

**ADD after FR-16:**

- **FR-19: Account settings** — An authenticated User has a Settings page to change their temperature unit (°F/°C), view their email (read-only), and log out. The unit change persists to the account and is reflected in subsequent digests. Delete-account, email change, and billing are deferred.
- **FR-20: Dashboard next-send status** — Each upcoming trip card shows when its next forecast will send: a live "sending" beacon + "this/tomorrow morning" while the Trip is within its Forecast Window, or "first forecast in N days · <date>" before the window opens — derived from the single cadence authority (AD-11) on the send clock (AD-7).
- **FR-21: Public sample tripcast** — A visitor can request a sample tripcast by email from the landing page; a cached-live sample digest for a fixed demo destination (Reykjavik) is sent, whose "Get started" CTA is a Magic Link that confirms/creates the account and lands them on the dashboard. Each accepted request is recorded (`sample_requests`) for acquisition tracking. Throttled on the shared magic-link limiter.

### 4.2 FR Coverage Map (epics.md) — ADD three rows

```
| FR-19 Account settings | Epic 6 | Settings page: temp unit, email, logout |
| FR-20 Dashboard next-send status | Epic 6 | Per-trip beacon + next-send line (AD-11) |
| FR-21 Public sample tripcast | Epic 6 | Landing sample email → magic-link get-started + tracking |
```

### 4.3 Epic List (epics.md) — ADD Epic 6

> ### Epic 6: Growth & Account
> Returning users land straight on their dashboard, self-manage account preferences (temperature unit), and see when each trip's next forecast will arrive; new visitors can experience the product before committing via an emailed sample whose "Get started" link becomes their account.
> **FRs covered:** FR-19, FR-20, FR-21 (and an FR-4 clarification: authenticated `/` → dashboard)
> **Anchored by:** AD-1 (weather port, sample), AD-6 (magic-link get-started + account), AD-7 (send clock), AD-11 (cadence authority for next-send), AD-13 (account email preference)

### 4.4 Epics & Stories (epics.md) — ADD Epic 6 section with four stories

- **Story 6.1: Authenticated landing redirect to dashboard** *(FR-4 clarification)* — shipped.
- **Story 6.2: Account settings page** *(FR-19)* — shipped.
- **Story 6.3: Dashboard per-trip next-send status** *(FR-20)* — shipped.
- **Story 6.4: Public sample tripcast (MVP)** *(FR-21)* — ready-for-dev (plan: `docs/superpowers/plans/2026-06-30-sample-tripcast-mvp.md`).

(Full Given/When/Then ACs written into epics.md.)

### 4.5 sprint-status.yaml — ADD epic-6 block

```yaml
  # ---- Epic 6: Growth & Account ----
  epic-6: in-progress
  6-1-authenticated-landing-redirect-to-dashboard: review
  6-2-account-settings-page: review
  6-3-dashboard-per-trip-next-send-status: review
  6-4-public-sample-tripcast: ready-for-dev
  epic-6-retrospective: optional
```

(6.1–6.3 marked `review` — implemented + committed, consistent with how the other shipped-but-not-yet-review-signed stories are tracked; 6.4 `ready-for-dev` — plan complete, not built.)

---

## Section 5 — Implementation Handoff

**Scope: Moderate — backlog reorganization.**

1. **Apply artifact edits (this proposal):** epics.md (FRs + coverage map + Epic 6 list & stories) and sprint-status.yaml. — Done as part of accepting this proposal.
2. **Create story artifacts** via `bmad-create-story` for 6.1–6.4 in `implementation-artifacts/` (6.1–6.3 as retro-capture referencing the shipped commits + superpowers specs; 6.4 seeded from the existing plan).
3. **Build 6.4** from `docs/superpowers/plans/2026-06-30-sample-tripcast-mvp.md` (7 TDD tasks).
4. **Follow-up (non-blocking):** append FR-19/20/21 to the PRD `addendum.md`.

**Success criteria:** the FR Coverage Map lists FR-19/20/21 → Epic 6; sprint-status shows epic-6 with its four stories; story artifacts exist for each; a later readiness report / retro sees the growth/account scope.
