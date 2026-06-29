---
title: Sprint Change Proposal — Monetization Pivot to Free + Affiliate
project: tripcast
created: 2026-06-29
updated: 2026-06-29 (session 2 — affiliate source & selection decisions, see §7)
author: Clayton (via Correct Course)
status: draft-for-approval
scope_classification: Major (business-model + success-metric change; architecture moderate)
---

# Sprint Change Proposal — Tripcast Monetization Pivot

## 1. Issue Summary

**Trigger (strategic pivot).** Tripcast's planning artifacts encode a **paid-subscription** monetization thesis: a free-tier trip cap that drives a "Pay Intent" upsell, with the headline business goal of converting to **5 paid members** once Stripe ships. Clayton no longer believes users will pay for the core service and wants to pivot the **primary** model to **free, supported by lightweight affiliate/ads**, while keeping an **optional ad-free paid tier** architecturally possible (a hedge, not a v1 build).

**Discovered:** during epic & story generation (a separate session). Critically, `epics.md` is still at `step-01-validate-prerequisites` — the **Epic List** and **FR Coverage Map** are unrendered `{{placeholders}}`, so no epic breakdown exists yet to roll back. The fix lands cleanly upstream.

**Decisions locked (this session):**
1. **Free-tier cap → raised to 3 active trips**, repurposed as pure **cost-control** (decoupled from monetization).
2. **v1 promo fill = affiliate links first** (fits the calm-concierge voice; no ad-network integration).
3. **Pay Intent dropped from v1.** Paid tier stays architecture-ready via entitlement, but has **no in-product surface** in v1.

**Central tension flagged:** the PRD's Aesthetic & Tone lists *"ad-heavy forecast sites"* as an explicit anti-reference. Resolution is not to ignore it but to **bound** monetization with a hard architectural guardrail (one native unit, off the delivery path, never in subject/preheader, density-capped) so Tripcast never *becomes* the anti-reference.

## 2. Impact Analysis

### Epic Impact
- No epics generated yet → **no rollback needed**. The in-flight epic session must be **paused** and **regenerated** after these upstream edits land, so it builds from the corrected requirements.

### Artifact Conflicts
| Artifact | Sections affected | Nature |
|---|---|---|
| **PRD** (`prd-tripcast-2026-06-28/prd.md`) | §1 Vision, §2.1 JTBD, §3 Glossary (Plan/Pay Intent/Free-Tier Cap), §4.5 FR-12, **§4.7 removed**, **new §4.9 promo**, §5 Non-Goals, §6 MVP Scope, §7 Success Metrics, §8 Open Q's, Aesthetic & Tone, IA | Moderate |
| **SPEC** (`specs/spec-tripcast/SPEC.md`) | Why, FR-12, **FR-14 removed**, **new FR-17/18**, Constraints, Non-goals, Success signal, Assumptions, Open Questions | Moderate |
| **Architecture** (`ARCHITECTURE-SPINE.md`) | `binds`, **AD-6** (drop pay-intent, add promo-click), **AD-15** (cap reframe), **new AD-18/AD-19**, structural seed (drop `PayIntent`, add `promo_events`, `plan` meaningful), Capability→Architecture map, Deferred | Moderate |
| **epics.md** | Requirements inventory (FR-14, AD-15 lines) + regenerate Epic List / Coverage Map | Regenerate |
| **UX** (`DESIGN.md`/`EXPERIENCE.md` — referenced but not located on disk) | UX-DR5 digest template (add promo slot), UX-DR12 pay-intent screen (remove), UX-DR16 microcopy (remove pay-intent strings, add promo + limit copy) | Light — confirm files |

### Technical Impact
- New `Promo` port + affiliate adapter (mirrors `Narrator`/AD-17).
- New `promo_events` table (impression/click), signed redirect route for attribution (reuses AD-6 pattern).
- `plan` field promoted from decorative stub → live entitlement switch.
- `PayIntent` model + `pay_intents` table + both pay-intent surfaces **removed** from v1.

