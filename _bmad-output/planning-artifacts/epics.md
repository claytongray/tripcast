---
stepsCompleted: [step-01-validate-prerequisites, step-02-design-epics, step-03-create-stories, step-04-final-validation]
inputDocuments:
  - prds/prd-tripcast-2026-06-28/prd.md
  - prds/prd-tripcast-2026-06-28/addendum.md
  - architecture/architecture-tripcast-2026-06-28/ARCHITECTURE-SPINE.md
  - ux-designs/ux-tripcast-2026-06-28/DESIGN.md
  - ux-designs/ux-tripcast-2026-06-28/EXPERIENCE.md
  - ../specs/spec-tripcast/SPEC.md
  - ../specs/spec-tripcast/glossary.md
---

# Tripcast - Epic Breakdown

## Overview

This document provides the complete epic and story breakdown for Tripcast, decomposing the requirements from the PRD, UX Design, and Architecture into implementable stories with testable acceptance criteria for the Developer agent.

## Requirements Inventory

### Functional Requirements

- **FR-1: Inline trip setup on landing** — A visitor can enter a Destination (free text) + Departure/Return dates in the landing hero and submit without authenticating. Non-empty destination; Return ≥ Departure; past Departure rejected inline; entered values survive into the email-capture step.
- **FR-2: Email capture & account creation** — After trip details, the visitor provides a single email (never a password) to create-or-match a User and persist the pending Trip. On submit, a Magic Link is sent and a Welcome Email queued.
- **FR-3: Passwordless Magic Link login** — Request a signed, time-limited, single-use link by email; clicking a valid unexpired link authenticates and lands in the dashboard; expired/consumed link is rejected and offers a fresh one; no password field anywhere.
- **FR-4: Persistent sessions** — Long-lived Session refreshed on activity until explicit logout; returning User with valid Session is not re-prompted; logout requires a new Magic Link.
- **FR-5: Login-free email action links** — End-trip / unsubscribe from email footer with no login; links signed and scoped to the intended Trip; GET only confirms, state change is POST (AD-6).
- **FR-6: Daily Digest cadence** — One digest per active Trip each morning, from Forecast Window open (Departure − 7d) through Return Date, then stop; >7 days out → none; paused → none; morning after Return → Trip completed; no duplicate per Trip per date.
- **FR-7: Daily Digest content** — Canonical Place Name, pre-trip countdown ("4 days until Edinburgh") / during-trip position ("Day 2 in Edinburgh"), rolling 7-day forecast each day with high/low in **both °F and °C**, conditions description, precip probability; partial data → "limited data" note, never fabricated.
- **FR-8: Feedback Click** — One-tap "This helped" / "Not helpful" in the footer, no login, tied to Trip + send date; one low-friction line, not a survey; re-click idempotent.
- **FR-9: Welcome Email** — One-time on Trip creation, immediate, independent of Forecast Window; states Destination, dates, and when daily digests will begin.
- **FR-10: One-time geocoding at Trip creation** — Resolve Destination → Canonical Place Name + lat/lng once at creation (never at send); ambiguous → most-likely locale with name shown back to confirm; international resolves correctly; on failure no unmonitorable Trip is created and an error is surfaced.
- **FR-11: Fresh forecast fetch at send time** — Fetch current 7-day forecast by stored coordinates each morning a digest is due; fresh (not pre-cached), by coordinates only, faithful to provider response (calendar-day alignment + °F/°C conversion); captured in the Email Log.
- **FR-12: Dashboard trip management** — Authenticated User can view/add/pause/resume/delete Trips and view past Trips; add uses the FR-10 geocoding path; delete is soft (preserves logs/feedback); logout available. Free-tier cost-control cap: up to three active Trips; a fourth active Trip is refused with a calm "trip limit reached" message (no upsell, no billing); completed/past and paused Trips do not occupy a slot.
- **FR-13: Admin trip & send monitoring** — Admin can view all Trips across Users with Destination, Canonical Place Name, dates, status, owner; most recent forecast snapshot/reference per Trip; per-Trip Email Log (send dates, sent/failed + reason); Admin-only access.
- **FR-17: Digest promo slot (entitlement-gated, weather-keyed)** — A free-tier User's Daily Digest *may* render **one** affiliate Promo Unit in a dedicated slot **below the 7-day forecast**; `ad_free` Users get none; the product is selected by mapping the secured forecast snapshot to a curated weather profile and picking one item via deterministic **send_date rotation** (idempotent-safe), with a generic fallback; on any selection error the slot is empty and the digest sends normally; never in subject/preheader; mandatory affiliate-disclosure line ("As an Amazon Associate, tripcast earns from qualifying purchases") in HTML + plain-text twin. v1 source = Amazon Associates (plain tagged URLs, no SDK/API).
- **FR-18: Affiliate click attribution** — A promo click routes through a signed app redirect that logs a `promo_events` row (impression at render, click at follow) then forwards **directly to the Amazon product URL**; idempotent per (Trip, send_date, promo); tripcast's own analytics for SM-4 (not an Amazon requirement); no PII beyond existing User/Trip linkage.
- **FR-15: Daily forecast history capture** — Retain a day-by-day forecast snapshot per Trip across the Forecast Window + trip days, keyed by Trip + capture date; consecutive captures diffable; purged ~30 days after Return Date.
- **FR-16: AI-generated forecast-change narration** — Optional short calm line narrating notable day-over-day change, grounded **only** in stored snapshots, never-alarmist voice; on no-prior-snapshot or generation failure the line is omitted and the digest still sends (never blocked/delayed/fabricated). The product's first AI feature.
- **FR-19: Account settings** — An authenticated User has a Settings page to change their temperature unit (°F/°C), view their email (read-only), and log out. The unit change persists to the account and is reflected in subsequent digests. Delete-account, email change, and billing are deferred. *(Added 2026-06-30, sprint-change-proposal-2026-06-30.)*
- **FR-20: Dashboard next-send status** — Each upcoming trip card shows when its next forecast will send: a live "sending" beacon + "this/tomorrow morning" while the Trip is within its Forecast Window, or "first forecast in N days · <date>" before the window opens — derived from the single cadence authority (AD-11) on the send clock (AD-7). *(Added 2026-06-30.)*
- **FR-21: Public sample tripcast** — A visitor can request a sample tripcast by email from the landing page; a cached-live sample digest for a fixed demo destination (Reykjavik) is sent, whose "Get started" CTA is a Magic Link that confirms/creates the account and lands them on the dashboard. Each accepted request is recorded (`sample_requests`) for acquisition tracking; the endpoint is throttled on the shared magic-link limiter. *(Added 2026-06-30.)*

### NonFunctional Requirements

