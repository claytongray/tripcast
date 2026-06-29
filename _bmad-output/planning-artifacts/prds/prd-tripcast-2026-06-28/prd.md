---
title: Tripcast — Product Requirements Document
status: final
created: 2026-06-28
updated: 2026-06-29
---

# PRD: Tripcast
*Working title — confirm.*

## 0. Document Purpose

This PRD is for the Tripcast builder (Clayton) and any collaborators or downstream BMad workflows (architecture, epics & stories). It is scoped lean for a personal/internal validation build with intentional room to scale. Vocabulary is anchored in the Glossary (§3); features are grouped with globally-numbered Functional Requirements (FRs) nested under them; assumptions are tagged inline as `[ASSUMPTION]` and indexed in §9. It builds on `tripcast-brief.md` (authored with Claude); technology choices, the data model, and other implementation "how" live in the companion `addendum.md`, not here.

## 1. Vision

Tripcast is "the weather app you never have to open." It is a passive trip-concierge meteorologist: a traveler tells it where and when they're going, once, and from then on Tripcast watches the destination's weather and delivers a single clean morning email digest in the run-up to the trip and throughout the trip itself — then stops on its own.

The product does not compete with weather apps; it competes with the *behavior* of compulsively checking them. Travelers today switch locations in a weather app, do mental math on packing, and repeat the ritual daily. Tripcast collapses that loop into one ambient email and gives the traveler their attention back.

It matters because the value is delivered where the user already is — their inbox — with zero ongoing effort. Setup is one form and one email address. Success for v1 is proving that this ambient digest is genuinely useful enough that people keep reading it through a whole trip, react positively, and engage with the calm affiliate recommendations woven in. Longer term the ambition is viral growth, sustained by lightweight affiliate/ad revenue woven calmly into the digest, with an optional ad-free paid tier as upside.

## 2. Target User

### 2.1 Jobs To Be Done
- **Functional:** "Tell me what the weather will be for my upcoming trip without my having to go look it up every day."
- **Emotional:** "Stop the low-grade anxiety of obsessively re-checking a forecast I can't change."
- **Contextual:** "Help me pack and plan with accurate destination conditions, including while I'm actually there."
- **Builder's JTBD:** "Validate cheaply whether ambient trip-weather email is a product people want and engage with enough to sustain on affiliate/ad revenue — with an optional ad-free paid tier as future upside."

### 2.2 Non-Users (v1)
- People wanting a general-purpose weather app or a home-location daily forecast (Tripcast is trip-scoped only).
- Power users wanting hourly detail, radar, or severe-weather alerting (future phases).
- Anyone needing multi-destination itineraries or trip sharing in v1.

### 2.3 Key User Journeys

- **UJ-1. Maya sets up a trip before she's even decided to sign up.**
  - **Persona + context:** Maya, planning a 6-day Edinburgh trip three weeks out, lands on tripcast.fyi from a friend's link.
  - **Entry state:** Unauthenticated, on the landing page.
  - **Path:** The hero *is* the product — she types "Edinburgh" and her dates into the inline form and submits before any signup CTA. She's then asked for one thing: her email. A magic link arrives; she clicks it.
  - **Climax:** She lands in her dashboard with the Edinburgh trip already saved, and a welcome email is already in her inbox confirming Tripcast is now watching the trip.
  - **Resolution:** She closes the tab. She does nothing else until the forecast window opens.

- **UJ-2. Maya gets her morning digest in the run-up and during the trip.**
  - **Persona + context:** Same Maya, now 5 days from departure.
  - **Entry state:** Not logged in anywhere; just opening her inbox at breakfast.
  - **Path:** A single email: "5 days until Edinburgh," a clean 7-day forecast (highs/lows in °F and °C, conditions, precip chance), and a one-line feedback prompt. Each morning it updates. Once she's there, the countdown becomes "Day 2 in Edinburgh" and the forecast keeps coming.
  - **Climax:** She packs a rain shell because Tripcast told her Thursday looks wet — and never once opened a weather app.
  - **Resolution:** The morning after her return date, the emails simply stop. **Edge case:** if a digest can't be built one morning (weather API down), she gets nothing rather than a broken email; the next day resumes normally.