## 3. Recommended Approach

**Hybrid: upstream amendment + downstream regeneration.** Direct rollback (Option 2) is N/A (no built work). PRD MVP is revised (Option 3) and stories are regenerated, not hand-patched (Option 1 is moot pre-generation).

- **Effort:** Medium. **Risk:** Low-Medium (main risk is aesthetic — mitigated by AD-18 guardrail).
- **Sequencing:** (a) pause epic session → (b) PM amends PRD + SPEC + metrics → (c) Architect amends spine (AD-6/15 + new AD-18/19 + entities) → (d) regenerate epics → (e) re-run implementation-readiness.

## 4. Detailed Change Proposals

### 4.1 PRD

**§1 Vision —**
- OLD: *"Longer term the ambition is viral growth and a paid product."*
- NEW: *"Longer term the ambition is viral growth, sustained by lightweight affiliate/ad revenue woven calmly into the digest, with an optional ad-free paid tier as upside."*
- OLD (in §1): *"...keep reading it through a whole trip, react positively, and signal they'd pay."*
- NEW: *"...keep reading it through a whole trip, react positively, and engage with the calm affiliate recommendations woven in."*

**§2.1 Builder's JTBD —**
- OLD: *"Validate cheaply whether ambient trip-weather email is a product people want and will pay for."*
- NEW: *"Validate cheaply whether ambient trip-weather email is a product people want and engage with enough to sustain on affiliate/ad revenue — with an optional ad-free paid tier as future upside."*

**§3 Glossary —**
- **Plan:** OLD *"A stubbed account tier field; all v1 Users are `beta`."* → NEW *"The account entitlement field driving the ads/ad-free switch: `free` (ad-supported, the v1 default) or `ad_free` (paid, architecture-ready, not sold in v1)."*
- **Pay Intent:** **DELETE** (deferred to the future paid phase).
- **Free-Tier Cap:** OLD *"...limit of one active Trip... exceeding it surfaces the Pay Intent prompt..."* → NEW *"A cost-control limit of **three active Trips** per free-tier User; exceeding it shows a calm 'trip limit reached' message (no upsell, no billing). Completed/past Trips don't consume a slot; a paused Trip doesn't occupy one."*
- **ADD — Promo Unit:** *"A single calm, native promotional element (v1: an affiliate recommendation) rendered in a dedicated digest slot for free-tier Users; suppressed for ad-free Users; never blocks or delays a send."*

**§4.5 FR-12 (Free-Tier Cap consequence) —**
- OLD: *"a free-tier User may hold **one active Trip**. Attempting to add a **second active Trip** surfaces the Pay Intent upgrade prompt (FR-14)..."*
- NEW: *"a free-tier User may hold **up to three active Trips** (cost-control, configurable). Attempting to add a **fourth active Trip** shows a calm 'trip limit reached' message — no upsell, no billing. Completed/past Trips do not consume a slot; a paused Trip is treated as not occupying a slot."*
- DELETE the `[NOTE FOR PM]` soft-vs-hard open-question note (dissolved; enforced as a plain limit).

**§4.7 Monetization Intent Capture + FR-14 — DELETE the entire section.** (Replaced by §4.9.)

