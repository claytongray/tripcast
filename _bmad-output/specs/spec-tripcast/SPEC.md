---
id: SPEC-tripcast
companions:
  - glossary.md
  - ../../planning-artifacts/architecture/architecture-tripcast-2026-06-28/ARCHITECTURE-SPINE.md
  - ../../planning-artifacts/ux-designs/ux-tripcast-2026-06-28/DESIGN.md
  - ../../planning-artifacts/ux-designs/ux-tripcast-2026-06-28/EXPERIENCE.md
sources:
  - ../../planning-artifacts/prds/prd-tripcast-2026-06-28/prd.md
  - ../../planning-artifacts/prds/prd-tripcast-2026-06-28/addendum.md
  - ../../../tripcast-brief.md
---

> **Canonical contract.** This SPEC and the files in `companions:` are the complete, preservation-validated contract for what to build, test, and validate. Source documents listed in frontmatter are for traceability only — consult them only if you need narrative rationale or prose color this contract intentionally omits.
>
> Capability IDs are the PRD's `FR-N` identifiers, kept verbatim so the architecture spine's `binds:` and Capability→Architecture map resolve against this SPEC with no remapping.

# Tripcast v1 — The Weather App You Never Have to Open

## Why

This is a **vision to realize** with an attached **business-validation mandate**. Travelers compulsively re-check a forecast they can't change — switching locations in a weather app, doing packing math, repeating the ritual daily — trading attention for low-grade anxiety. Tripcast competes not with weather apps but with that *behavior*: a traveler names a destination and dates once, and from then on Tripcast watches the weather and delivers one calm morning email digest in the run-up to the trip and throughout it, then stops on its own. Value lands where the user already is — the inbox — with zero ongoing effort and a one-form, one-email setup. v1 is a lean personal/internal beta whose job is to prove the ambient digest is useful enough that people read it through a whole trip, react positively, and engage with the calm affiliate recommendations woven into it — the leading indicator that the service can sustain itself on lightweight affiliate/ad revenue, with an optional ad-free paid tier as future upside. The builder is Clayton; the archetypal user is a traveler with a trip a few weeks out.

## Capabilities

- **FR-1 — Inline trip setup on landing**
  - **intent:** A visitor can enter a Destination and a valid date range directly in the landing hero and submit, before any account exists.
  - **success:** Form accepts a non-empty Destination and a range where Return ≥ Departure; a past Departure Date is rejected with a clear inline message; the entered Destination + dates survive into the email-capture step.

- **FR-2 — Email capture & account creation**
  - **intent:** After trip details, a visitor provides a single email address to create-or-match a User and persist the pending Trip to them.
  - **success:** Exactly one field (email, never a password) is requested; a valid email creates a User or attaches the Trip to an existing one; on submit a Magic Link is sent and a Welcome Email is queued.

- **FR-3 — Passwordless magic-link login**
  - **intent:** A User requests a Magic Link by email and authenticates by clicking it.
  - **success:** A submitted email triggers a signed, time-limited, single-use link; clicking a valid unexpired link authenticates and lands in the dashboard; an expired/consumed link is rejected and offers a fresh one; no password field exists anywhere.

- **FR-4 — Persistent sessions**
  - **intent:** Once authenticated, a User stays logged in indefinitely via a long-lived Session refreshed on activity, until explicit logout.
  - **success:** A returning User with a valid Session is not re-prompted; explicit logout ends the Session and requires a new Magic Link to return.

- **FR-5 — Login-free email action links**
  - **intent:** A User can end a Trip / unsubscribe from email footer links with no login.
  - **success:** Every Daily Digest footer carries a one-click end-trip/unsubscribe link that needs no login and stops emails; action links are signed and scoped so they affect only the intended Trip (and a GET only confirms — the state change is a POST, per architecture AD-6).