- **UJ-3. Maya manages her trips from the dashboard.**
  - **Persona + context:** Maya wants to add a second trip and pause an old one.
  - **Entry state:** Returns to the site; her long-lived session means she's still logged in.
  - **Path:** Dashboard lists upcoming and past trips with destination, dates, days-until-departure, and status. She adds a new trip, pauses another, deletes a third. Logs out from here if she wants.
  - **Climax/Resolution:** Trip list reflects changes immediately; paused trips send no email.

- **UJ-4. Clayton monitors the whole system from an admin view.**
  - **Persona + context:** Clayton, the builder, wants to confirm sends are working and forecasts look right during the beta.
  - **Entry state:** Authenticated as an admin.
  - **Path:** Opens an admin view listing all trips, their status, the forecast last fetched for each, and the per-trip email send log (sent/failed, dates).
  - **Climax/Resolution:** He spots a failed send and its logged reason, confirms it retried, and sees it recover the next morning — without touching the database directly.

## 3. Glossary

- **Trip** — A single destination + date range belonging to one User. Has a Departure Date, a Return Date, and a Trip Status. The atomic unit Tripcast monitors.
- **Destination** — The free-text location a User enters for a Trip (e.g., "Edinburgh").
- **Canonical Place Name** — The resolved, unambiguous place label produced by geocoding the Destination (e.g., "Edinburgh, Scotland, UK"), stored with latitude/longitude on the Trip.
- **Departure Date** — The first day of a Trip.
- **Return Date** — The last day of a Trip. The last Daily Digest is sent on this date; none are sent after it.
- **Forecast Window** — The 7-day span ending at the Departure Date during which destination forecast data becomes available. Daily Digests begin when this window opens (7 days before Departure Date).
- **Trip Status** — One of: **active** (being monitored), **paused** (no emails), **completed** (past Return Date or ended early).
- **Welcome Email** — The one-time email sent immediately on signup/trip creation, confirming Tripcast is watching the Trip.
- **Daily Digest** — The recurring morning email for an active Trip, sent once per day during and after the Forecast Window through the Return Date.
- **Feedback Click** — A one-tap reaction link in a Daily Digest footer ("This helped" / "Not helpful") used as the primary engagement signal; requires no login.
- **Magic Link** — A signed, time-limited URL emailed to a User that authenticates them on click. The sole login mechanism.
- **Session** — A long-lived authenticated state, persisted via cookie and refreshed on activity, that keeps a User logged in indefinitely until explicit logout.
- **Email Log** — A per-send record (Trip, send date, sent/failed, weather snapshot/reference) used for de-duplication, debugging, and admin monitoring.
- **Plan** — The account entitlement field driving the ads/ad-free switch: `free` (ad-supported, the v1 default) or `ad_free` (paid, architecture-ready, not sold in v1).
- **Free-Tier Cap** — A cost-control limit of three active Trips per free-tier User; exceeding it shows a calm "trip limit reached" message (no upsell, no billing). Completed/past Trips don't consume a slot; a paused Trip doesn't occupy one.
- **Promo Unit** — A single calm, native promotional element (v1: a weather-keyed Amazon Associates affiliate recommendation) rendered in a dedicated Daily Digest slot below the forecast for free-tier Users; suppressed for ad-free Users; never blocks or delays a send.
- **Forecast History** — The day-by-day record of forecast snapshots captured for a Trip across its Forecast Window and trip days, enabling day-over-day comparison; purged ~30 days after Return Date.
- **Narration** — A short, calm, AI-generated Daily Digest line describing notable day-over-day forecast changes, grounded strictly in stored Forecast History; enhancement-only.
- **User** — An account identified by email address that owns Trips.
- **Admin** — A privileged User who can access the admin monitoring view.