**ADD §4.9 — Monetization: Promotional Content (affiliate)**
- *Description:* v1 is free, sustained by **one** calm, native affiliate recommendation woven into the Daily Digest for free-tier Users. Enhancement-only — it never blocks, delays, or fabricates a send, and it honors the never-alarmist voice. Ad-free entitlement (`plan = ad_free`) suppresses it entirely.
- **FR-17 — Digest promo slot (entitlement-gated, weather-keyed).** Consequences: a free-tier User's Daily Digest *may* render **one** promo unit in a dedicated slot **below the 7-day forecast**; the product is **selected by weather profile** — the `PromoProvider` adapter maps the day's secured forecast snapshot (cold-wet, hot, snow, mild, …) to a curated product set and picks **one** item via **deterministic rotation keyed by `send_date`** (so re-runs of a send pick the same item — idempotent-safe under AD-3); on no profile match or empty catalog it falls back to a generic "travel essentials" set; an `ad_free` User's digest renders none; on any promo-selection error the slot is empty and the digest sends normally; the promo never appears in the subject line or preheader; the slot carries the **mandatory affiliate-disclosure line** ("As an Amazon Associate, tripcast earns from qualifying purchases" — FTC + Amazon Associates requirement); plain-text twin includes the promo as a labeled literal URL plus the disclosure. _Affiliate source for v1 = **Amazon Associates** (links are plain tagged URLs — no SDK/API). See §7 Session-2 Decisions._
- **FR-18 — Affiliate click attribution.** Consequences: a promo click routes through a signed app redirect that records a `promo_events` row (impression at render, click at follow) then forwards **directly to the Amazon product URL** (the click target chosen this session); the redirect is **tripcast's own analytics instrument** (it powers SM-4), not an Amazon requirement; logging is idempotent per (trip, send_date, promo); no PII beyond the existing User/Trip linkage is stored. _Note: routing through tripcast's own redirect keeps the future two-step landing-page pivot a redirect-target change, not a rebuild._

**§5 Non-Goals —**
- OLD: *"It will **not** process payments or run subscription billing in v1 (only Pay Intent capture). The Free-Tier Cap (one active Trip) gates a **Pay Intent prompt**, not a charge."*
- NEW: *"It will **not** process payments or run subscription billing in v1; the optional ad-free paid tier is architecture-ready (entitlement switch) but not sold. It will **not** integrate a third-party ad network in v1 — affiliate links only, no programmatic display ads. The Free-Tier Cap (three active Trips) is pure cost-control and gates a calm limit message, not a charge."*

**§6 MVP Scope —**
- §6.1: replace *"Free-tier 1-active-trip cap with contextual upgrade (Pay Intent) prompt..."* → *"Free-tier 3-active-trip cost-control cap with a calm limit message."*; replace *"Pay Intent capture (no billing)..."* → *"One affiliate promo slot in the Daily Digest (free-tier), entitlement-gated, with click attribution (FR-17/FR-18)."*
- §6.2: replace the *"Real Stripe billing and paid plans — deferred... gated on the 5-paid-members milestone"* note → *"Real Stripe billing + the ad-free paid tier — deferred; architecture-ready via the `plan` entitlement (AD-19). Third-party ad-network / programmatic display — deferred; v1 is affiliate-only."*

**§7 Success Metrics —**
- **SM-4** OLD *"Pay Intent... leading indicator for... 5 paid members"* → NEW **SM-4 — Affiliate engagement:** *"Promo click-through rate and clicks per active Trip — the leading indicator for affiliate/ad revenue viability. Validates FR-17/FR-18."*
- **SM-C2** OLD *"Pay Intent friction..."* → NEW **SM-C2 — Promo restraint:** *"Promo density/placement must never depress SM-1/SM-2 (engagement, retention) or raise SM-C1 (unsubscribe/complaints). One calm unit, below the forecast, never in subject."*
- Business-milestone line OLD *"convert Pay Intent into 5 paid members..."* → NEW *"Sustain the service on affiliate/ad revenue at a clear engagement floor; the ad-free paid tier is optional future upside, not a v1 gate."*

**Aesthetic & Tone — Anti-references:**
- OLD: *"busy weather-app dashboards, ad-heavy forecast sites, anxiety-inducing severe-weather styling."*
- NEW: *"busy weather-app dashboards, **ad-heavy/cluttered** forecast sites, anxiety-inducing severe-weather styling. Monetization, when present, is **one** calm native affiliate unit below the forecast — Tripcast must never read as ad-heavy."*

**§8 Open Questions —** mark Q1 and Q7 (Pay Intent placement / cap enforcement) **RESOLVED/REMOVED** (Pay Intent dropped; cap is a plain limit). Add: *"Affiliate program + link source for v1 — **RESOLVED: Amazon Associates, direct-to-product links** (session 2, §7); weather-profile taxonomy + curated catalog contents (Clayton to author); exact promo copy and slot styling (UX)."*