- **NFR-1: Reliability of the daily send** — The scheduled run processes all active Trips due a digest; idempotent per Trip/date via Email Log (no duplicates); per-Trip failure retries ≤3× within the run, else logs and defers to the next day; never sends a broken/empty forecast.
- **NFR-2: Deliverability** — Authenticated sending domain (SPF/DKIM/DMARC); plain-text fallback on every email; one-click unsubscribe honored immediately.
- **NFR-3: Observability** — Every send produces an Email Log entry (sent/failed + reason + forecast snapshot/reference); the Admin view is the operational window; the daily run reports liveness (heartbeat / dead-man's-switch, AD-14).
- **NFR-4: Scalability (design-for-future, build-for-small)** — v1 targets low tens of Users; a single scheduled command suffices; architecture must not preclude scaling — providers abstracted, the daily send is the pre-built pivot to chunked/queued processing (per-trip Job, AD-2).
- **NFR-5: Privacy & data** — Stored personal data limited to email + Trip destinations/coordinates; no passwords; unsubscribe/end-trip works without login; no formal GDPR/CCPA program in v1 beta.
- **NFR-6: Cost control** — Geocode once per Trip; weather fetched once per Trip per send day — bounded, roughly linear API spend.
- **NFR-7: Forecast-history retention & privacy** — Per-Trip daily snapshots retained through the Trip then purged ~30 days after Return Date — no indefinite accumulation.
- **NFR-8: AI narration cost** — Narration bounded at ~one LLM call per active Trip per send day — AI spend linear in active trips × send days.
- **NFR-9: AI grounding & safety** — Narration derived strictly from stored snapshots (never invents figures), constrained to the never-alarmist concierge voice (no severe-weather language).

### Additional Requirements

_Technical requirements from the Architecture Spine (AD-1…AD-17) and Addendum that shape stories and build order._

- **🚩 STARTER TEMPLATE (Epic 1, Story 1):** Begin from Laravel's official **Vue starter kit** (Inertia 3 + Vue 3 + Tailwind 4 + shadcn-vue). The kit ships **Fortify password auth + Wayfinder typed routes**; v1 **removes Fortify and builds custom magic-link auth (AD-6)** — a replacement, not a trim — and cleans dropped auth routes so Wayfinder types still build. Configure SSR/Vite, MySQL 8 with **case-insensitive `users.email` collation** (AD-3/AD-10 depend on it), and Redis (queue + cache).
- **Layered architecture (paradigm):** Laravel layered + provider ports + pipes-and-filters send pipeline. Presentation (Inertia Pages / Blade emails) → thin HTTP Controllers → Actions (use-cases) → Eloquent Models; external I/O only via Ports+Adapters; daily send = Console Command (select + dispatch) → per-trip Job.
- **AD-1 Ports for all external I/O:** `WeatherProvider`, `Geocoder`, `Narrator` interfaces + vendor adapters (`WeatherApiProvider`, `GoogleGeocoder`, `ClaudeNarrator`), Mailer via Laravel; bound in a ServiceProvider; vendor SDK/HTTP appears only in the adapter. Weather requested **by coordinates only**.
- **AD-2 Send is command-selects + per-trip Job:** `SendDailyDigests` command computes the due set and dispatches one `SendTripDigest` job per trip (Redis); sync-vs-worker is config, not structure.
- **AD-3 Idempotency via claim-first unique index:** `email_logs` unique on `(trip_id, send_date)`; job inserts the row (`status=sending`, `claimed_at`) before fetch/send; duplicate insert aborts; forecast fetched once and snapshot persisted before delivery; stale-lease rows reclaimable.
- **AD-4 Bounded delivery retry then defer:** Laravel job `tries=1`; in-process retry ≤3× on delivery only (no weather re-fetch); always reaches terminal `sent`/`failed`+reason; recovery is the next day's run; never a fabricated/stale digest.
- **AD-5 One owner for Trip status:** single state-transition method on `Trip` (`active⇄paused` by user, `→completed` by system/end-trip); `completed` terminal; completion is a daily `CompleteExpiredTrips` sweep (status-agnostic); **delete is a soft delete** preserving `email_logs`/`feedback`.
- **AD-6 Auth & email-action safety:** magic-link login via single-use hashed `login_tokens` (requesting a new link invalidates prior unconsumed); **the login link is confirm-then-POST and the first consume confirms the email (`email_verified_at`)** — a new signup's account + trip are pending until confirmed (gated by AD-11); email action links are signed, scoped to trip id; **signed GET renders a confirm page, state change is POST** (end-trip, unsubscribe, feedback); feedback writes idempotent; **promo-click attribution is a signed GET redirect** that reads-then-logs-then-forwards (no state mutation, prefetch-safe, idempotent `promo_events`); long-lived cookie sessions; throttle login per email.
- **AD-7 Two pinned time frames:** all scheduling math (`send_date`, window-open test, countdown, completion) uses **America/New_York** calendar date as "today" (DST-tracking); trip dates stored as timezone-naive `DATE`; **forecast rows render in destination-local calendar days**; `users.timezone` collected but unused for sends.
- **AD-8 Geocode once at creation; Trip cannot exist without coordinates:** `latitude`/`longitude`/`canonical_place_name` required, set once at creation by `Geocoder`, **before email capture, outside any DB transaction**; failure → inline error, no Trip; coordinates never recomputed at send.
- **AD-9 EmailLog = single per-send source of truth + forecast-history time-series:** each row carries outcome + weather snapshot; forecasts cached nowhere else; the `(trip_id, send_date)` row series **is** the forecast history (FR-15); narration (FR-16) reads the prior send_date's snapshot here; readers must tolerate a purged (snapshot-absent) row.
- **AD-10 No orphan trips; account+trip atomic:** pre-account trip details + resolved geocode held in **server session**; on email submit a single **DB-only** transaction upserts `User` and inserts `Trip`; `trip.user_id` not nullable.
- **AD-11 Cadence in one predicate:** due ⟺ `status==active` AND `deleted_at null` AND owner confirmed (`email_verified_at` not null, AD-6) AND owner not opted out (AD-13) AND D ∈ [Departure−7d, Return]; both the selector and the UI "days until" derive from this; Welcome Email fires once when the trip becomes real-for-sending (creation if confirmed, else confirmation).
- **AD-12 Admin = boolean Gate:** `users.is_admin` enforced by one Gate/middleware; no allowlist, no admin CMS.
- **AD-13 Unsubscribe is account-level suppression:** footer one-click unsubscribe sets `users.email_opted_out` (excludes ALL the user's trips in AD-11 + suppresses List-Unsubscribe target); distinct from per-trip "end this trip" (AD-5). Welcome + digests both honor it.
- **AD-14 Daily run liveness:** every run records run-level outcome (started/finished, due, dispatched, sent, failed) + heartbeat; missed heartbeat or finished-zero-dispatched-when-due → out-of-band alert to the builder.
- **AD-15 Free-tier cap at one decision point:** a **configurable cost-control limit (default 3)** active Trips; slot-occupancy = `status==active && deleted_at null` only (paused/completed don't occupy); enforcement runs through a single decision point in `CreateTrip` and is a **plain limit** — an over-limit add is **refused** with a calm "trip limit reached" message (no upsell, no billing). Decoupled from monetization; no `PayIntent` model.
- **AD-18 Monetization via a `Promo` port, render-slot only, off the delivery path:** affiliate promo reached through a `PromoProvider` interface + adapter (v1 = weather-keyed Amazon affiliate config) bound in a ServiceProvider; selection runs inside `SendTripDigest` after the snapshot is secured (AD-3), before render — **not** in the idempotency claim or AD-4 retry; time-boxed, empty-slot-on-error; one unit below the forecast, never in subject/preheader; weather-keyed select + deterministic `send_date` rotation; mandatory affiliate disclosure; signed-redirect attribution to `promo_events` (impression/click), idempotent per (trip, send_date, promo).
- **AD-19 Entitlement is a single predicate; `plan` is the ads/ad-free switch:** `shouldShowPromo(user)` derives from `users.plan` (`free`→promos on, `ad_free`→off) at one decision point consumed by the digest renderer; `plan` is a live entitlement (not a stub); billing that sets `ad_free` is deferred (no checkout in v1).
- **AD-16 Forecast history = bounded-retention sweep over email_logs:** no new store; a scheduled retention sweep **nulls only `weather_snapshot`** ~30 days after `trip.return_date` (anchored on return_date, never send_date); send-outcome row survives; runs in the daily command alongside `CompleteExpiredTrips`.
- **AD-17 AI narration via Narrator port, enhancement-only, off the delivery path:** runs inside `SendTripDigest` after snapshot secured, before render; not part of the idempotency claim or delivery retry; time-boxed; on timeout/error/no-prior → omit line, digest sends; never re-fetches weather; text not separately persisted (derivable from snapshots); model id is config (Haiku 4.5 default, Opus 4.8 available).
- **Stack:** PHP 8.3+, Laravel 13.x, Inertia 3 (SSR via `@inertiajs/vite`), Vue 3 (Composition API), Node 22+ (SSR runtime), Tailwind 4, MySQL 8 (case-insensitive `users.email`), Redis (queue+cache), MailerSend (`mailersend/laravel-driver`), Google Maps Geocoding (HTTP), WeatherAPI.com (Starter), Anthropic Claude (Narrator adapter). Hosting: Laravel Forge (single server).
- **Deployment/ops:** Forge deploy (`composer install`, `npm ci && npm run build` incl. SSR, `php artisan migrate --force`, restart SSR daemon + queue worker); supervised SSR Node + Redis queue-worker daemons; `php artisan schedule:run` per-minute cron; scheduled DB backups; daily-run heartbeat is the primary health signal.
- **Entities/source tree:** `User, Trip, EmailLog, LoginToken, Feedback, PromoEvent` (singular models, snake_case tables; `promo_events` is the only new table — for FR-18). Actions: `CreateTrip, SendTripDigest, EndTrip, RequestMagicLink, CompleteExpiredTrips, PurgeForecastHistory`. Controllers: `…, PromoRedirect` (signed GET redirect). Services: `Weather/`, `Geocoding/`, `Narration/`, `Promo/` (`PromoProvider` + `AffiliatePromoProvider`). No new table for FR-15/FR-16.

### UX Design Requirements

_From the bmad-ux spine pair (DESIGN.md visual identity + EXPERIENCE.md behavior). Each is specific enough to seed a story with testable ACs._

- **UX-DR1: Design-token foundation** — Implement the token system as the single source of truth: color palette with **light + dark pairs** (surface base/raised/wash, ink primary/secondary/disabled, accent + accent-hover/wash, sunrise motif-only, rain glyph-only, positive, hairline, focus-ring), typography scale (display 30/38/600 · title 22/30/600 · subtitle 17/26/500 · body 16/26/400 · meta 13/20/400 · temp 18/22/600 tabular), spacing (4/8/12/16/24/32/48/64), radius (sm 8 / md 14 / lg 20 / full). Web = Tailwind 4 theme + Inter; email = web-safe stack + inline-style equivalents.
- **UX-DR2: Dark-mode + forced-invert resilience** — Ship `<meta name="color-scheme" content="light dark">`, `color-scheme`/`supported-color-schemes`, and a `prefers-color-scheme: dark` block mapping each token to its `-dark` pair; design email so Outlook.com / Gmail-Android forced inversion never erases glyphs (mid-tone stroke or `surface-raised` chip behind each glyph); never assume a white background.
- **UX-DR3: Trip-setup form (landing hero)** — `surface-raised` card on `surface-wash` band; destination full-width + date range + single accent submit; inline validation (empty, return<departure, past departure) with specific copy; on submit show Canonical Place Name back ("Edinburgh, United Kingdom") with quiet "Edit destination" affordance (passive confirm, no "did you mean?" picker); pending "Finding that place…" state disables submit so it can't double-fire.
- **UX-DR4: Email-capture step** — Same card language; single email input + accent submit; one field, nothing else; advances to the check-your-email interstitial.
- **UX-DR5: Daily Digest email template** — `surface-base` outer, fluid `surface-raised` content card (max 600px); header = place name (`display`) + countdown/position line + optional sunrise glyph; 7 stacked forecast day-rows; an (optional) narration line slot; **an (optional) affiliate promo slot below the forecast (free-tier only, FR-17/AD-18) — one calm native unit with a mandatory affiliate-disclosure line, omitted on error or for `ad_free` users, never in subject/preheader**; footer = feedback chips + "End this trip" + account-level one-click unsubscribe + stable physical postal address; **plain-text twin always paired** (promo included as a labeled literal URL + disclosure); fully legible with all images blocked.
- **UX-DR6: Forecast day-row** — Hairline divider, no fill; day label (`meta`) · condition glyph + short description (`body`) · high/low in **both °F and °C** (`temp`, tabular) · precip % in **`ink-secondary`** (rain color is the glyph, not the number); glyphs = hosted PNG 2× retina served 1× with meaningful `alt` + adjacent text; never inline SVG / icon fonts / multicolor emoji; meaning never lives in image alone.
- **UX-DR7: Welcome email template** — One-time, calm, no CTA/celebration; states destination, dates, when digests begin; plain-text twin; honors account opt-out.
- **UX-DR8: Trip card (dashboard)** — `surface-raised`, `rounded/md`, hairline; destination (`subtitle`) + dates & days-until-departure (`meta`) + status pill + pause/resume/delete actions; **no weather preview, no analytics**; changes reflect immediately (optimistic).
- **UX-DR9: Status pill** — small low-contrast tonal chips Active / Paused / Completed, each **text-labeled, not color-only** (AA, color-blind safe).
- **UX-DR10: Magic-link CTA + interstitial + result** — Email: one accent button. Web: centered `surface-raised` "check your inbox — sent a link to {email}, expires in N min" interstitial with resend; calm expired/used result page with one-tap resend (no dead end).
- **UX-DR11: Feedback chips** — 👍/👎 **always paired with visible text** ("👍 This helped" / "👎 Not helpful"); ≥44px tap targets; email caps radius at `md` (full is web-only); collapse to a `positive` "Thanks — noted." line after tap; one tap, no login, no survey.
- **UX-DR12: Affiliate promo unit (digest)** — _(replaces the removed pay-intent/upgrade screen)_ one calm, native promo unit rendered in the UX-DR5 slot below the forecast: product image/label, short copy, single affiliate link, and the mandatory disclosure line ("As an Amazon Associate, tripcast earns from qualifying purchases"); must read as concierge help, not an ad — no pricing drama, no second unit; suppressed for `ad_free` users; on selection error the slot is simply absent.
- **UX-DR13: Admin table** — operational, intentionally plain; hairline row dividers, no card chrome; columns: destination · canonical name · dates · status pill · owner · latest forecast snapshot · email-log (sent/failed + reason); desktop-primary, horizontally scrollable (or stacks) on small screens.
- **UX-DR14: Button + Input primitives** — primary = accent fill / white text / `rounded/full` or `md`, max one primary per surface; secondary = ghost accent; inputs = `surface-raised`, hairline border, `rounded/sm`, visible 2px accent focus-ring with offset, inline validation text in `ink-secondary` (no red-fill drama).
- **UX-DR15: State patterns** — empty dashboard ("No trips yet — add your first"), resolving/pending geocode, geocoding-ambiguous (confirm), geocoding-failure (no unmonitorable trip), paused (muted + "no emails until you resume"), completed (grouped/muted), past/invalid dates (inline), magic-link expired/used, limited-weather-data line, weather-API-down (send nothing), delete confirm ("Stop watching Edinburgh… can't be undone"); never a blank/dead-end screen.
- **UX-DR16: Voice & microcopy** — implement the locked strings: welcome body, check-email interstitial, validation messages, geocoding confirm/failure, paused/delete copy, end-trip confirmation ("Your trip is wrapped. We've stopped watching — safe travels."), **affiliate promo label + mandatory disclosure copy ("As an Amazon Associate, tripcast earns from qualifying purchases")**, **free-tier "trip limit reached" message (calm, no upsell)**, countdown boundary copy ("Today: {place}", "Last day in {place}"); subject lines + preheaders (place name leads, countdown is the hook, **weather verdict never in subject**, no emoji/all-caps/ALERT); never-alarmist rough-weather lexicon ("heavy rain — bring a real coat", etc.).
- **UX-DR17: Email deliverability & inbox invariants** — authenticated subdomain w/ aligned SPF+DKIM+DMARC; `List-Unsubscribe` (mailto + HTTPS) **and** `List-Unsubscribe-Post: List-Unsubscribe=One-Click`; consistent friendly `From` ("tripcast") + defined `Reply-To` + stable physical postal address (CAN-SPAM); scanner-safe links (no destructive bare GET — confirm/POST); image-blocking resilience; plain-text completeness (countdown + all 7 days °F&°C + precip + limited-data + literal tappable URLs); type minimums (body ≥16px, footer ≥13–14px at AA on rendered bg); spam-complaint rate <0.3%.
- **UX-DR18: Accessibility floor (WCAG 2.2 AA)** — email: `role="presentation"` layout tables, meaningful glyph `alt`, condition never by color/icon alone, readable at 200% zoom + client dark mode, plain-text as full accessible alternative; web: semantic landmarks, labeled controls w/ role+state, visible focus ring, full keyboard operability, field-associated announced errors, status pills text-labeled; contrast AA (body/secondary ≥4.5:1, UI/large ≥3:1); ≥44×44px targets; honor `prefers-reduced-motion`; color-blind-safe conditions + status.
- **UX-DR19: Responsive & platform** — email mobile-first single-column fluid to 600px, table layout + inline styles, graceful across Apple Mail / Gmail web+app / Outlook, progressive-enhancement rounding (square fallback ok); landing single responsive column, form usable above the fold on phone; dashboard/admin single-rail, trip cards stack on mobile, admin table scrolls or stacks to cards.

### FR Coverage Map

_To be completed in Step 2 (epic design) — maps every FR / NFR / UX-DR to the epic + story that delivers it._

_Every live FR (FR-14 retired in the monetization pivot) maps to exactly one epic._

| FR | Epic | Coverage |
| --- | --- | --- |
| FR-1 Inline trip setup on landing | Epic 1 | Landing hero trip-setup form |
| FR-2 Email capture & account creation | Epic 1 | Atomic account+trip on email submit |
| FR-3 Passwordless Magic Link login | Epic 1 | Magic-link auth (replaces Fortify) |
| FR-4 Persistent sessions | Epic 1 | Long-lived cookie sessions |
| FR-9 Welcome Email | Epic 1 | One-time welcome on trip creation |
| FR-10 One-time geocoding at creation | Epic 1 | Geocode-once before email capture |
| FR-5 Login-free email action links | Epic 2 | Signed confirm→POST end-trip/unsubscribe |
| FR-6 Daily Digest cadence | Epic 2 | Cadence predicate + per-trip send job |
| FR-7 Daily Digest content | Epic 2 | 7-day forecast, °F+°C, countdown |
| FR-8 Feedback Click | Epic 2 | One-tap helped/not-helpful |
| FR-11 Fresh forecast fetch at send | Epic 2 | Fetch-by-coords once per send (AD-3) |
| FR-12 Dashboard trip management + cost-control cap | Epic 3 | View/add/pause/resume/delete + 3-trip cap |
| FR-13 Admin trip & send monitoring | Epic 3 | Admin view of all trips + email logs |
| FR-15 Daily forecast history capture | Epic 4 | email_logs snapshot time-series + purge |
| FR-16 AI-generated forecast-change narration | Epic 4 | Narrator port, enhancement-only |
| FR-17 Digest promo slot (weather-keyed, entitlement-gated) | Epic 5 | PromoProvider select + disclosure slot |
| FR-18 Affiliate click attribution | Epic 5 | Signed redirect → promo_events → Amazon |
| FR-19 Account settings | Epic 6 | Settings page: temp unit, email, logout |
| FR-20 Dashboard next-send status | Epic 6 | Per-trip beacon + next-send line (AD-11) |
| FR-21 Public sample tripcast | Epic 6 | Landing sample email → magic-link get-started + tracking |

_NFRs and ADs are realized across the epics that touch them (e.g. NFR-1/AD-3/AD-4/AD-14 in Epic 2; AD-18/AD-19 in Epic 5); UX-DRs are pulled into the epic owning their surface (UX-DR1–4/10/14–16 across Epics 1–3; UX-DR5–7/9/18–19 in Epic 2; UX-DR8/13 in Epic 3; UX-DR5/12/16 promo additions in Epic 5)._

## Epic List

_To be completed in Step 2 (epic design)._

_Six epics, organized by user value. Each is standalone and enables — but does not require — later epics. Build in order; within the digest pipeline, Epic 4 then Epic 5 extend the same `SendTripDigest` enhancement seam (after snapshot, before render) and must be sequenced, not interleaved. Epic 6 (added 2026-06-30) is post-MVP growth/account scope reusing existing seams._

### Epic 1: Account & Trip Setup
A visitor goes from the landing hero to a saved, geocoded Trip with a passwordless account and a welcome email — no password anywhere. Establishes the project foundation (Laravel Vue starter kit, Fortify removed, custom magic-link auth) and the create-once-monitorable-Trip path.
**FRs covered:** FR-1, FR-2, FR-3, FR-4, FR-9, FR-10
**Anchored by:** AD-6 (auth + signed actions), AD-8 (geocode once at creation), AD-10 (atomic account+trip), AD-11 (welcome fires once)
**Note:** Epic 1 / Story 1 starts from the **Laravel Vue starter kit** (Inertia 3 + Vue 3 + Tailwind 4 + shadcn-vue): remove Fortify, build magic-link auth, configure SSR/Vite, MySQL 8 (case-insensitive `users.email`), Redis.

### Epic 2: The Daily Digest *(core product)*
An active Trip receives the correct morning digest each day from Forecast-Window open through Return Date, then stops — with a faithful 7-day forecast (°F + °C), countdown/position copy, working login-free footer actions, and one-tap feedback. The daily send is reliable, idempotent, and self-monitoring.
**FRs covered:** FR-5, FR-6, FR-7, FR-8, FR-11
**Anchored by:** AD-1 (ports), AD-2 (command selects + per-trip job), AD-3 (claim-first idempotency), AD-4 (bounded retry/defer), AD-7 (time frames), AD-9 (EmailLog source of truth), AD-11 (cadence predicate), AD-13 (account-level unsubscribe), AD-14 (run liveness)

### Epic 3: Trip Management & Admin
Authenticated users self-manage their Trips (view/add/pause/resume/delete, logout) within a calm 3-active-Trip cost-control cap; the builder monitors every Trip and send from an admin view.
**FRs covered:** FR-12, FR-13
**Anchored by:** AD-5 (one owner for Trip status, soft delete), AD-12 (admin Gate), AD-15 (cost-control cap at one decision point)

### Epic 4: Forecast History & AI Narration
Each daily fetch is retained as a day-by-day forecast-history time-series (over `email_logs`, purged ~30 days after Return), and from it the digest gains a short, calm, AI-generated line narrating notable day-over-day changes — enhancement-only, never blocking or delaying a send.
**FRs covered:** FR-15, FR-16
**Anchored by:** AD-16 (bounded-retention sweep), AD-17 (Narrator port, off the delivery path)

### Epic 5: Affiliate Monetization *(the pivot)*
Free-tier digests carry one calm, native, weather-keyed Amazon affiliate recommendation below the forecast (with mandatory disclosure), suppressed for `ad_free` users; clicks route through a signed redirect that attributes engagement before forwarding to Amazon — the SM-4 signal for sustaining the service on affiliate revenue.
**FRs covered:** FR-17, FR-18
**Anchored by:** AD-18 (PromoProvider port, render-slot only, off the delivery path), AD-19 (`plan` entitlement predicate), AD-6 (signed promo-redirect)

### Epic 6: Growth & Account
Returning users land straight on their dashboard, self-manage account preferences (temperature unit), and see when each trip's next forecast will arrive; new visitors can experience the product before committing via an emailed sample whose "Get started" link becomes their account. Post-MVP scope reusing existing seams (dashboard, magic-link auth, weather port); added 2026-06-30 via sprint-change-proposal.
**FRs covered:** FR-19, FR-20, FR-21 (plus an FR-4 clarification: authenticated `/` → dashboard)
**Anchored by:** AD-1 (weather port — sample forecast), AD-6 (magic-link get-started + account), AD-7 (send clock), AD-11 (cadence authority for next-send), AD-13 (account email preference)

---

## Epics & Stories

_Stories are sized for a single dev-agent session, ordered so none depends on a later story, and each creates only the tables it needs. ACs are Given/When/Then and testable. Each story cites the FR/AD/UX-DR it satisfies._

## Epic 1: Account & Trip Setup

**Goal:** A visitor goes from the landing hero to a saved, geocoded Trip with a passwordless account and a welcome email.
**FRs:** FR-1, FR-2, FR-3, FR-4, FR-9, FR-10 · **ADs:** AD-6, AD-8, AD-10, AD-11 · **UX-DR:** UX-DR1–4, 10, 14–16

### Story 1.1: Project foundation & passwordless magic-link authentication
As the builder,
I want the app scaffolded with magic-link auth replacing the starter kit's password auth,
So that every later story builds on a passwordless, SSR-ready foundation.

**Acceptance Criteria:**

**Given** a clean repo
**When** the project is initialized
**Then** it starts from the Laravel **Vue starter kit** (Inertia 3 + Vue 3 + Tailwind 4 + shadcn-vue) with SSR/Vite configured, MySQL 8 with a **case-insensitive collation on `users.email`**, and Redis (queue + cache) wired.
**And** Fortify password auth is **removed** and its dropped auth routes cleaned so Wayfinder types still build (no password field exists anywhere).
**And** a `users` table exists with `email` (unique, case-insensitive), `plan` (default `free`), `timezone` (default `America/New_York`), `is_admin` (bool), `email_opted_out` (bool).
**And** a `login_tokens` table (hashed token, `expires_at`, `consumed_at`) backs a `RequestMagicLink` action that issues a single-use, time-limited link and **invalidates prior unconsumed tokens** for that email; login is throttled per email. *(FR-3, AD-6)*

**Given** a seeded user with a valid unconsumed magic link
**When** they click it
**Then** the token is consumed, a **long-lived cookie session** is established (refreshed on activity), and they land in the dashboard; clicking an expired/consumed link shows a calm error with one-tap resend. *(FR-3, FR-4, UX-DR10)*

**Given** the UI foundation
**When** the Tailwind 4 theme and base primitives are set up
**Then** the **design-token system** (color light+dark pairs, typography scale, spacing, radius) is the single source of truth with `color-scheme: light dark` + a `prefers-color-scheme: dark` mapping, and the **Button + Input primitives** (accent fill, ghost secondary, hairline inputs with a visible focus ring) are implemented once for reuse. *(UX-DR1, UX-DR2, UX-DR14)*

> **Cross-cutting acceptance (applies to every UI/email story below):** the **WCAG 2.2 AA accessibility floor** (UX-DR18 — semantic landmarks, labeled controls, visible focus, contrast, ≥44px targets, status/condition never by color alone) and **responsive/platform** behavior (UX-DR19 — mobile-first single column, email fluid to 600px, dashboard/admin stacking) are acceptance gates on each surface, not a separate story.

### Story 1.2: Inline trip-setup form on the landing hero
As a visitor,
I want to enter a destination and dates in the landing hero and submit without signing up,
So that the product's value starts before any account exists.

**Acceptance Criteria:**

**Given** the public landing page (SSR-rendered)
**When** I enter a non-empty Destination and a Departure + Return date and submit
**Then** the form accepts it only if Return ≥ Departure and Departure is not in the past — otherwise it shows the specific inline message (empty / return-before-departure / past-departure) without losing my other entries. *(FR-1, UX-DR3, UX-DR16)*

**Given** a valid submission
**When** I proceed
**Then** the entered Destination + dates are preserved in the server session into the next (geocoding/email) step, and no Trip or account is created yet. *(FR-1, AD-10)*

### Story 1.3: One-time geocoding at the trip-detail step
As a visitor,
I want my destination resolved to a real place before I commit,
So that Tripcast watches the right location and never creates an unmonitorable trip.

**Acceptance Criteria:**

**Given** a submitted Destination (Story 1.2) and a `Geocoder` port with a `GoogleGeocoder` adapter (vendor HTTP only in the adapter)
**When** geocoding runs — **once, at the trip-detail step, before email capture, outside any DB transaction**
**Then** it resolves to a Canonical Place Name + latitude/longitude, shown back for **passive confirm** ("Edinburgh, United Kingdom" with a quiet "Edit destination"), with a "Finding that place…" pending state that disables submit so it can't double-fire. *(FR-10, AD-8, UX-DR3, UX-DR15)*

**Given** an ambiguous name ("Paris")
**When** it resolves
**Then** it picks the most-likely locale (Paris, France) and shows the name back to confirm — no interactive "did you mean?" picker. *(FR-10)*

**Given** geocoding fails or returns nothing usable
**When** the visitor submits
**Then** an inline error is shown and **no Trip/coordinates are created**. *(FR-10, AD-8, UX-DR15)*

### Story 1.4: Email capture + atomic account & trip creation
As a visitor,
I want to give just my email to save my trip,
So that I get an account and Tripcast starts watching — with no password and no orphan data.

**Acceptance Criteria:**

**Given** trip details + a resolved geocode held in the session (Stories 1.2–1.3)
**When** I submit a single email (exactly one field, never a password)
**Then** a **single DB-only transaction** upserts the `User` (create-or-match by case-insensitive email) and inserts the `Trip` with `user_id` **not null** and the stored coordinates/canonical name — no external calls inside the transaction. *(FR-2, AD-8, AD-10)*

**Given** a successful creation
**When** the transaction commits
**Then** a Magic Link is sent immediately (Story 1.1) and a Welcome Email is queued (Story 1.5); the check-your-email interstitial is shown. *(FR-2, UX-DR4, UX-DR10)*

### Story 1.5: Welcome email
As a new user,
I want a one-time welcome confirming what Tripcast will do,
So that I know my trip is being watched and when emails begin.

**Acceptance Criteria:**

**Given** a Trip is created (Story 1.4)
**When** the welcome email sends — **immediately, independent of the Forecast Window**
**Then** it states the Destination, the dates, and when daily digests will begin (Forecast-Window open), in the calm voice with no CTA/celebration. *(FR-9, AD-11, UX-DR7)*

**And** it includes a plain-text twin and honors `email_opted_out` (a suppressed user gets neither welcome nor digests). *(FR-9, AD-13, UX-DR7)*

## Epic 2: The Daily Digest *(core product)*

**Goal:** An active Trip receives the correct morning digest from window-open through Return, with working footer actions and feedback; the send is reliable, idempotent, and self-monitoring.
**FRs:** FR-5, FR-6, FR-7, FR-8, FR-11 · **ADs:** AD-1, 2, 3, 4, 7, 9, 11, 13, 14 · **UX-DR:** UX-DR5–6, 9, 11, 16–19

### Story 2.1: Weather provider port + fresh forecast fetch by coordinates
As the system,
I want a swappable weather port that fetches a fresh 7-day forecast by coordinates,
So that digests use current data and the provider can be swapped without touching call sites.

**Acceptance Criteria:**

**Given** a `WeatherProvider` interface with a `WeatherApiProvider` adapter (vendor HTTP only in the adapter)
**When** a forecast is requested for a Trip
**Then** it is fetched **fresh** (not pre-cached), **by coordinates only** (no geocoding dependency on the weather provider), faithful to the provider response with correct calendar-day alignment and °F/°C conversion at render. *(FR-11, AD-1, AD-7)*

**Given** partial provider data
**When** the forecast is assembled
**Then** a "limited data" marker is produced rather than fabricated values. *(FR-7, UX-DR15)*

### Story 2.2: Cadence predicate + daily command that selects and dispatches
As the system,
I want one cadence predicate and a command that dispatches a job per due Trip,
So that "is a digest due today" has a single authority and the send is the pre-built scaling seam.

**Acceptance Criteria:**

**Given** a single cadence predicate
**When** it evaluates a Trip for date D (America/New_York calendar date as "today")
**Then** the Trip is **due ⟺ `status == active` AND `deleted_at` null AND owner confirmed (`email_verified_at` not null, AD-6) AND owner not opted out AND D ∈ [Departure−7d, Return]** — paused/completed/soft-deleted/owner-unconfirmed/opted-out are not due. *(FR-6, AD-11, AD-7, AD-13, AD-6)*

**Given** the scheduled `SendDailyDigests` command runs
**When** it executes
**Then** it computes the due set via the predicate and **dispatches one `SendTripDigest` job per Trip** (Redis) — the command only selects + dispatches, no per-trip work inline. *(FR-6, AD-2)*

**And** no digest is dispatched for a Trip more than 7 days from Departure; a paused Trip gets none. *(FR-6)*

### Story 2.3: Per-trip send job — claim-first idempotency + snapshot persist
As the system,
I want each per-trip job to claim its send row before doing work and persist the forecast once,
So that retries and concurrent workers never double-send or re-fetch weather.

**Acceptance Criteria:**

**Given** an `email_logs` table with a **unique index on `(trip_id, send_date)`**
**When** a `SendTripDigest` job starts
**Then** it inserts the log row (`status = sending`, `claimed_at`) **before** fetching; a duplicate insert fails the unique constraint and the job aborts as already-claimed. *(FR-6, AD-3)*

**Given** a claimed row
**When** the forecast is fetched (Story 2.1)
**Then** it is fetched **once** and its snapshot persisted on the row **before delivery**; a row stuck in `sending` past a stale-lease threshold is reclaimable on a later run. *(FR-11, AD-3, AD-9)*

### Story 2.4: Digest render + delivery with bounded retry
As a traveler,
I want one clean morning email with my countdown and 7-day forecast,
So that I never open a weather app — and I never get a broken email.

**Acceptance Criteria:**

**Given** a claimed row with a persisted snapshot (Story 2.3)
**When** the digest renders
**Then** it shows the Canonical Place Name, a pre-trip countdown ("4 days until Edinburgh") / during-trip position ("Day 2 in Edinburgh"), and 7 day-rows each with high/low in **both °F and °C**, a conditions description, and precip probability; partial data shows the "limited data" line. *(FR-7, AD-7, AD-9, UX-DR5, UX-DR6, UX-DR16)*

**Given** delivery via the Mailer (MailerSend) with the Laravel job at `tries = 1`
**When** delivery fails
**Then** it retries **in-process ≤ 3×, delivery only** (weather is not re-fetched), then reaches a terminal state (`sent`, or `failed` + reason) and **defers recovery to the next day's run** — never sending a fabricated/stale/empty digest. *(FR-6, AD-4)*

**And** every send pairs a content-complete **plain-text twin** (countdown + all 7 days °F&°C + precip + limited-data) and is legible with all images blocked. *(NFR-2, UX-DR5, UX-DR17, UX-DR18)*

### Story 2.5: Login-free end-trip / unsubscribe footer links
As a traveler,
I want to end a trip or unsubscribe straight from the email,
So that stopping is one tap and never needs a login.

**Acceptance Criteria:**

**Given** a signed, trip-scoped footer link
**When** I click it (a GET)
**Then** it **only renders a confirmation page**; the state change happens on a **POST** from that page (prefetch-safe). *(FR-5, AD-6)*

**Given** I confirm "End this trip"
**When** the POST runs
**Then** that one Trip transitions to `completed` via the single Trip transition method and stops receiving digests. *(FR-5, AD-5)*

**Given** I confirm "Unsubscribe"
**When** the POST runs
**Then** `users.email_opted_out` is set, excluding **all** of my Trips from the cadence predicate and suppressing the List-Unsubscribe target. *(FR-5, AD-13, UX-DR17)*

### Story 2.6: Feedback Click
As a traveler,
I want a one-tap "this helped / not helpful" in the footer,
So that I can react without a login or a survey.

**Acceptance Criteria:**

**Given** a `feedback` table `unique(trip_id, send_date)` and signed footer chips
**When** I tap 👍/👎 (a GET → confirm → POST, no login)
**Then** a Feedback Click is recorded tied to that Trip + send date, a re-tap is idempotent (last-reaction-wins), and the row collapses to a calm "Thanks — noted." *(FR-8, AD-6, AD-9, UX-DR11)*

### Story 2.7: Daily-run liveness heartbeat
As the builder,
I want every scheduled run to report its own liveness,
So that a total failure (cron/queue/Redis down) can't go undetected.

**Acceptance Criteria:**

**Given** the daily command runs
**When** it finishes
**Then** it records a run-level outcome (started/finished, due, dispatched, sent, failed) and emits a heartbeat. *(NFR-3, AD-14)*

**Given** a missed heartbeat or a finished-with-zero-dispatched-when-trips-were-due
**When** monitoring evaluates it
**Then** an out-of-band alert fires to the builder. *(NFR-1, AD-14)*

## Epic 3: Trip Management & Admin

**Goal:** Users self-manage trips within a calm 3-active-trip cost-control cap; the builder monitors every trip and send.
**FRs:** FR-12, FR-13 · **ADs:** AD-5, AD-12, AD-15 · **UX-DR:** UX-DR8, 9, 13, 15

### Story 3.1: Dashboard — view trips & manage status
As an authenticated user,
I want to see and manage my trips,
So that I control what Tripcast watches.

**Acceptance Criteria:**

**Given** the authenticated dashboard
**When** it loads
**Then** it lists upcoming Trips (Destination, dates, days-until-departure, status pill) and past/completed Trips grouped separately, with no weather preview or analytics; logout is available. *(FR-12, UX-DR8, UX-DR9)*

**Given** a Trip
**When** I pause / resume / delete it
**Then** status changes go through the **single Trip transition method** (`active ⇄ paused`; delete is a **soft delete** preserving `email_logs`/`feedback`), reflect immediately (optimistic), and a delete asks one calm confirm. *(FR-12, AD-5, UX-DR8, UX-DR15)*

### Story 3.2: Add a trip from the dashboard
As an authenticated user,
I want to add another trip from an inline panel,
So that I can watch more than one destination.

**Acceptance Criteria:**

**Given** the dashboard add-trip inline panel
**When** I add a Trip
**Then** it uses the **same geocoding path as Story 1.3** and creates the Trip through the `CreateTrip` action (the single creation decision point). *(FR-12, AD-8, AD-10)*

**And** because the user is already confirmed (`email_verified_at`, AD-6), there is **no email-capture step** — the Trip is immediately active-for-sending and its Welcome Email fires at creation, landing on a **success state** ("Trip added — your first forecast goes out {date}"). *(FR-9, AD-6, AD-11)*

> **Success screen (shared):** build the polished, dated "trip added / you're all set — first forecast goes out {date}" success screen here and reuse it for the new-user email-confirmation landing (Story 1.1/1.4 currently lands a confirmed new user on the placeholder dashboard with a calm "all set" status message — see the 2026-06-29 email-confirmation sprint change). *(UX-DR4, UX-DR15)*

### Story 3.3: Free-tier cost-control cap
As the system,
I want a single enforced limit on active trips,
So that cost stays bounded without any upsell or billing.

**Acceptance Criteria:**

**Given** the `CreateTrip` decision point and a configurable limit (default **3**)
**When** a free-tier user tries to add an active Trip beyond the limit (slot-occupancy = `status==active && deleted_at null`; paused/completed don't occupy)
**Then** the add is **refused** with a calm "trip limit reached" message — **no upsell, no billing, no Trip created**. *(FR-12, AD-15, UX-DR16)*

**And** there is no Pay Intent surface anywhere. *(AD-15)*

### Story 3.4: Admin monitoring view
As the builder,
I want one screen showing every trip and send,
So that I can confirm the beta is healthy without touching the database.

**Acceptance Criteria:**

**Given** an `is_admin` boolean enforced by a single Gate/middleware
**When** an admin opens the view
**Then** it lists all Trips across users (Destination, Canonical Place Name, dates, status, owner), the most recent forecast snapshot/reference per Trip, and the per-Trip Email Log (send dates, sent/failed + reason); non-admins are denied. *(FR-13, AD-9, AD-12, UX-DR13)*

## Epic 4: Forecast History & AI Narration

**Goal:** Digests gain a calm AI line about notable day-over-day changes; history is retained then bounded.
**FRs:** FR-15, FR-16 · **ADs:** AD-9, AD-16, AD-17 · **UX-DR:** UX-DR5 (narration slot), UX-DR16

### Story 4.1: Forecast-history time-series + bounded-retention purge
As the system,
I want the per-send snapshots to serve as the forecast history and to age out,
So that day-over-day change can be computed without a second store or unbounded growth.

**Acceptance Criteria:**

**Given** the `email_logs` snapshot series (Story 2.3) is the forecast-history time-series (no new store)
**When** consecutive captures exist for a Trip
**Then** a target day's values (e.g., precip probability) are diffable across capture dates. *(FR-15, AD-9)*

**Given** a `PurgeForecastHistory` sweep in the daily command
**When** it runs
**Then** it **nulls only `weather_snapshot`** ~30 days after the Trip's `return_date` (anchored on return_date, never send_date), leaving the send-outcome row intact; readers tolerate a snapshot-absent row. *(FR-15, AD-16)*

### Story 4.2: Narrator port + day-over-day narration (enhancement-only)
As a traveler,
I want a short calm line when the forecast notably changes,
So that I get reassurance without ever a broken or delayed email.

**Acceptance Criteria:**

**Given** a `Narrator` port with a `ClaudeNarrator` adapter (vendor SDK only in the adapter; model id is config — Haiku default)
**When** a digest is built **after the snapshot is secured and before render**, with a prior-day snapshot available
**Then** it renders a brief narration grounded **only** in stored snapshots, in the never-alarmist voice (e.g. "Since yesterday, Thursday's rain chance dropped from 60% to 20%"). *(FR-16, AD-17, UX-DR5, UX-DR16)*

**Given** no prior snapshot, a timeout, or a generation error
**When** the digest builds
**Then** the line is **omitted and the digest sends normally** — narration is **not** part of the idempotency claim or delivery retry, never re-fetches weather, and never fails/delays the send beyond its timebox. *(FR-16, AD-17)*

## Epic 5: Affiliate Monetization *(the pivot)*

**Goal:** Free-tier digests carry one calm, weather-keyed Amazon recommendation below the forecast; clicks are attributed. Built against a **placeholder catalog** so nothing waits on curated content.
**FRs:** FR-17, FR-18 · **ADs:** AD-6, AD-18, AD-19 · **UX-DR:** UX-DR5 (promo slot), UX-DR12 (promo unit), UX-DR16 (disclosure/label)

### Story 5.1: Entitlement predicate — `plan` drives ads/ad-free
As the system,
I want a single predicate deciding whether to show a promo,
So that ad/ad-free gating lives in one place and `plan` is a real switch.

**Acceptance Criteria:**

**Given** `users.plan` is a live entitlement (`free` default | `ad_free`)
**When** `shouldShowPromo(user)` is evaluated at the one decision point consumed by the digest renderer
**Then** it returns true for `free` and false for `ad_free`; no other call site re-implements the check. *(FR-17, AD-19)*

**And** no checkout sets `ad_free` in v1 (the switch is settable in data, billing deferred). *(AD-19)*

### Story 5.2: PromoProvider port + placeholder weather-keyed catalog
As the builder,
I want a promo port backed by a stubbed, editable catalog,
So that the whole promo pipeline is buildable now and the real products drop in later by editing config.

**Acceptance Criteria:**

**Given** a `PromoProvider` interface with an `AffiliatePromoProvider` adapter reading a **config catalog** (vendor specifics — plain tagged Amazon URLs — only in the adapter/config)
**When** the catalog is seeded
**Then** it ships with **placeholder entries** — ~4–6 weather profiles (e.g. cold-wet, hot, snow, mild) each mapped to one or more stub products `{label, image, affiliate URL with tag}`, plus a generic "travel essentials" fallback — editable later with no code change. *(FR-17, AD-18)*

**Given** a secured forecast snapshot
**When** the adapter selects
**Then** it maps the snapshot to a weather profile and picks **one** item via **deterministic rotation keyed by `send_date`** (a re-run picks the same item); no match or empty catalog → the generic fallback. *(FR-17, AD-18, AD-3)*

### Story 5.3: Promo slot in the digest + mandatory disclosure
As a free-tier traveler,
I want at most one calm, relevant recommendation below my forecast,
So that it helps rather than clutters — and the digest never breaks when there isn't one.

**Acceptance Criteria:**

**Given** `shouldShowPromo` true (Story 5.1) and a selected item (Story 5.2), selection running **after the snapshot is secured, before render, off the idempotency/retry path** and time-boxed
**When** the digest renders
**Then** it shows **one** native promo unit **below the 7-day forecast** with the **mandatory disclosure line** ("As an Amazon Associate, tripcast earns from qualifying purchases") in HTML, and the plain-text twin carries the label, a literal URL, and the disclosure. *(FR-17, AD-18, UX-DR5, UX-DR12, UX-DR16)*

**Given** `ad_free`, a selection error, or a timeout
**When** the digest renders
**Then** the slot is **simply absent** and the digest sends normally; the promo **never** appears in the subject line or preheader. *(FR-17, AD-18, AD-19)*

### Story 5.4: Signed-redirect click attribution
As the builder,
I want promo clicks attributed before forwarding to Amazon,
So that I can measure affiliate engagement (SM-4) without putting raw affiliate links in the email body.

**Acceptance Criteria:**

**Given** a `promo_events` table and a `PromoRedirect` controller on a signed route
**When** the digest renders a promo
**Then** an `impression` event is logged for `(trip_id, send_date, promo_slug)`. *(FR-18, AD-18)*

**Given** a recipient taps the promo (a signed GET)
**When** the redirect runs
**Then** it **reads-then-logs** a `click` event (idempotent per `(trip_id, send_date, promo_slug, event)`) **then forwards** to the Amazon product URL — no app state mutation, prefetch-safe, no PII beyond the existing User/Trip linkage. *(FR-18, AD-6, AD-18)*

## Epic 6: Growth & Account

**Goal:** Returning users land on their dashboard and self-manage account preferences and see each trip's next-send timing; new visitors sample the product before committing via an emailed sample whose "Get started" link becomes their account.
**FRs:** FR-19, FR-20, FR-21 (plus FR-4 clarification) · **ADs:** AD-1, AD-6, AD-7, AD-11, AD-13 · **UX-DR:** UX-DR1–4, 8–9, 14–16, 18–19
**Added:** 2026-06-30 via `sprint-change-proposal-2026-06-30.md`. Post-MVP; reuses existing seams (dashboard from Epic 3, magic-link auth from Epic 1, weather port from Epic 2). Design sources under `docs/superpowers/specs/` + `docs/superpowers/plans/`.

### Story 6.1: Authenticated landing redirect to dashboard
As a returning user,
I want the landing URL to take me straight to my trips,
So that I don't land on the new-user setup form when I already have an account.

**Acceptance Criteria:** *(implemented — commit `6c84d1e`)*

**Given** an authenticated user
**When** they request `GET /`
**Then** they are redirected to their dashboard (`route('dashboard')`). *(FR-4)*

**Given** a guest
**When** they request `GET /`
**Then** the landing hero + trip-setup form renders unchanged. *(FR-1, FR-4)*

### Story 6.2: Account settings page
As an authenticated user,
I want a settings page to manage my account,
So that I can change my temperature unit, see my email, and log out in one place.

**Acceptance Criteria:** *(implemented — commit `84359d4`)*

**Given** an authenticated user at `/settings`
**When** the page renders
**Then** it shows their email (read-only) and current temperature unit, and a log-out action; a guest is redirected to login. *(FR-19, UX-DR8)*

**Given** the user toggles the temperature unit (°F/°C)
**When** the change is submitted (auto-saved, optimistic)
**Then** it persists to the account and a calm confirmation shows; an invalid unit is rejected. *(FR-19)*

**And** the top bar links to Settings; delete-account, email change, and billing are out of scope. *(FR-19)*

### Story 6.3: Dashboard per-trip next-send status
As a user viewing my trips,
I want each card to tell me when its next forecast arrives,
So that I know whether it's tomorrow morning or weeks away.

**Acceptance Criteria:** *(implemented — commit `ac6ffe1`)*

**Given** an active Trip inside its Forecast Window
**When** the dashboard renders its card
**Then** a live green "sending" beacon shows plus "Next forecast this morning / tomorrow morning", derived from the single cadence authority on the send clock. *(FR-20, AD-11, AD-7, UX-DR9)*

**Given** an active Trip still before its window opens
**When** the card renders
**Then** it shows "First forecast in N days · <Mon D>" and no beacon; paused/completed trips show no next-send line. *(FR-20, AD-11)*

### Story 6.4: Public sample tripcast (MVP)
As a visitor,
I want to get a sample tripcast by email before signing up,
So that I can see the product's value with one click becoming my account.

**Acceptance Criteria:** *(ready-for-dev — plan: `docs/superpowers/plans/2026-06-30-sample-tripcast-mvp.md`)*

**Given** the landing page
**When** a visitor opens the "Send me a sample" modal and submits their email
**Then** `POST /sample` (throttled on the shared magic-link limiter) creates-or-matches the User, issues a Magic Link, records a `sample_requests` row (user_id, email, destination), and queues a sample digest; the modal confirms "on its way". *(FR-21, AD-6)*

**Given** the fixed demo destination (Reykjavik)
**When** the sample digest is built
**Then** its forecast is the destination's real forecast fetched live once per day and cached (baked-in static fallback on provider failure), rendered through the shared day-row projection with a short window that fits the live reach. *(FR-21, AD-1, AD-7)*

**Given** the sample email
**When** the recipient taps "Get started"
**Then** the Magic Link confirms/creates the account, logs them in, and lands them on the dashboard (no unsubscribe/feedback/promo in a sample). *(FR-21, AD-6)*

**And** each accepted request writes one `sample_requests` row (repeat requests → multiple rows) for acquisition quantification. *(FR-21)*