## 4. Features

### 4.1 Landing Page & Trip Setup

**Description:** The landing page is a single page within the main app (SSR-rendered for SEO) whose hero contains the trip-setup form inline. The first interaction is the product itself: a visitor enters a Destination and a date range and submits *before* any account exists. Positioning copy frames the value ("Stop checking the weather for your trip. We'll do it for you."). After submission the visitor is prompted for their email only. Realizes UJ-1. `[ASSUMPTION: no blog/docs/complex nav in v1 — single page.]`

**Functional Requirements:**

#### FR-1: Inline trip setup on landing
A visitor can enter a Destination (free text) and a Departure Date and Return Date directly in the landing hero and submit without authenticating. Realizes UJ-1.

**Consequences (testable):**
- Form accepts a non-empty Destination string and a valid date range where Return Date ≥ Departure Date.
- Submitting with a past Departure Date is rejected with a clear inline message.
- The entered Destination + dates are preserved through the subsequent email-capture step (not lost on signup).

#### FR-2: Email capture and account creation
After trip details are submitted, the visitor can provide a single email address to create (or match) a User and persist the pending Trip to that User.

**Consequences (testable):**
- Exactly one field (email) is requested; no password is ever requested.
- A valid email creates a User if none exists, or attaches the Trip to the existing User if the email is already known.
- On submission a Magic Link is sent immediately (see FR-5) and a Welcome Email is queued (see FR-9).

### 4.2 Authentication

**Description:** Authentication is Magic Link only — no passwords, no reset flow. A User enters their email, receives a signed temporary URL, and clicking it authenticates them and establishes a long-lived Session. Email action links (unsubscribe / end trip / feedback) work without any login. Realizes UJ-1, UJ-3.

**Functional Requirements:**

#### FR-3: Passwordless Magic Link login
A User can request a Magic Link by entering their email and authenticate by clicking it. Realizes UJ-1.

**Consequences (testable):**
- A submitted known email triggers a signed, time-limited Magic Link to that address.
- Clicking a valid, unexpired Magic Link authenticates the User and lands them in their dashboard.
- An expired or already-consumed Magic Link is rejected and offers to send a fresh one.
- No password field exists anywhere in the product.

#### FR-4: Persistent sessions
Once authenticated, a User remains logged in indefinitely via a long-lived Session refreshed on activity, until explicit logout.

**Consequences (testable):**
- A returning User with a valid Session is not asked to re-authenticate.
- Explicit logout ends the Session and requires a new Magic Link to return.

#### FR-5: Login-free email action links
A User can act on a Trip from email footer links (unsubscribe / end this trip; Feedback Click) without logging in.

**Consequences (testable):**
- Each Daily Digest footer contains a one-click "end this trip / unsubscribe" link that requires no login and sets the Trip to completed (stops emails).
- Footer action links are signed/scoped so they affect only the intended Trip.

### 4.3 Email Digest *(core feature)*

**Description:** The email digest is the product. Two email types exist: a one-time **Welcome Email** on signup, and the recurring **Daily Digest**. Crucially, **daily emails do not start immediately for far-future trips** — after the Welcome Email there are no daily emails until the Forecast Window opens (7 days before Departure Date). From then on, a Daily Digest is sent every morning **through the Return Date** — i.e., continuing during the trip itself — and stops automatically after the Return Date. Each Daily Digest is single-column, mobile-first HTML with a plain-text fallback, sent at a fixed **9:00 AM Eastern (America/New_York wall-clock, so it tracks DST)**. Realizes UJ-2.

**Functional Requirements:**

#### FR-6: Daily Digest cadence
The system sends a Daily Digest for each active Trip once per morning, beginning when the Forecast Window opens (7 days before Departure Date) and ending on the Return Date, then stops. Realizes UJ-2.