### 4.2 Architecture Spine

**Frontmatter `binds:`** — remove `FR-14`; add `FR-17, FR-18`.

**AD-6** — remove `pay-intent` from the signed-action list; the email/state-change POST actions are now end-trip, unsubscribe, feedback. **Add:** promo-click attribution is a **signed GET redirect** (read-then-log-then-forward to an external URL) — it is *not* a state-mutating action, so it stays a GET; logging is idempotent.

**AD-15 — rewrite:**
- OLD: cap = 1; SOFT enforcement records Pay Intent; over-cap predicate drives email footer + dashboard prompt.
- NEW: *"A free-tier `User` may hold up to **a configurable limit (default 3)** active Trips (`status==active && deleted_at null`; paused/completed don't occupy). Pure **cost-control**, decoupled from monetization. Enforcement runs through the **single decision point in `CreateTrip`** and is a plain limit (no upsell, no Pay Intent, no billing coupling): over-limit add is refused with a calm 'trip limit reached' message. No `PayIntent` model. The soft-vs-hard open question is dissolved."*

**ADD AD-18 — Monetization via a `Promo` port, render-slot only, never on the delivery path** *(mirrors AD-17)*:
- Promo units reached through a `PromoProvider` interface bound to a concrete adapter in a `ServiceProvider` (v1 adapter = static affiliate config; ad-network adapter is a future swap). Vendor/HTTP appears only in the adapter.
- Selection runs **inside `SendTripDigest`, after the forecast snapshot is secured (AD-3), before render** — **not** part of the idempotency claim or the AD-4 delivery retry. Time-boxed; on timeout/error/no-fill the **slot is empty and the digest sends normally**. Never fails, delays, or re-triggers a send.
- Bounded to **one** native slot **below the forecast**; **never** in subject/preheader. Plain-text twin includes it as a labeled literal URL.
- Attribution: a **signed redirect route** logs a `promo_events` row (impression at render, click at follow) then forwards. Idempotent per (trip_id, send_date, promo).

