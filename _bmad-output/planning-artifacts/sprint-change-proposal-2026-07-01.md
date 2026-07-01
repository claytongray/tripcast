# Sprint Change Proposal — 2026-07-01

**Author:** Clayton (via Developer / Correct Course)
**Date:** 2026-07-01
**Mode:** Batch
**Scope classification:** Moderate (backlog reorganization — one new MVP epic + a skeleton follow-on epic; all stories new/backlog)

---

## Section 1 — Issue Summary

Approaching MVP launch, the project needs **observability** — a way to read whether the product
is working and healthy (acquisition, activation, email deliverability, engagement, monetization)
without touching the database. Today only a single read-only monitoring page exists (`/admin`,
Story 3.4 / FR-13): it lists every trip and its per-send email log, but has **no aggregate
metrics, no user view, no monetization analytics, and no navigation**.

A full admin-panel plan was designed and approved this session (plan file:
`~/.claude/plans/harmonic-plotting-hopcroft.md`). It introduces net-new scope that no current FR
or epic covers, so the BMad record (FR inventory, FR Coverage Map, epic list, `sprint-status.yaml`)
needs to be reconciled before build — the same reconciliation used to add Epic 6.

**Issue type:** New requirements emerged (feature scope added). Not a technical failure, rollback,
or MVP reduction.

**Two epics proposed:**

| Epic | Title | Ship | Branch |
| --- | --- | --- | --- |
| **7** | Admin Observability Panel | MVP (now) | `epic-7-admin-panel` (off `frontend-polish`) |
| **8** | Sponsored Catalog & Weather Mapping | Follow-on (skeleton only) | `epic-8-sponsored-catalog` (later) |

---

## Section 2 — Impact Analysis

### Epic impact
- **No existing epic is invalidated or blocked.** Epics 1–6 stand.
- **A new MVP epic is warranted.** The observability work is cohesive (product-health signals for
  launch) and extends — does not belong inside — Epic 3's basic admin monitoring. → **New Epic 7:
  Admin Observability Panel.** It **folds the existing monitoring view (FR-13/Story 3.4) in as one
  section**.
- **A second epic is registered as a skeleton.** The sponsored-catalog→weather mapping is a large,
  separable build (DB-backed catalog + new provider + CRUD UI) explicitly scoped to its own branch
  later. → **New Epic 8: Sponsored Catalog & Weather Mapping** (stories sketched, not detailed).
- Sequencing unaffected: Epic 7 reads existing, hardened data (`users`, `trips`, `email_logs`,
  `feedback`, `promo_events`, `sample_requests`, the `digests:run` liveness signal) under the
  existing admin Gate; it can be built independently. Epic 8 extends the Epic 5 promo seam.

### Requirements (FR) impact
- **New FRs (Epic 7):** FR-22 (observability panel & overview metrics), FR-23 (users explorer,
  read-only), FR-24 (email health & daily-run liveness), FR-25 (monetization & acquisition
  analytics).
- **New FR (Epic 8):** FR-26 (admin-managed sponsored catalog & weather mapping).
- **Extends (not rewords) FR-13:** the existing admin monitoring becomes one section of the panel;
  FR-13 wording is unchanged.
- FR Coverage Map gains five rows + Epics 7 and 8.

### Artifact conflicts
- **epics.md** — needs the 5 new FRs in the inventory, 5 rows + Epics 7/8 in the coverage map,
  Epics 7/8 in the epic list, an Epic 7 detailed stories section (7.1–7.8), and an Epic 8 skeleton
  section. **(Edited on accepting this proposal.)**
- **sprint-status.yaml** — needs `epic-7` (+ 7.1–7.8) and an `epic-8` skeleton block.
  **(Edited on accepting this proposal.)**
- **PRD** (`prds/prd-tripcast-2026-06-28/`) and **SPEC** (`specs/spec-tripcast/SPEC.md`) —
  recommended non-blocking follow-up: append FR-22–26 to the PRD `addendum.md` for completeness,
  as done for FR-19/20/21.
- **Architecture spine** — Epic 7 introduces **no new architectural decisions**; it reuses AD-12
  (admin Gate), AD-9 (EmailLog source of truth), AD-14 (run liveness), AD-18 (`promo_events`).
  Epic 8 adds one new table (`PromoItem`) and a second `PromoProvider` implementation
  (`DatabasePromoProvider`) behind the existing AD-18 port — recorded when Epic 8 is detailed.
- **UX** — the panel is a new admin surface built in the existing design-token system; **phone-first
  is a cross-cutting acceptance criterion** on every Epic 7 story (tiles stack, tables
  scroll/collapse, charts are simple full-width). No UX-DR conflict.

### Technical impact
- Nearly all metrics are **already queryable** from existing tables — no new tracking infra for MVP.
- **Email opens/bounces are explicitly out of scope** — not trackable on the current `log` mail
  driver. Deferred fast-follow via an ESP (Postmark). FR-24 covers **send-health**, not opens.
- **One new frontend dependency:** `vue-chartjs` + `chart.js` (first charting lib), added in Story
  7.2. Approved by the user.