**Consequences (testable):**
- No Daily Digest is sent for an active Trip whose Departure Date is more than 7 days away (only the Welcome Email has gone out).
- Once a Trip is within 7 days of Departure, a Daily Digest is sent each morning.
- Daily Digests continue every morning through and including the Return Date (during the trip).
- The morning after the Return Date, no Daily Digest is sent and the Trip transitions to completed.
- A paused Trip receives no Daily Digest.
- A Trip created inside the 7-day window begins receiving Daily Digests the next scheduled run (or same-day if created before the 9:00 AM Eastern send). `[ASSUMPTION: same-day-before-9am sends that morning; otherwise next morning.]`
- No duplicate Daily Digest is sent for the same Trip on the same date (enforced via Email Log).

#### FR-7: Daily Digest content
A Daily Digest presents the destination, trip countdown/position, and a rolling 7-day forecast for the Destination.

**Consequences (testable):**
- Shows Canonical Place Name and a countdown before the trip ("4 days until Edinburgh") and a position indicator during the trip ("Day 2 in Edinburgh"). `[ASSUMPTION: during-trip copy uses a day-N indicator.]`
- Shows a 7-day forecast, each day with high/low temperature in **both °F and °C**, a conditions description, and precipitation probability.
- When forecast data is partial (e.g., trip edge cases), a clear "limited data" note is shown instead of fabricated values.

#### FR-8: Feedback Click
Each Daily Digest footer offers a one-tap Feedback Click ("This helped" / "Not helpful") that records engagement without login.

**Consequences (testable):**
- Clicking either option records a Feedback Click tied to that Trip and send date.
- The interaction is a single tap and requires no authentication.
- Feedback is presented as one low-friction line, not a multi-step survey. `[ASSUMPTION: 👍/👎 style, not a rating scale.]`

#### FR-9: Welcome Email
On Trip creation the system sends a one-time Welcome Email confirming Tripcast is now watching the Trip.

**Consequences (testable):**
- Sent immediately on signup/trip creation, independent of the Forecast Window.
- States the Destination, dates, and when daily digests will begin (when the Forecast Window opens).

**Feature-specific NFRs:**
- Single-column, mobile-first HTML; plain-text fallback always included.
- Footer on every email includes one-click unsubscribe / end-trip.

### 4.4 Weather & Geocoding

**Description:** On Trip creation the Destination is geocoded once to a Canonical Place Name plus latitude/longitude, both stored on the Trip; geocoding never runs at send time. Each morning, the forecast is fetched fresh by coordinates for active Trips due a Daily Digest. The weather provider is abstracted so it can be swapped without touching geocoding. Realizes UJ-2. *(Provider specifics live in `addendum.md`.)*

**Functional Requirements:**

#### FR-10: One-time geocoding at Trip creation
The system resolves a Destination to a Canonical Place Name + coordinates once, at Trip creation, and stores them on the Trip.

**Consequences (testable):**
- Geocoding occurs exactly once per Trip at creation, never at send time.
- **(v1 acceptance)** Common ambiguous city names resolve to their best-known locale — "Paris" → Paris, France (not Texas) — and the Canonical Place Name is shown back so the User can confirm the match. `[ASSUMPTION: most-populous/most-likely match with confirm-by-display; no interactive "did you mean?" picker in v1 — see §8 Q5.]`
- **(v1 acceptance)** International destinations (Edinburgh, Tokyo, etc.) resolve correctly to the right country/coordinates.
- If geocoding fails or returns nothing usable, Trip creation surfaces an error and does not silently create an unmonitorable Trip.

#### FR-11: Fresh forecast fetch at send time
The system fetches a current 7-day forecast by the Trip's stored coordinates each morning a Daily Digest is due.

**Consequences (testable):**
- Forecast is fetched fresh at send time (not pre-cached the prior day).
- Forecast is requested by coordinates only (no geocoding dependency on the weather provider).
- **(v1 acceptance)** The forecast shown in the Daily Digest faithfully reflects the provider's response for the Trip's coordinates — correct calendar-day alignment and correct °F/°C conversion, no fabricated or stale values.
- The fetched forecast (or a reference to it) is captured in the Email Log for that send.