**ADD AD-19 — Entitlement is a single predicate; `plan` is the ads/ad-free switch** *(mirrors AD-15's single-decision-point pattern)*:
- `shouldShowPromo(user)` is a single read-only predicate derived from `users.plan` (`free` → promos on; `ad_free` → promos off), evaluated at **one decision point** consumed by the digest renderer (AD-18). `plan` stops being a stub. Billing that *sets* `ad_free` is still deferred (no payment in v1) — the switch is architecture-ready, not wired to a checkout.

**Structural Seed (ERD + entities):**
- **Remove** `PAY_INTENT` entity / `pay_intents` table and the `USER ||--o{ PAY_INTENT` relationship; drop `PayIntent` from the models list and consistency conventions.
- `USER.plan` comment: *"`free` (default, ad-supported) | `ad_free` (paid, architecture-ready) — AD-19"*.
- **Add** `PROMO_EVENT { fk trip_id; date send_date; fk user_id; string promo_slug; enum event "impression|click"; datetime created_at }` with `TRIP ||--o{ PROMO_EVENT`. Mirrors the `feedback` table shape.
- Source tree: `Services/Promo/` (`PromoProvider` port + `AffiliatePromoProvider` adapter); `Http/Controllers/PromoRedirect`; migration `promo_events`; remove `pay_intents`.

**Daily-send pipeline diagram** — add a `Promo (AD-18)` step parallel/adjacent to `Narr` (after Fetch, before Render), labeled enhancement-only/omit-on-error.

**Capability → Architecture Map** — remove the FR-14 row; add `FR-17 Digest promo slot → views/emails/digest slot, Services/Promo, Jobs/SendTripDigest → AD-18, AD-19` and `FR-18 Affiliate attribution → Http/Controllers/PromoRedirect, promo_events → AD-6, AD-18`. Update the FR-12 row's AD-15 reference (cap reframe).

**Deferred** — replace the *"Stripe billing & paid plans... 5-paid-members"* and *"Free-tier cap soft vs hard"* bullets with: *"Ad-free paid tier + Stripe billing — architecture-ready via `plan`/AD-19; not sold in v1."* and *"Third-party ad-network / programmatic display — v1 is affiliate-only (AD-18); the `PromoProvider` port is wide enough to add a network adapter without re-architecting."*

### 4.3 SPEC
Mirror the PRD/architecture edits: rewrite **Why** (drop "signal they'd pay" → affiliate/engagement + optional ad-free upside); **FR-12** success (cap → 3, plain limit); **remove FR-14**; **add FR-17/FR-18** capabilities (FR-17 = weather-keyed selection + `send_date` rotation + mandatory affiliate disclosure; FR-18 = signed redirect direct to Amazon product, tripcast-owned analytics); add Constraints for AD-18 (promo off the delivery path, one calm unit, never in subject, idempotent-safe selection, disclosure required) and AD-19 (entitlement switch); update **Non-goals** (no billing/ad-network in v1; ad-free tier architecture-ready); replace **SM-4** in Success signal (affiliate engagement) and drop the "5 paid members" line; update **Assumptions** (remove pay-intent ones; add affiliate-first + promo-restraint + Amazon-Associates-opt-in-email-compliant); resolve the pay-intent **and** affiliate-source **Open Questions** (source = Amazon Associates).

### 4.4 epics.md (requirements inventory)
- **FR-14 line** — replace with **FR-17** (digest promo slot, entitlement-gated) and **FR-18** (affiliate click attribution).
- **FR-12 line** — cap → "up to 3 active Trips (cost-control); over-limit shows a calm limit message (no upsell)."
- **AD-15 line** — rewrite to the cost-control reframe; **add AD-18, AD-19 lines**; update the AD-6 line (drop pay-intent, add promo-redirect); update the entities line (drop `PayIntent`, add `PromoEvent`; `Services/Promo`).
- Then **regenerate** the Epic List + FR Coverage Map (Step 2 of the epic workflow).

## 5. Implementation Handoff

**Scope: Major** (headline business model + success metrics change), though architecturally moderate (patterns already exist).

- **PM (John, `bmad-agent-pm` / `bmad-prd`)** — apply §4.1 PRD edits + §4.3 SPEC edits; owns vision, FRs, metrics, non-goals.
- **Architect (Winston, `bmad-architecture`)** — apply §4.2 spine edits: AD-6/AD-15 amendments, new AD-18/AD-19, ERD (`promo_events` in, `pay_intents` out), maps, deferred.
- **Then:** regenerate epics (`bmad-create-epics-and-stories`) → re-run `bmad-check-implementation-readiness`.
- **UX (Sally, `bmad-agent-ux-designer`)** — locate/confirm `DESIGN.md`/`EXPERIENCE.md`; add the weather-keyed promo slot to UX-DR5 (one unit below the forecast, **with the mandatory affiliate-disclosure line**), remove UX-DR12 pay-intent screen, update UX-DR16 microcopy (drop pay-intent strings; add promo label/disclosure copy + the "trip limit reached" message).

**Success criteria:** all four artifacts consistently describe free + affiliate with an architecture-ready ad-free entitlement; no residual Pay Intent / "5 paid members" references; the aesthetic guardrail (AD-18) is explicit; epics regenerate clean and pass readiness.

## 6. Action Checklist
- [x] Pause the in-flight epic-generation session. _(epics.md is still at step-01; Epic List unrendered — nothing to roll back.)_
- [x] PM: PRD edits (§4.1 + §7 refinements). _(prd.md + addendum.md, 2026-06-29 session 2.)_
- [x] PM: SPEC edits (§4.3 + §7 refinements). _(SPEC.md + glossary.md.)_
- [x] Architect: spine edits (§4.2) + §7 AD-18 refinement applied. _(AD-6/AD-15 amended; AD-18/AD-19 added; ERD PAY_INTENT→PROMO_EVENT; pipeline + maps + deferred updated; lint PASS.)_
- [x] Update epics.md requirements inventory (§4.4). _(FR-14→FR-17/18, cap, AD-6/15/18/19, entities, UX-DR5/12/16.)_
- [x] Regenerate Epic List + FR Coverage Map. _(5 epics, all 17 live FRs mapped; step-02 complete.)_
- [x] UX: DR5/DR12/DR16 edits applied to DESIGN.md + EXPERIENCE.md (promo slot + disclosure added, pay-intent/upgrade screen removed, microcopy + cap message updated; updated 2026-06-29). `mockups/digest-email.html` refreshed to show the weather-keyed promo slot + disclosure (placeholder content).
- [x] Re-run implementation-readiness check. _(implementation-readiness-report-2026-06-29-post-pivot.md — 🟡 READY; one non-blocking gap: UX spine still pre-pivot.)_
- [ ] Clayton: author weather-profile taxonomy + curated Amazon product catalog (config; no code).

## 7. Session-2 Decisions — Affiliate Source & Selection (2026-06-29)

_A second Correct-Course conversation (Clayton + John) resolved how FR-17/FR-18 are filled. These refine — do not contradict — §4.1/§4.2/§4.3 above. Apply alongside them._

**Decisions locked:**
1. **Affiliate source = Amazon Associates.** Resolves the §8 "link source TBD" open question. An affiliate link is a plain tagged URL (e.g. `?tag=tripcast-20`) — **no SDK/API/integration**. The v1 `PromoProvider` adapter is config: weather profile → list of `{ label, image, affiliate URL }`. Any future program (or ad network) is the same shape behind the same port (AD-18).
2. **Selection = weather-keyed curated catalog (A + B).** Clayton authors ~4–6 weather profiles, each → a curated product set; the adapter maps the **already-secured forecast snapshot** (AD-3 ordering) to a profile and picks one item. Generic "travel essentials" fallback on no-match/empty.
3. **Rotation = deterministic, keyed by `send_date`.** Across a trip's 7-day window the slot varies (jacket one morning, gloves the next). Keying on `send_date` keeps it **idempotent-safe** (a re-run picks the same item — required by AD-3). This is why the one-unit guardrail + multi-product profiles coexist: one link per send, varied over the window.
4. **Click target = direct to the Amazon product**, via the existing FR-18 signed redirect. Chosen over the two-step landing page for build simplicity.
5. **Hand-curation (point C) deferred as a build.** In v1, "hand-curation" = Clayton editing the weather-keyed catalog config. A per-send authoring/targeting surface (CMS-lite) is a clean fast-follow on the same port — not v1.
6. **Affiliate disclosure is mandatory** (FTC + Amazon Associates): the promo slot carries a disclosure line ("As an Amazon Associate, tripcast earns from qualifying purchases") in both HTML and plain-text twin. Added to FR-17 AC + UX-DR16.

**Compliance basis (verified this session):** Amazon's official [Offline Use of Associates Links and Ads](https://affiliate-program.amazon.com/help/node/topic/GQ5CMSPVXBXSFDFV) permits Special Links in email **to opted-in recipients with an easy opt-out** — which tripcast is by construction (magic-link opt-in + one-click List-Unsubscribe, NFR-2/UX-DR17). This contradicts common third-party "no links in email" advice; the official source governs. **Residual risk** (Amazon's legal Operating Agreement still carries stricter "offline/mailing" language): if ever enforced, the fix is a fast pivot to a two-step landing page — and because clicks already route through tripcast's FR-18 redirect, that pivot is a **redirect-target change, not a rebuild**.

**Architecture note for Winston:** AD-18's "v1 adapter = static affiliate config" is **refined** to "weather-keyed config with `send_date` rotation, generic fallback, and a mandatory disclosure line." This is **adapter prose only — no new port, table, or structural change** to the spine. FR-18 redirect forwards directly to the Amazon product URL.