- **FR-6 — Daily Digest cadence**
  - **intent:** The system sends one Daily Digest per active Trip each morning, beginning when the Forecast Window opens (7 days before Departure) and ending on the Return Date, then stops.
  - **success:** No digest goes out for a Trip more than 7 days from Departure; once inside the window a digest is sent each morning through and including the Return Date; the morning after Return the Trip transitions to completed and no digest is sent; paused Trips get none; no duplicate digest is sent for the same Trip on the same date.

- **FR-7 — Daily Digest content**
  - **intent:** A Daily Digest presents the destination, trip countdown/position, and a rolling 7-day forecast.
  - **success:** Shows the Canonical Place Name, a pre-trip countdown ("4 days until Edinburgh") and during-trip position ("Day 2 in Edinburgh"), and 7 days each with high/low in **both °F and °C**, a conditions description, and precipitation probability; when data is partial, a clear "limited data" note replaces fabricated values.

- **FR-8 — Feedback Click**
  - **intent:** Each Daily Digest footer offers a one-tap "This helped" / "Not helpful" reaction that records engagement without login.
  - **success:** A single tap (no auth) records a Feedback Click tied to that Trip and send date; it is one low-friction line, not a multi-step survey; a re-click is idempotent.

- **FR-9 — Welcome Email**
  - **intent:** On Trip creation the system sends a one-time Welcome Email confirming Tripcast is now watching the Trip.
  - **success:** Sent immediately on signup/trip creation, independent of the Forecast Window; states the Destination, dates, and when daily digests will begin.

- **FR-10 — One-time geocoding at trip creation**
  - **intent:** The system resolves a Destination to a Canonical Place Name + coordinates once, at creation, and stores them on the Trip.
  - **success:** Geocoding runs exactly once per Trip at creation (never at send); ambiguous names resolve to the best-known locale ("Paris" → Paris, France) with the Canonical Place Name shown back to confirm; international destinations resolve correctly; on geocoding failure no unmonitorable Trip is created and an error is surfaced.

- **FR-11 — Fresh forecast fetch at send time**
  - **intent:** The system fetches a current 7-day forecast by the Trip's stored coordinates each morning a digest is due.
  - **success:** Forecast is fetched fresh at send (not pre-cached), by coordinates only (no geocoding dependency on the weather provider), faithful to the provider's response with correct calendar-day alignment and °F/°C conversion; the forecast (or a reference) is captured in the Email Log.

- **FR-12 — Dashboard trip management**
  - **intent:** An authenticated User can view, add, pause, resume, and delete Trips, and view past Trips, within the free-tier cost-control limit of three active Trips.
  - **success:** Upcoming list shows Destination, dates, days-until-departure, and Status; add uses the same geocoding path as FR-10; pause stops digests and resume restores them; delete removes the Trip from cadence and stops emails (soft delete preserves logs/feedback); past Trips are viewable separately; the User can log out. A free-tier User may hold **up to three active Trips** (cost-control, configurable); attempting to add a fourth active Trip is refused with a calm "trip limit reached" message — no upsell, no billing. Completed/past Trips do not consume a slot; a paused Trip does not occupy one. Enforcement is a plain limit at a single decision point in trip creation.

- **FR-13 — Admin monitoring**
  - **intent:** An Admin can view all Trips across Users with status, last-fetched forecast, and send history.
  - **success:** Lists every Trip with Destination, Canonical Place Name, dates, status, and owner; shows the most recent forecast snapshot/reference per Trip and the per-Trip Email Log (send dates, sent/failed + reason); access is restricted to Admin users only.

- **FR-17 — Digest promo slot (entitlement-gated, weather-keyed)**
  - **intent:** A free-tier User's Daily Digest may render one calm, weather-keyed affiliate Promo Unit in a dedicated slot below the 7-day forecast; an ad-free User's digest renders none.
  - **success:** At most **one** Promo Unit renders, **below** the forecast, for `plan == free` Users only; the product is selected by mapping the day's secured forecast snapshot to a curated weather profile (cold-wet, hot, snow, mild, …) and picking **one** item from that profile's curated set via **deterministic rotation keyed by send date** (a re-run picks the same item); on no profile match or empty catalog a generic "travel essentials" fallback is used; on any selection error the slot is empty and the digest sends normally; the promo never appears in the subject line or preheader; a mandatory affiliate-disclosure line ("As an Amazon Associate, tripcast earns from qualifying purchases") renders in both HTML and the plain-text twin; the plain-text twin carries the promo as a labeled literal URL. v1 affiliate source is Amazon Associates; links are plain tagged URLs (no SDK/API).