### 4.5 Dashboard

**Description:** A minimal authenticated dashboard to manage Trips. No analytics, no weather preview — the email is the product. Realizes UJ-3.

**Functional Requirements:**

#### FR-12: Trip management
An authenticated User can view, add, pause, and delete Trips, and view past Trips.

**Consequences (testable):**
- Upcoming Trips list shows Destination, dates, days-until-departure, and Trip Status.
- User can add a new Trip (same geocoding path as FR-10).
- User can pause a Trip (stops Daily Digests) and resume it.
- User can delete a Trip (removes it and stops emails).
- Past/completed Trips are viewable separately.
- User can log out (ends Session).
- **Free-Tier Cap:** a free-tier User may hold **up to three active Trips** (cost-control, configurable). Attempting to add a **fourth active Trip** shows a calm "trip limit reached" message — no upsell, no billing. Completed/past Trips do **not** consume a slot; a paused Trip is treated as **not** occupying a slot.

### 4.6 Admin Monitoring

**Description:** An internal view for the builder to monitor the system during beta — all Trips, their status, the forecast last fetched, and the per-Trip Email Log. Realizes UJ-4. This is operational tooling, intentionally minimal. `[ASSUMPTION: admin access gated to a flagged Admin user; no full admin CMS in v1.]`

**Functional Requirements:**

#### FR-13: Admin trip & send monitoring
An Admin can view all Trips across Users with status, last-fetched forecast, and send history.

**Consequences (testable):**
- Lists all Trips with Destination, Canonical Place Name, dates, status, and owning User.
- Shows the most recent forecast snapshot/reference per Trip.
- Shows the Email Log per Trip: send dates and sent/failed outcome (with failure reason where available).
- Access is restricted to Admin users only.

### 4.7 Monetization: Promotional Content (Affiliate)

**Description:** v1 is free, sustained by **one** calm, native affiliate recommendation woven into the Daily Digest for free-tier Users. Enhancement-only — it never blocks, delays, or fabricates a send, and it honors the never-alarmist voice. Ad-free entitlement (`plan = ad_free`) suppresses it entirely. The v1 affiliate source is **Amazon Associates**; affiliate links are plain tagged URLs (no SDK/API/integration). The promo product is chosen by the destination's weather so it reads as concierge help, not advertising.

**Functional Requirements:**

#### FR-17: Digest promo slot (entitlement-gated, weather-keyed)
A free-tier User's Daily Digest may render one weather-keyed affiliate Promo Unit in a dedicated slot below the 7-day forecast.

**Consequences (testable):**
- A free-tier User's Daily Digest may render **one** Promo Unit in a dedicated slot **below the 7-day forecast**; an `ad_free` User's digest renders none.
- The product is selected by **weather profile**: the day's forecast snapshot is mapped to a curated profile (e.g., cold-wet, hot, snow, mild) and **one** item is chosen from that profile's curated set via **deterministic rotation keyed by send date** — re-running the same send picks the same item (idempotent-safe).
- On no profile match or empty catalog, a generic "travel essentials" fallback set is used.
- On any promo-selection error, the slot is empty and the digest sends normally — the promo never blocks, delays, or fabricates a send.
- The Promo Unit never appears in the subject line or preheader.
- The slot carries the **mandatory affiliate-disclosure line** ("As an Amazon Associate, tripcast earns from qualifying purchases") in both HTML and the plain-text twin (FTC + Amazon Associates requirement).
- The plain-text twin includes the promo as a labeled literal URL plus the disclosure.

#### FR-18: Affiliate click attribution
A promo click routes through a signed app redirect that records the event, then forwards to the Amazon product.