- New branch `epic-7-admin-panel` off `frontend-polish` (which carries the data dependencies not
  yet on `main`).

---

## Section 3 — Recommended Approach

**Option 1 — Direct Adjustment (add new epics + stories within the existing plan). — SELECTED.**

- **Effort:** Low for the docs reconciliation; Epic 7 build is moderate (8 stories, mostly
  read-only controllers + a metrics service + phone-first Vue sections).
- **Risk:** Low (no rollback, no rework, no changes to Epics 1–6; reuses hardened data + the admin
  Gate).
- **Rationale:** The work is fully specified in the approved plan; a new Epic 7 cleanly homes the
  observability scope and keeps the "every live FR maps to one epic" invariant true. Epic 8 is
  registered as a skeleton so the coverage map stays honest without over-detailing future work.

**Option 2 — Rollback:** Not applicable (nothing to undo).
**Option 3 — MVP review:** Not applicable (scope is additive and intentionally MVP-bounded — opens
deferred, catalog CRUD split to Epic 8).

---

## Section 4 — Detailed Change Proposals

### 4.1 New FRs (epics.md → Functional Requirements inventory)

**ADD after FR-21:**

- **FR-22: Admin observability panel & overview metrics** — Under the single admin Gate (AD-12), a
  multi-section, **phone-first** admin panel presents product-health signals with an Overview of
  KPI tiles + trend charts (signups, confirmation rate, trips created, active-trip status mix,
  sends today + success rate, promo CTR, sample requests), computed from existing data over
  selectable windows (7/30/90 days, app tz). Read-only. Folds the existing admin monitoring view
  (FR-13) in as one section under a shared admin nav.
- **FR-23: Admin users explorer (read-only)** — An admin can browse a paginated, searchable list of
  all Users with plan, confirmation state, signup date, active-trip count, last login
  (`login_tokens.consumed_at`), and whether they've requested a sample. Read-only for MVP — no
  impersonation or mutation.
- **FR-24: Admin email health & daily-run liveness** — An admin section surfaces send-health from
  `email_logs` (sends/day, sent-vs-failed rate, failures grouped by reason, stuck-`sending` count)
  and the daily-run liveness signal (AD-14: last run healthy?, due vs dispatched, duration). Email
  opens/bounces are out of scope for MVP (require an ESP — deferred).
- **FR-25: Admin monetization & acquisition analytics** — An admin section reports sponsored-link
  performance (impressions, clicks, CTR by `promo_slug` and weather profile over a date range, from
  `promo_events`) and sample-acquisition (sample_requests over time, top destinations,
  sample→confirmed-signup conversion). Read-only.
- **FR-26: Admin-managed sponsored catalog & weather mapping** *(Epic 8, skeleton)* — The static
  weather-keyed promo catalog becomes admin-editable and DB-backed (`PromoItem`), served by a new
  `DatabasePromoProvider` implementing the existing PromoProvider port (preserving deterministic
  `send_date` rotation and the fallback). Admins manage items grouped by weather profile, a
  date-ranged **Featured** override, and an **Essentials** fallback pool used when weather is
  neutral (`mild`) or early/low-signal (<2 forecast days). Selection precedence:
  Featured → weather profile → Essentials.

### 4.2 FR Coverage Map (epics.md) — ADD five rows

```
| FR-22 Admin observability panel & overview metrics | Epic 7 | Phone-first admin panel + KPI/trend overview (AD-12) |
| FR-23 Admin users explorer (read-only) | Epic 7 | Paginated/searchable user list + activity |
| FR-24 Admin email health & daily-run liveness | Epic 7 | email_logs send-health + digests:run liveness (AD-9, AD-14) |
| FR-25 Admin monetization & acquisition analytics | Epic 7 | promo_events CTR + sample_requests funnel (AD-18) |
| FR-26 Admin-managed sponsored catalog & weather mapping | Epic 8 | DB-backed catalog + DatabasePromoProvider + Featured/Essentials (AD-18) |
```

### 4.3 Epic List (epics.md) — ADD Epics 7 and 8

> ### Epic 7: Admin Observability Panel
> The builder can see, from a phone, whether the beta is working and healthy — acquisition,
> activation, email deliverability, engagement, and monetization — without touching the database,
> all under the single admin Gate. A multi-section, phone-first panel (Overview, Users, Emails,
> Promos, Samples) that folds the existing trip/send monitoring (FR-13) in as one section. Read-only.
> **FRs covered:** FR-22, FR-23, FR-24, FR-25 (extends FR-13)
> **Anchored by:** AD-12 (admin Gate), AD-9 (EmailLog source of truth), AD-14 (run liveness), AD-18 (promo_events)
>
> ### Epic 8: Sponsored Catalog & Weather Mapping *(skeleton — follow-on)*
> Turn the static weather-keyed promo catalog into an admin-managed, DB-backed system: item CRUD
> grouped by weather profile, a date-ranged Featured override, and an Essentials fallback for
> neutral/early conditions — served by a new `DatabasePromoProvider` behind the existing promo port.
> **FRs covered:** FR-26
> **Anchored by:** AD-18 (PromoProvider port), AD-19 (entitlement) · **New table:** `PromoItem` · **Branch:** `epic-8-sponsored-catalog`