- **FR-18 — Affiliate click attribution**
  - **intent:** A promo click routes through a signed app redirect that records the event, then forwards to the Amazon product — tripcast's own analytics instrument for SM-4.
  - **success:** A click hits a signed redirect that records a promo event (impression at render, click at follow) then forwards **directly to the Amazon product URL**; logging is idempotent per (Trip, send date, promo); no PII beyond the existing User/Trip linkage is stored; the redirect is not an Amazon requirement but tripcast's measurement of affiliate engagement.

- **FR-15 — Daily forecast history capture**
  - **intent:** The system retains a day-by-day record of each forecast fetched for a Trip across its lead-up (Forecast Window) and the trip itself, so day-over-day changes can be computed.
  - **success:** Each daily fetch persists its forecast snapshot keyed by Trip + capture date; consecutive captures for the same Trip are queryable to diff a given target day's values (e.g., precipitation probability) across days; the history is purged ~30 days after the Trip's Return Date.

- **FR-16 — AI-generated forecast-change narration**
  - **intent:** A Daily Digest can include a short, calm AI-generated line narrating notable day-over-day forecast changes for the Trip.
  - **success:** When prior-day history exists, the digest renders a brief narration grounded **only** in stored snapshots (e.g., "Since yesterday, Tuesday's rain chance dropped from 60% to 20% — looking more promising") in the never-alarmist voice; when there is no prior snapshot or generation fails, the line is omitted and the digest still sends normally (never blocked, delayed, or fabricated).

## Constraints