**Consequences (testable):**
- A promo click routes through a **signed app redirect** that records a promo event (impression at render, click at follow) then forwards **directly to the Amazon product URL**.
- The redirect is tripcast's own analytics instrument (it powers SM-4), not an Amazon requirement.
- Logging is idempotent per (Trip, send date, promo).
- No PII beyond the existing User/Trip linkage is stored.

### 4.8 Forecast History & Change Narration

**Description:** Extends the Email Digest (§4.3) and Weather (§4.4) capabilities. Each daily forecast fetch is retained as a day-by-day Forecast History per Trip, enabling day-over-day comparison; from that history a Daily Digest can carry a short, calm, AI-generated Narration of notable changes. This is Tripcast's first AI feature and is strictly enhancement-only — it never blocks, delays, or fabricates a send. Realizes UJ-2.

**Functional Requirements:**

#### FR-15: Daily forecast history capture
The system retains a day-by-day record of each forecast fetched for a Trip across its Forecast Window (the lead-up) and the trip days, so day-over-day changes can be computed. *(Builds on FR-11's fresh fetch; feeds FR-16.)*

**Consequences (testable):**
- Each daily fetch persists its forecast snapshot keyed by **Trip + capture date**.
- Consecutive captures for the same Trip are queryable to diff a target day's values (e.g., precipitation probability) across capture days.
- Forecast History is purged ~30 days after the Trip's Return Date.

#### FR-16: AI-generated forecast-change narration
A Daily Digest can include a short, calm, AI-generated line narrating notable day-over-day forecast changes for the Trip. **The product's first AI feature.** *(Enhancement only — never blocks the send.)*

**Consequences (testable):**
- When prior-day Forecast History exists, the digest renders a brief Narration grounded **only** in stored snapshots — e.g., "Since yesterday, Tuesday's rain chance dropped from 60% to 20% — looking more promising" — in the never-alarmist calm-concierge voice.
- When there is no prior snapshot **or** generation fails, the Narration line is **omitted** and the digest still sends normally — never blocked, delayed, or fabricated.

## 5. Non-Goals (Explicit)

- Tripcast is **not** a general weather app and will not provide home-location or non-trip forecasts.
- It will **not** process payments or run subscription billing in v1; the optional ad-free paid tier is architecture-ready (entitlement switch) but not sold. It will **not** integrate a third-party ad network in v1 — affiliate links only, no programmatic display ads. The Free-Tier Cap (three active Trips) is pure cost-control and gates a calm limit message, not a charge.
- It will **not** offer user-configurable send times or timezones in v1 (fixed 9:00 AM Eastern).
- It will **not** provide hourly forecasts, radar, packing suggestions, local events, or flight status in v1.
- It will **not** be a native mobile app or send push notifications in v1.
- It will **not** support multiple destinations per Trip or trip sharing in v1.
- It will **not** require or offer passwords.

## 6. MVP Scope

### 6.1 In Scope
- Landing page with inline trip setup (SSR for SEO).
- Email-only signup + Magic Link auth with persistent Sessions.
- Welcome Email + Daily Digest with the cadence in FR-6 (welcome → quiet → daily from Forecast Window through Return Date).
- 7-day forecast content (°F + °C, conditions, precip), limited-data handling.
- One-time geocoding; fresh forecast fetch at send.
- Feedback Click engagement signal.
- Minimal dashboard (view/add/pause/resume/delete/logout).
- Free-tier 3-active-trip cost-control cap with a calm "trip limit reached" message.
- Admin monitoring view.
- One weather-keyed affiliate Promo Unit in the Daily Digest (free-tier), entitlement-gated, with click attribution (FR-17/FR-18).
- Forecast History capture per Trip + ~30-day post-Return purge (FR-15).
- AI forecast-change Narration in the Daily Digest (FR-16) — enhancement-only, never blocks a send.
- Send-failure handling: retry 3× per run, else log and defer to next day (see §NFRs).

### 6.2 Out of Scope for MVP
- Real Stripe billing + the ad-free paid tier — deferred; architecture-ready via the `plan` entitlement. Third-party ad-network / programmatic display — deferred; v1 is affiliate-only.
- Timezone / send-time configuration — the `timezone` field is collected now for future use (fixed 9:00 AM Eastern in v1). The `plan` field is a live `free`/`ad_free` entitlement (not a stub), but no checkout sets `ad_free` in v1.
- Packing suggestions, local events, flight status — future content phases.
- Native app, push notifications, multi-destination trips, trip sharing.

## 7. Success Metrics

**Primary**
- **SM-1 — Engagement via Feedback Click.** Of active Trips that reach their Forecast Window, the share registering ≥1 positive Feedback Click during their lifecycle. Target (validation): a clear majority of monitored trips get at least one "This helped." Validates FR-6, FR-7, FR-8.
- **SM-2 — Trip-completion retention.** Share of Trips that run from Forecast-Window open through Return Date **without** a mid-trip unsubscribe/end-trip. Target: most trips complete without opt-out. Validates FR-6, FR-5.

**Secondary**
- **SM-3 — Open rate.** Daily Digest open rate where measurable; acknowledged unreliable for email, treated as a soft signal only. Target: >50% where tracked. Validates FR-7.
- **SM-4 — Affiliate engagement.** Promo click-through rate and clicks per active Trip — the leading indicator for affiliate/ad revenue viability. Validates FR-17/FR-18.

**Counter-metrics (do not optimize)**
- **SM-C1 — Unsubscribe / spam-complaint rate.** Must stay low. Counterbalances SM-1/SM-3: do not increase email frequency or add nags to juice engagement at the cost of opt-outs.
- **SM-C2 — Promo restraint.** Promo density/placement must never depress SM-1/SM-2 (engagement, retention) or raise SM-C1 (unsubscribe/complaints). One calm unit, below the forecast, never in the subject. Counterbalances SM-4.

*Business milestone (post-v1 gate, not a v1 build target): sustain the service on affiliate/ad revenue at a clear engagement floor; the ad-free paid tier is optional future upside, not a v1 gate.*

## 8. Open Questions

1. ~~Exact placement and wording of the Pay Intent affordance~~ **REMOVED** — Pay Intent was dropped in the monetization pivot to free + affiliate (see §4.7, FR-17/FR-18).
2. During-trip digest copy wording (FR-7). *(Decided for v1: the forecast stays a rolling 7-day view throughout; re-anchoring on remaining trip days is a v2 refinement, not a v1 blocker.)*
3. Same-day signup edge: should a trip created at, say, 8:55 AM EST attempt that morning's send, or always start next morning? (FR-6 assumption.)
4. Admin access model — single hard-coded Admin flag vs. a small allowlist (FR-13).
5. Geocoding disambiguation — is most-likely-match acceptable for v1, or is a "did you mean?" confirmation needed for ambiguous Destinations? (FR-10.)
6. Domain confirmation (tripcast.fyi or alternative).
7. ~~Enforcement at the Free-Tier Cap (FR-12) — soft-allow vs hard-block~~ **RESOLVED/REMOVED** — the cap is now a plain cost-control limit (three active Trips) with a calm "trip limit reached" message; no Pay Intent coupling.
8. Forecast History substrate (ARCHITECT-facing, FR-15) — reuse the existing per-send weather snapshot store (Email Log) vs a dedicated forecast-snapshots store, reconciled with the architecture's "forecasts cached nowhere else" rule.
9. Affiliate program + link source for v1 (FR-17/FR-18) — **RESOLVED: Amazon Associates, direct-to-product links.** Remaining: the weather-profile taxonomy + curated catalog contents (builder to author), and exact promo copy + slot styling (UX). `[NOTE FOR PM] Amazon permits Special Links in opted-in email with easy opt-out — tripcast qualifies; if Amazon ever enforces the stricter Operating-Agreement reading, pivot to a two-step landing page (a redirect-target change, not a rebuild).]`

## 9. Assumptions Index

- §4.1 / FR-1 — Landing is a single page; no blog/docs/complex nav in v1.
- §4.3 / FR-6 — Same-day-before-9am signups send that morning; otherwise next morning.
- §4.3 / FR-7 — During-trip copy uses a "Day N in {place}" indicator; forecast stays a rolling 7-day view.
- §4.3 / FR-8 — Feedback Click is a 👍/👎-style single tap, not a rating scale.
- §4.4 / FR-10 — Geocoding resolves to most-likely match with the Canonical Place Name shown back; no disambiguation picker in v1.
- §4.6 / FR-13 — Admin is a flagged user; no full admin CMS in v1.
- §4.7 / FR-17 — Affiliate Promo Unit is one calm weather-keyed Amazon Associates recommendation below the forecast; plain tagged URLs (no SDK/API); mandatory affiliate disclosure; rotation keyed by send date.
- §NFR Privacy — no formal GDPR/CCPA program in the v1 personal beta; revisit before public scale.

---

## Cross-Cutting NFRs

- **Reliability of the daily send.** The scheduled morning run must process all active Trips due a Daily Digest. Sends are idempotent per Trip per date via the Email Log (no duplicates). On a per-Trip send failure (weather fetch or delivery), the system retries up to **3 times within the run**; if still failing, it logs the failure and **defers to the next day's run** — it never sends a broken/empty forecast in place of a real one.
- **Deliverability.** Authenticated sending domain (SPF/DKIM via the email provider); plain-text fallback on every email; one-click unsubscribe honored immediately.
- **Observability.** Every send produces an Email Log entry (sent/failed + reason + forecast snapshot/reference); the Admin view (FR-13) is the operational window into system health.
- **Scalability (design-for-future, build-for-small).** v1 targets small scale (low tens of Users); a single scheduled command is sufficient. The architecture must **not preclude** scaling as adoption grows — provider calls are abstracted, and the daily send is the known pivot point to move to chunked/queued processing when volume warrants. `[NOTE FOR PM] Flag the single-command daily job as the first thing to revisit under load.]`
- **Privacy & data.** Stored personal data is limited to email + Trip destinations/coordinates. No passwords are stored. Unsubscribe/end-trip works from email without login. `[ASSUMPTION: no formal GDPR/CCPA program in v1 personal beta; revisit before public scale.]`
- **Cost control.** Geocoding runs once per Trip; weather is fetched once per Trip per send day — keeping third-party API spend bounded and roughly linear in active trips × days.
- **Forecast-history retention & privacy.** Per-Trip daily forecast snapshots are retained through the Trip then purged ~30 days after the Return Date — no indefinite forecast accumulation.
- **AI narration cost.** Narration is bounded at roughly one LLM call per active Trip per send day, keeping AI spend linear in active trips × send days.
- **AI grounding & safety.** Narration is derived strictly from stored forecast snapshots (it never invents figures) and is constrained to the never-alarmist concierge voice (no severe-weather language).

## Aesthetic & Tone

- **Tagline:** "The weather app you never have to open." — surface it on the landing page, not just internally.
- **Voice:** calm, concierge-like, effortless — reinforce the tagline. Confident and brief; never alarmist about weather.
- **Visual:** clean, single-column, mobile-first email; minimal landing page that lets the inline form be the hero.
- **Anti-references:** busy weather-app dashboards, **ad-heavy/cluttered** forecast sites, anxiety-inducing severe-weather styling. Monetization, when present, is **one** calm native affiliate unit below the forecast — Tripcast must never read as ad-heavy.

## Information Architecture (surfaces)

- **Landing page** — public, SSR; inline trip-setup hero + positioning copy.
- **Email** — Welcome Email + Daily Digest (the primary product surface).
- **Dashboard** — authenticated; trip management only.
- **Admin view** — restricted; monitoring only.
