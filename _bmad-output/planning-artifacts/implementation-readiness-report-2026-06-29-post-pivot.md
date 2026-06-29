---
title: Implementation Readiness Report — Post-Monetization-Pivot Re-check
project: tripcast
created: 2026-06-29
author: John (PM, via bmad-check-implementation-readiness)
stepsCompleted: [step-01-document-discovery, step-02-cross-artifact-traceability, step-03-verdict]
supersedes: implementation-readiness-report-2026-06-29.md (pre-pivot)
scope: Validate PRD ↔ SPEC ↔ Architecture ↔ Epics alignment after the free + affiliate monetization pivot
---

# Implementation Readiness — Tripcast v1 (Post-Pivot Re-check)

## Verdict

**🟡 READY TO PROCEED — with one tracked, non-blocking gap.**

The monetization pivot (free + affiliate) landed cleanly and consistently across the **PRD, addendum, SPEC, glossary, Architecture spine, and Epics**. All four core contract layers agree: Pay Intent / FR-14 / "5 paid members" are gone; FR-17 (weather-keyed entitlement-gated promo slot) and FR-18 (affiliate click attribution) are present and traced end-to-end; the free-tier cap is reframed to a 3-active-trip cost-control limit; AD-18/AD-19 are defined and referenced; entities use `promo_events`, not `pay_intents`.

The single misalignment is the **UX spine** (`DESIGN.md` / `EXPERIENCE.md`), which still describes the removed pay-intent screen and lacks the promo slot. This does **not** block epic/story work (the `epics.md` UX-DR inventory was already aligned as an interim), but it must be resolved by UX (Sally) before UX-driven implementation of the digest promo and the dashboard cap message.

## Document Inventory

| Artifact | Path | Status |
| --- | --- | --- |
| PRD | `prds/prd-tripcast-2026-06-28/prd.md` | ✅ updated 2026-06-29 |
| PRD addendum | `prds/prd-tripcast-2026-06-28/addendum.md` | ✅ updated |
| SPEC | `specs/spec-tripcast/SPEC.md` | ✅ updated |
| SPEC glossary | `specs/spec-tripcast/glossary.md` | ✅ updated |
| Architecture spine | `architecture/architecture-tripcast-2026-06-28/ARCHITECTURE-SPINE.md` | ✅ updated, lint PASS |
| Epics | `epics.md` | ✅ step-02 (Epic List + Coverage Map) |
| UX — DESIGN | `ux-designs/ux-tripcast-2026-06-28/DESIGN.md` | 🔴 stale (pre-pivot) |
| UX — EXPERIENCE | `ux-designs/ux-tripcast-2026-06-28/EXPERIENCE.md` | 🔴 stale (pre-pivot) |
| Sprint-change proposal | `sprint-change-proposal-2026-06-29.md` | ✅ record (incl. §7 session-2) |

## Cross-Artifact Traceability

### FR set (FR-14 retired; FR-17/FR-18 added)

| FR | PRD | SPEC | Arch `binds` | Epics coverage | Epic |
| --- | --- | --- | --- | --- | --- |
| FR-1…FR-13 | ✅ | ✅ | ✅ | ✅ | 1–3 |
| ~~FR-14 (Pay Intent)~~ | retired | retired | absent | retired | — |
| FR-15 Forecast history | ✅ | ✅ | ✅ | ✅ | 4 |
| FR-16 AI narration | ✅ | ✅ | ✅ | ✅ | 4 |
| **FR-17** Promo slot | ✅ | ✅ | ✅ | ✅ | 5 |
| **FR-18** Click attribution | ✅ | ✅ | ✅ | ✅ | 5 |

`binds:` = `[FR-1…FR-13, FR-15, FR-16, FR-17, FR-18]` — matches the live FR set exactly. **No orphans, no gaps.**

### Architecture decisions

- **AD-18** (PromoProvider port, render-slot only, off the delivery path) — present in SPEC, Arch, Epics.
- **AD-19** (`plan` entitlement predicate `shouldShowPromo`) — present in SPEC, Arch, Epics.
- **AD-6** amended (promo-click signed GET redirect; pay-intent dropped from the POST list) — consistent.
- **AD-15** rewritten (3-trip cost-control, plain limit, no PayIntent) — consistent across all four.
- Spine `lint_spine.py`: **0 findings**; AD-1…19 contiguous; mermaid valid.

### Entities / data model

- Live artifacts reference `promo_events` / `PromoEvent` (14 refs) and **zero** `pay_intents` / `PayIntent` except intentional negations ("no `PayIntent` model").
- ERD: `PAY_INTENT` removed, `PROMO_EVENT { trip_id, user_id, send_date, promo_slug, event, created_at }` added with `TRIP ||--o{ PROMO_EVENT`; `users.plan` = `free|ad_free`.

### Residual-reference scan

All `Pay Intent` / `FR-14` / `5 paid members` hits in live artifacts are **intentional** — "REMOVED/RESOLVED" open-question markers or explicit negations documenting the removal. Remaining hits elsewhere are confined to append-only `.memlog.md` audit trails and the original pre-pivot `review-rubric.md` / `reconcile-brief.md` (point-in-time records, correctly preserved).

## Findings

### 🔴 R-1 (High, non-blocking for epics) — UX spine is pre-pivot

`ux-designs/ux-tripcast-2026-06-28/DESIGN.md` and `EXPERIENCE.md` still specify the **pay-intent / upgrade screen** (DESIGN §"Pay-intent (upgrade) screen"; EXPERIENCE flows, copy table, and journey step ~227) and contain **no** affiliate-promo or `ad_free` content.

- **Impact:** UX-driven implementation of the FR-17 promo slot, the FR-12 "trip limit reached" message, and removal of the upgrade screen would follow a stale contract.
- **Owner:** UX (Sally) — `bmad-ux` update.
- **Required edits:** add the weather-keyed promo slot + mandatory affiliate disclosure to the digest template (UX-DR5); remove the pay-intent/upgrade screen (UX-DR12) and its copy/flows; update microcopy (UX-DR16) — drop pay-intent strings, add promo label/disclosure + the calm "trip limit reached" message.
- **Interim mitigation:** the `epics.md` UX-DR inventory (UX-DR5/12/16) is already aligned, so epic/story work is unblocked.

### ✅ R-2 — Epic coverage complete

Five epics; all 17 live FRs mapped exactly once; Epics 4 (narration) and 5 (monetization) correctly sequenced on the shared `SendTripDigest` enhancement seam.

### ✅ R-3 — Core contract layers consistent

PRD ↔ SPEC ↔ Architecture ↔ Epics agree on scope, FRs, cap, entitlement, and entities post-pivot.

## Recommendation

1. **Proceed** to story creation (epics step-03) and/or UX update — both are unblocked.
2. **Sequence the UX update (R-1) before** implementing the digest promo / dashboard cap UI, so UX-driven work builds on the corrected contract.
3. No core-contract blockers remain; the pivot is implementation-ready at the PRD/SPEC/Architecture/Epics level.