- **Magic-link only, no passwords anywhere.** Login uses single-use stored tokens; requesting a new link invalidates prior unconsumed ones. (Architecture AD-6.)
- **Email actions are prefetch-safe.** A signed GET only renders a confirmation page; the state change (end-trip, unsubscribe, feedback) happens on a POST — because mail clients and security scanners pre-click GET links. The promo-click attribution (FR-18) is the one exception: it is a signed GET that reads-then-logs-then-forwards (no state mutation), so it stays a GET; logging is idempotent. (AD-6.)
- **Fixed 9:00 AM America/New_York send** (DST-tracking). No per-user send time/timezone in v1; all scheduling math — `send_date`, Forecast-Window test, countdown, completion — uses the New York calendar date as "today". (AD-7.)
- **Never send a broken, empty, or fabricated digest.** If the weather source is down for a Trip that morning, send nothing and resume next day. Delivery retries ≤3× in-run, then defer to the next day's run. (AD-4.)
- **Sends are idempotent.** A DB unique index on `(trip_id, send_date)` is the dedup authority; the job claims the row first, fetches the forecast once and persists the snapshot before delivery, so retries never re-fetch weather. (AD-3.)
- **Email Log is the single per-send source of truth** — outcome + weather snapshot live there; forecasts are cached nowhere else; the admin view reads health from it. (AD-9.)
- **Geocode exactly once at creation; a Trip cannot exist without coordinates.** Weather is fetched **by coordinates only** (no geocoding dependency on the weather provider). Account + Trip are created atomically — no orphan trips, no nullable owner. (AD-1, AD-8, AD-10.)
- **All external I/O goes through ports** (Weather, Geocoder, Mailer behind interfaces bound to adapters) so a provider swap touches one adapter, not every call site. (AD-1.)
- **Per-trip send is a dispatchable Job; the scheduled command only selects + dispatches** — the named, pre-built scaling seam. (AD-2.)
- **One owner for Trip status.** All transitions go through a single method on `Trip`; `completed` is terminal; completion is a status-agnostic daily sweep; delete is a soft delete that preserves logs/feedback. (AD-5.)
- **A single cadence predicate is the sole authority** for "is this Trip due a digest on date D" — both the selector and any UI countdown derive from it, never a re-implementation. (AD-11.)
- **Unsubscribe is account-level suppression** across all of a User's Trips (one-click CAN-SPAM compliance), distinct from per-Trip "end this trip". (AD-13.)
- **Deliverability is first-class:** authenticated sending subdomain with aligned SPF + DKIM + DMARC, `List-Unsubscribe` one-click headers honored immediately, a stable physical postal address in every footer, spam-complaint rate kept < 0.3%, and a content-complete plain-text mirror on every send.
- **Email must be fully legible with all images blocked.** Single-column table layout, fluid to a 600px cap, inline styles; hosted PNG glyphs (2× retina) only — no inline SVG or icon fonts; no information lives in an image alone; dark-mode-aware, never assuming a white background.
- **Never alarmist.** Weather is communicated calmly — precipitation in soft slate-blue, never severe-weather red/amber; the weather verdict never appears in the subject line; voice is calm-concierge and brief.
- **Forecast history is bounded.** The day-by-day history is the time-series of per-send snapshots already stored on `email_logs` (no second forecast cache — AD-9 preserved); a retention sweep nulls those snapshots ~30 days after the Return Date while keeping the send outcome + feedback rows (AD-16), consistent with the limited-data-retention posture.
- **AI narration is grounded and calm.** The narration is derived strictly from stored forecast snapshots (it never invents figures), obeys the never-alarmist calm-concierge voice (no severe-weather language), and is produced through a provider port (per AD-1) so the LLM is swappable and isolated to one adapter.
- **AI narration is enhancement-only.** It runs after the forecast snapshot is secured, outside the idempotency claim and delivery retry; it is time-boxed and may add at most that timebox, but insufficient history, a timeout, or a generation failure omits the line and never **fails** the digest (per AD-17, consistent with AD-3/AD-4). The forecast itself is always the deliverable.
- **Monetization is off the delivery path and density-capped.** The affiliate Promo Unit is reached through a `PromoProvider` port bound to an adapter (v1 = weather-keyed Amazon affiliate config; ad-network is a future swap). Selection runs inside the per-trip send **after the forecast snapshot is secured, before render** — never part of the idempotency claim or the delivery retry; it is time-boxed, and on timeout/error/no-fill the slot is empty and the digest sends normally. Bounded to **one** native unit **below the forecast**, never in the subject/preheader; a mandatory affiliate disclosure renders in HTML + plain text. (AD-18.)
- **Entitlement is a single predicate.** `shouldShowPromo(user)` derives from `users.plan` (`free` → promos on; `ad_free` → promos off) at one decision point consumed by the digest renderer; `plan` is a live entitlement, not a stub. Billing that *sets* `ad_free` is deferred (no checkout in v1) — the switch is architecture-ready. (AD-19.)
- **Forecast figures show both °F and °C** with tabular numerals (conversion at render); forecast rows render in the destination's local calendar days. (AD-7.)
- **The daily run reports its own liveness** (heartbeat / dead-man's-switch); a missed run or finished-with-zero-dispatched-when-due triggers an out-of-band alert to the builder — the daily run *is* the product and the admin view is pull-only. (AD-14.)
- **Admin access is a single `is_admin` boolean behind one Gate** — no scattered ad-hoc checks, no allowlist, no admin CMS in v1. (AD-12.)
- **Accessibility floor is WCAG 2.2 AA** across email and web; trip status and weather conditions are never conveyed by color/hue alone.

## Non-goals

- Not a general weather app — no home-location or non-trip forecasts.
- No real payments or subscription billing in v1; the optional ad-free paid tier is architecture-ready via the `plan` entitlement (`free`/`ad_free`) but not sold.
- No third-party ad network / programmatic display in v1 — affiliate links only (Amazon Associates); the `PromoProvider` port is wide enough to add a network adapter later.
- No per-send hand-curation surface in v1 — promo curation is editing the weather-keyed catalog config.
- No user-configurable send time or timezone in v1 (fixed 9:00 AM Eastern; `timezone` collected but unused for sends).
- No hourly forecasts, radar, packing suggestions, local events, or flight status in v1.
- No native mobile app and no push notifications in v1 — email is the only proactive channel.
- No multiple destinations per Trip and no trip sharing in v1.
- No passwords and no password-reset flow.
- No formal GDPR/CCPA privacy program in the v1 personal beta (revisit before public scale).

## Success signal

- **Primary — engagement & retention.** Across active Trips that reach their Forecast Window, a clear majority register ≥1 positive Feedback Click over their lifecycle (SM-1), and most Trips run from Forecast-Window open through the Return Date **without** a mid-trip unsubscribe/end-trip (SM-2). The world-change moment: a traveler packs a rain shell because the morning digest told them — and never once opens a weather app.
- **Secondary — open rate & affiliate engagement.** Daily Digest open rate > 50% where measurable (soft signal, SM-3); a rising promo click-through rate and clicks per active Trip (SM-4), the leading indicator that the service can sustain on affiliate/ad revenue; the ad-free paid tier is optional future upside, not a v1 gate.
- **Counter-metrics (do not optimize).** Unsubscribe / spam-complaint rate must stay low (SM-C1) — never raise frequency or add nags to juice engagement; promo density/placement must never depress engagement/retention (SM-1/SM-2) or raise SM-C1 — one calm unit, below the forecast, never in the subject (SM-C2).

## Assumptions

- Landing is a single SSR page; no blog/docs/complex nav in v1.
- A same-day signup before the 9:00 AM Eastern send goes out that morning; otherwise the first digest is the next morning (cadence-predicate default; confirm at first real signup).
- During-trip header uses a "Day N in {place}" indicator and the forecast stays a rolling 7-day view (re-anchoring deferred to v2); boundary copy is locked ("Today: {place}", "Last day in {place}").
- Feedback Click is a 👍/👎 single tap with mandatory text labels, not a rating scale or survey.
- Geocoding resolves to the most-likely match with the Canonical Place Name shown back for passive confirm — no interactive "did you mean?" picker in v1.
- Admin is a single `is_admin` flag behind a Gate; no allowlist or admin CMS in v1.
- v1 affiliate source is Amazon Associates with direct-to-product links; an affiliate link is a plain tagged URL (no SDK/API). Amazon permits Special Links in opted-in email with an easy opt-out — which tripcast is by construction (magic-link opt-in + one-click List-Unsubscribe); if Amazon ever enforces the stricter Operating-Agreement reading, pivot to a two-step landing page (a redirect-target change, not a rebuild).
- The weather-profile taxonomy (~4–6 profiles) and the curated product catalog are authored by the builder as config; promo selection rotates deterministically by send date.
- The free-tier cap counts active Trips (default 3, configurable); a paused Trip does not occupy a slot (it is not being monitored) — confirm at review.
- Add-trip is an inline panel on the dashboard, not a separate route.
- Forecast-history retention is 30 days after the Return Date ("a month or so").
- The AI narration highlights notable day-over-day changes (not a per-day readout); calm, brief, ~one line.
- A current Claude model is recommended for narration (Haiku 4.5 for cost, Opus 4.8 for quality); the final model choice is an architecture/addendum decision.
- Inter is the web UI typeface (swappable — the type scale, not the family, is the contract); email uses a web-safe font stack only.

## Open Questions

- **Enforcement at the free-tier cap — RESOLVED.** The cap is a plain cost-control limit (three active Trips, configurable) with a calm "trip limit reached" message; no Pay Intent, no billing coupling. The soft-vs-hard question is dissolved.
- **Affiliate program + link source — RESOLVED: Amazon Associates, direct-to-product links.** Remaining: the weather-profile taxonomy + curated catalog contents (builder to author) and exact promo copy + slot styling (UX).
- Admin access model beyond v1 — keep the single `is_admin` flag or move to a small allowlist as the beta grows. (PRD Open Q4.)