### 4.4 Epics & Stories (epics.md) — ADD Epic 7 section (8 stories) + Epic 8 skeleton

Epic 7 cross-cutting ACs (apply to every story): **phone-first** (tiles stack to one column,
tables scroll/collapse, charts simple + full-width); **read-only** (no mutations); **guarded by the
existing `admin` Gate** (guests → login, non-admins → 403).

- **Story 7.1: Admin shell, tab nav & route group** *(FR-22)* — `/admin/*` route group under
  `auth`+`can:admin`; a lightweight phone-first tab nav (Overview/Users/Emails/Promos/Samples/
  Monitoring); the current monitoring page folded in as `admin.monitoring`; "Admin" entry shown
  only to admins.
- **Story 7.2: Metrics service + charting foundation** *(FR-22)* — `MetricsService` for efficient
  date-bucketed aggregates (guard N+1/unbounded scans; 7/30/90-day windows, app tz); add
  `vue-chartjs`+`chart.js`; reusable `KpiTile` + `TrendChart` components.
- **Story 7.3: Overview dashboard** *(FR-22)* — KPI tiles + trend charts for signups, confirmation
  rate, trips, active-trip mix, sends + success rate, CTR, samples.
- **Story 7.4: Users explorer (read-only)** *(FR-23)* — paginated/searchable user list with plan,
  confirmed?, created, active-trip count, last login, sample-requested?.
- **Story 7.5: Email health & liveness** *(FR-24)* — sends/day, success/failure rate, failures by
  reason, stuck-`sending`, and the daily-run liveness panel; opens/bounces placeholder (deferred).
- **Story 7.6: Promo analytics** *(FR-25)* — impressions/clicks/CTR by promo and weather profile
  over a date range, from `promo_events`.
- **Story 7.7: Sample activity & acquisition** *(FR-25)* — sample_requests over time, top
  destinations, sample→confirmed-signup conversion.
- **Story 7.8: Admin demo seeder (dev harness)** — seeds ~90 days of realistic
  users/trips/email_logs/feedback/promo_events/sample_requests so the panel renders meaningfully
  locally; never run in production.

Epic 8 skeleton: Stories **8.1** DB-backed `PromoItem` catalog (+ seed from config), **8.2**
`DatabasePromoProvider` (port impl, deterministic rotation, fallback), **8.3** catalog CRUD UI,
**8.4** weather mapping + Featured/Essentials fallback rules, **8.5** per-item performance
(reuses 7.6). Detailed ACs to be written when Epic 8 is picked up.

### 4.5 sprint-status.yaml — ADD epic-7 block + epic-8 skeleton

```yaml
  # ---- Epic 7: Admin Observability Panel (added 2026-07-01, sprint-change-proposal-2026-07-01) ----
  epic-7: in-progress
  7-1-admin-shell-tab-nav-route-group: backlog
  7-2-metrics-service-charting-foundation: backlog
  7-3-overview-dashboard: backlog
  7-4-users-explorer: backlog
  7-5-email-health-and-liveness: backlog
  7-6-promo-analytics: backlog
  7-7-sample-activity-and-acquisition: backlog
  7-8-admin-demo-seeder: backlog
  epic-7-retrospective: optional

  # ---- Epic 8: Sponsored Catalog & Weather Mapping (skeleton — follow-on branch) ----
  epic-8: backlog
  8-1-db-backed-promo-item-catalog: backlog
  8-2-database-promo-provider: backlog
  8-3-catalog-crud-ui: backlog
  8-4-weather-mapping-featured-essentials-fallback: backlog
  8-5-per-item-performance: backlog
  epic-8-retrospective: optional
```

---

## Section 5 — Implementation Handoff

**Scope: Moderate — backlog reorganization + a new MVP build.**

1. **Apply artifact edits (this proposal):** epics.md (5 FRs + coverage rows + Epics 7/8 list &
   Epic 7 stories + Epic 8 skeleton) and sprint-status.yaml (epic-7 + epic-8 blocks). — On accept.
2. **Create story artifacts** via `bmad-create-story` for 7.1–7.8 in `implementation-artifacts/`.
3. **Build Epic 7** story-by-story via `bmad-dev-story` on branch `epic-7-admin-panel`, each with
   Pest tests (403 for non-admins on every `/admin/*` route; aggregate correctness; no N+1) and a
   review pass.
4. **Follow-up (non-blocking):** append FR-22–26 to the PRD `addendum.md`.
5. **Later:** detail + build Epic 8 on its own branch; record `PromoItem` + `DatabasePromoProvider`
   in the architecture spine at that time.

**Success criteria:** the FR Coverage Map lists FR-22–25 → Epic 7 and FR-26 → Epic 8; sprint-status
shows epic-7 (8 stories) and the epic-8 skeleton; story artifacts exist for 7.1–7.8; the panel is
phone-first, read-only, and reads only existing data under the admin Gate.
