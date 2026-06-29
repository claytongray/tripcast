---
name: tripcast
status: final
sources:
  - "{planning_artifacts}/prds/prd-tripcast-2026-06-28/prd.md"
  - "{planning_artifacts}/prds/prd-tripcast-2026-06-28/addendum.md"
updated: 2026-06-29
---

# tripcast — Experience Spine

> Multi-surface: **the email digest is the product**; a small SSR web app (landing · auth · dashboard · admin) surrounds it. Consumer-in-form, personal-validation v1. Paired with `DESIGN.md` (visual identity). This spine owns *how it works*; `DESIGN.md` owns *how it looks*. Both spines win over any mock on conflict. Tokens referenced as `{path.to.token}` resolve in `DESIGN.md`.

## Foundation

Two surfaces, one product:

- **Email** — the primary surface and the actual product. HTML, single-column, mobile-first, max 600px, **plain-text fallback on every send**. Most user value is delivered here, to people who are *not logged in*, reading at breakfast.
- **Web app** — SSR-rendered (required for landing SEO), Vue.js + Inertia.js + Laravel. Hosts the landing page, magic-link auth, the trip dashboard, the admin monitoring view, and login-free email-action landing pages.

No native app, **no push notifications** in v1; email is the only proactive channel (fixed 9:00 AM America/New_York send). No named UI component system — the web app inherits `DESIGN.md` directly. `DESIGN.md` is the visual reference; this spine specifies behavior.

## Information Architecture

| Surface | Reached from | Auth | Purpose |
|---|---|---|---|
| Landing | Public URL / shared link | none | Tagline + inline trip-setup form (the hero). The form is the primary CTA. |
| Email-capture step | Landing form submit | none | Single email field → creates/attaches account, sends magic link. |
| Check-your-email interstitial | After email submit | none | Confirms a magic link is on its way; offers resend. |
| Magic-link result | Click link in email | becomes auth | Valid → dashboard. Expired/used → calm error + resend. |
| Dashboard | Magic link / return visit (persistent session) | required | View/add/pause/resume/delete trips; past trips; logout. |
| Add-trip | Dashboard action | required | Same fields + geocoding confirm as setup. [ASSUMPTION] inline panel on dashboard, not a separate route. |
| Welcome email | Trip created | none | One-time: destination, dates, when digests begin. |
| Daily digest email | Scheduled send (forecast window) | none | Countdown/position + rolling 7-day forecast + feedback + unsubscribe footer. |
| Feedback landing | 👍/👎 tap in email | none | Records signal, shows a calm thank-you. |
| Unsubscribe / end-trip landing | Footer link in email | none (signed, scoped) | One-click ends/unsubscribes that trip; calm confirmation. |
| Affiliate promo redirect | Promo tap in digest (free-tier) | none (signed) | Logs the click, then forwards to the Amazon product. No interstitial screen — a pass-through redirect. |
| Admin monitoring | Direct URL | admin only | All trips across users, last forecast snapshot, per-trip email log. |

Surface closure check: every PRD need maps to a surface, and every surface is reached by a journey below. Landing→capture→magic-link→dashboard is the setup spine; the digest is the value spine; dashboard is management; admin is operations; the digest's affiliate promo (with its signed redirect) is the monetization signal.

→ Composition references (illustrative; **spine wins on conflict**): [`mockups/landing.html`](mockups/landing.html) · [`mockups/digest-email.html`](mockups/digest-email.html) · [`mockups/dashboard.html`](mockups/dashboard.html).

## Voice and Tone

Microcopy. Brand voice/posture lives in `DESIGN.md.Brand & Style`. tripcast speaks like a calm concierge: brief, confident, **never alarmist about weather**.

| Do | Don't |
|---|---|
| "The weather app you never have to open." | "Check the latest conditions now!" |
| "4 days until Edinburgh." / "Day 2 in Edinburgh." | "⚠️ Rain alert for your trip!" |
| "Thursday looks wet — pack a shell." | "SEVERE WEATHER WARNING" |
| "We're watching Edinburgh. First digest arrives [date]." | "Setup complete! 🎉🎉" |
| "Limited data today — we'll have the full picture tomorrow." | Fabricated numbers to fill the layout |
| "Your trip is wrapped. We've stopped watching — safe travels." | "You have been unsubscribed (code 200)." |
| "That link expired. Want a fresh one?" | "Error: token invalid or consumed." |
| "Thanks — noted." (after feedback) | A multi-step survey |

Rain and rough weather are stated plainly and helpfully, never dramatized. One idea per line. No exclamation pile-ups, no emoji except the deliberate 👍/👎 feedback and an optional single sunrise motif glyph.

**Subject lines & preheaders** (the most-seen surface — the never-alarmist rule applies most sharply here). Place name leads, the countdown is the hook, the *weather verdict never appears in the subject*. No emoji, no all-caps, no exclamation, no "⚠/ALERT/urgent" (these also hurt spam scoring).

| Moment | Subject | Preheader (preview, ~40–100 chars, calm summary) |
|---|---|---|
| Welcome | "We're watching Edinburgh" | "Your first morning forecast arrives 7 July." |
| Pre-trip digest | "Edinburgh — 5 days to go" | "Thursday looks wet; the rest is mild. Highs 14–17°C." |
| During-trip digest | "Edinburgh — day 2" | "Cooler today, clearing by afternoon." |
| Limited-data day | "Edinburgh — your morning note" | "A partial picture today; we'll have the full forecast tomorrow." |

**Rough-weather lexicon** — model the *non-mild* cases so condition copy never drifts into source-API alarm language. Plain, brief, helpful, never "warning / severe / alert / danger / ⚠":

- Heavy rain → "heavy rain — bring a real coat"
- Heat → "hot — pack light, plan some shade"
- Snow → "snow likely — warm layers"
- High wind → "blustery — a windproof layer helps"

**Written strings for load-bearing moments** (these were described but not written; lock them here):

| Moment | Copy |
|---|---|
| Welcome email body | "We're watching Edinburgh, 14–21 July. Your first morning forecast arrives 7 July. Nothing to do until then — we'll be in your inbox." (no CTA, no celebration) |
| Check-your-email interstitial | "Check your inbox — we sent a link to {email}. It expires in [N] minutes." + "Resend link" |
| Empty destination | "Where are you headed?" |
| Return before departure | "Return is before departure — check the dates." |
| Past departure | "That date's already passed — pick a future trip." |
| Resolving destination (pending) | "Finding that place…" |
| Geocoding failure | "We couldn't find that place. Try a city and country — like 'Edinburgh, UK'." + "Edit destination" |
| Geocoding confirm | "Watching Edinburgh, United Kingdom. Not right? Edit destination." |
| Paused trip (card) | "Paused — no emails until you resume." + "Resume" |
| Delete confirm | "Stop watching Edinburgh and remove it? This can't be undone." → "Remove trip" / "Keep it." |
| Next-digest hint | "Next forecast tomorrow, 9:00 AM." |
| End-trip footer link | "End this trip" → confirmation: "Your trip is wrapped. We've stopped watching — safe travels." |
| Affiliate promo disclosure (mandatory) | "As an Amazon Associate, tripcast earns from qualifying purchases." |
| Affiliate promo link | "View on Amazon" (one calm link; never "Buy now", no price, no urgency) |
| Trip limit reached (free-tier cap) | "You're watching 3 trips — that's the limit for now. Pause or wrap one to add another." (calm, no upsell, no billing) |
| Countdown boundary — departure day | "Today: Edinburgh." |
| Countdown boundary — last day | "Last day in Edinburgh." |

The **"watching"** motif ("We're watching {place}" → "We've stopped watching") is the consistent throughline — extend it to confirm/delete copy rather than introducing new verbs.

## Component Patterns

Behavioral rules; visual specs live in `DESIGN.md.Components`.

| Component | Surface | Behavioral rules |
|---|---|---|
| Trip-setup form | Landing | Destination (free text) + departure + return. Submits **without auth**. Validates: destination non-empty; return ≥ departure; past departure rejected inline. Entered values preserved into the email-capture step. |
| Email-capture | Post-submit | Single email field, **no password ever**. Submit → create/attach user, send magic link immediately, queue welcome email, advance to interstitial. |
| Magic-link CTA | Email | One signed, time-limited link. Click authenticates + lands in dashboard. Expired/consumed → calm error page with one-tap resend. |
| Geocoding confirm | Setup / add-trip | After submit, show the **Canonical Place Name** back ("Paris, France") so the user can confirm it's right. [ASSUMPTION] passive display with an "edit destination" affordance; no interactive "did you mean?" picker in v1 (PRD Open Q5). |
| Forecast block | Digest email | 7 stacked day-rows, rolling (re-anchoring deferred to v2). Each row: day · condition glyph + description · high/low in **both °F and °C** · precip %. On partial data, show a "limited data" line — never fabricate. |
| Countdown / position line | Digest email | Pre-trip: "N days until {place}". Departure day: "Today: {place}." During trip: "Day N in {place}". Last day: "Last day in {place}." Boundary copy locked so the header never reads "Day 0" or runs past the trip. [ASSUMPTION] resolves PRD Open Q2. |
| Feedback chips | Digest footer | One-tap 👍 "This helped" / 👎 "Not helpful" (visible text label mandatory, not emoji-only; ≥44px tap targets in email), **no login**. Single low-friction line, not a survey. Tap → feedback landing confirms quietly. |
| Unsubscribe / end-trip link | Every email footer | One-click, signed + scoped to that trip only; sets trip → completed; no login. |
| Trip card | Dashboard | Shows destination, dates, days-until-departure, status. Actions: pause / resume / delete. No weather preview, no analytics (per PRD). Changes reflect immediately. |
| Affiliate promo unit | Digest (free-tier, below forecast) | At most **one** weather-keyed Amazon recommendation: thumbnail + label + "View on Amazon" link + **mandatory disclosure line**. Suppressed for `ad_free`; absent on selection error; never in subject/preheader. Tap → signed redirect logs the click, then forwards to Amazon (no interstitial). Reads as concierge help, not an ad. |
| Free-tier cap | Add-trip (dashboard) | A free-tier user may hold up to **3 active trips** (cost-control). A 4th add is refused inline with the calm "trip limit reached" message — no upsell, no billing. Paused/completed trips don't count. |
| Admin table ([mock](mockups/admin.html)) | Admin | All trips across users: destination, canonical name, dates, status, owner; latest forecast snapshot; per-trip email log (send dates, sent/failed + reason). Read-only monitoring. |

## Email Delivery & Inbox Invariants

Because the email *is* the product and is bulk-sent daily, these are first-class experience invariants, not implementation trivia:

- **Authentication & reputation** — send from a dedicated authenticated subdomain with aligned **SPF + DKIM + DMARC** (`p=quarantine` or `reject`); monitor Google Postmaster reputation; keep the spam-complaint rate **< 0.3%** (Gmail/Yahoo bulk-sender rules).
- **One-click unsubscribe** — every send carries `List-Unsubscribe` (mailto + HTTPS) **and** `List-Unsubscribe-Post: List-Unsubscribe=One-Click` so Gmail/Apple render native unsubscribe; honor it immediately. This is in addition to the in-body "End this trip" link.
- **Sender identity** — a consistent friendly `From` name ("tripcast") on the authenticated domain; a defined `Reply-To` (monitored or explicit no-reply); a stable **physical postal address** in every footer (CAN-SPAM).
- **Scanner-safe links** — security appliances (Outlook Safe Links, Proofpoint, Mimecast) and Gmail prefetch **pre-click GET links**. No bare GET may perform a destructive/state-changing mutation: magic-link, feedback, and end-trip resolve through a confirmation landing / POST-on-confirm, and the `List-Unsubscribe-Post` path is idempotent and scanner-safe.
- **Image-blocking resilience** — images are blocked by default in Outlook desktop and often elsewhere; the email must be **fully comprehensible with all images suppressed**. No background-image for layout/content; every glyph carries `alt` + adjacent text.
- **Plain-text completeness** — the plain-text part is a content-complete mirror, not a stripped afterthought (a weak/ mismatched text part raises spam score): countdown/position line, all 7 days with conditions + high/low in °F **and** °C + precip %, the "limited data today" behavior, and literal tappable URLs for feedback and end-trip.
- **Type minimums** — body ≥ 16px; the footer/unsubscribe line ≥ 13–14px and never below AA contrast on the *rendered* (possibly inverted) background (tiny grey unsubscribe text reads as a dark pattern to filters).

## State Patterns

| State | Surface | Treatment |
|---|---|---|
| Empty dashboard (no trips) | Dashboard | "No trips yet — add your first." Primary action to add-trip. Never a blank screen. |
| Active trip | Dashboard | Status pill = Active. In forecast window → small "next digest [time]" hint. [ASSUMPTION] |
| Paused trip | Dashboard | Status pill = Paused; muted treatment; copy makes clear no emails send while paused. Resume restores sends. |
| Completed / past trip | Dashboard | Grouped/shown separately, muted. Read-only. |
| Resolving destination (pending) | Setup form | Geocoding runs synchronously at creation — show a "Finding that place…" pending state; disable the submit so it can't double-fire. Never a frozen, un-acknowledged button. |
| Geocoding ambiguous | Setup | Resolve to most-likely match, show canonical name back to confirm (no silent guess). Copy: "Watching {place}. Not right? Edit destination." |
| Geocoding failure | Setup | Surface a clear error; **do not create an unmonitorable trip**. Copy: "We couldn't find that place. Try a city and country — like 'Edinburgh, UK'." + "Edit destination". |
| Past / invalid dates | Setup form | Inline, specific message (see Voice strings); preserve other entered values. |
| Trip limit reached (free-tier cap) | Add-trip | Inline calm refusal: "You're watching 3 trips — that's the limit for now. Pause or wrap one to add another." No upsell, no billing, no trip created. |
| Promo slot empty / suppressed | Digest | No promo selected (error/no-fill) or `ad_free` user → the slot is simply absent; the forecast and footer render normally. Never an empty box or placeholder. |
| Offline (web) | — | Out of scope for v1: SSR, email-first product; no offline web behavior specified by design. |
| Magic link expired/used | Magic-link result | Calm error + one-tap resend. No dead end. |
| Limited weather data | Digest email | "Limited data" line in place of any missing values — never fabricated figures. |
| Weather API down (can't build digest) | Email (none sent) | **Send nothing** rather than a broken email; next morning resumes. No error email to the user. |
| Send failure (transient) | Backend → admin | Retry up to 3× within the run, else log + defer to next day. Surfaced only in admin email log (sent/failed + reason). |
| Trip ended via email link | Unsubscribe landing | Calm confirmation; trip set to completed; no login required. |
| Feedback recorded | Feedback landing | Quiet "Thanks — noted." Single screen, no follow-on funnel. |
| Focus (web) | All web inputs/actions | Visible `{colors.focus-ring}`, follows reading order. |

## Interaction Primitives

- **One action per surface.** The landing form, the email's magic link, the digest's feedback — each surface has a single obvious next step.
- **Login-free where it counts.** Magic link, feedback, unsubscribe/end-trip all work with no session (signed, scoped links).
- **Tap/click to act.** No drag, no swipe gestures (web + email).
- **Immediate, optimistic dashboard edits** with clear reflected state; destructive delete asks a single calm confirm.
- **Persistent session** — the dashboard assumes a long-lived, refreshed session; users rarely re-auth.
- **Banned:** carousels, auto-playing motion, severe-weather alert styling, badge/notification counts, multi-step surveys, password fields anywhere, weather radar/hourly detail (out of v1 scope).

## Accessibility Floor

Target: **WCAG 2.2 AA** across email and web. Behavioral floor; visual contrast values live in `DESIGN.md`.

- **Email:** semantic table layout with `role="presentation"` on layout tables; meaningful `alt` on every weather glyph (e.g. alt="rain likely"); never convey condition by color/icon alone — always paired text; readable at 200% zoom and in client dark mode; logical linear reading order; plain-text fallback is itself a full accessible alternative.
- **Web:** semantic HTML landmarks; every control labeled with role + state; visible focus ring on all interactive elements; full keyboard operability (form, dashboard actions, links); form errors associated with fields and announced; status pills carry text labels, not color alone.
- **Contrast:** body and secondary text meet AA (≥4.5:1) on their surfaces; large text/UI ≥3:1 (values set in `DESIGN.md`).
- **Targets:** interactive targets ≥44×44px on web.
- **Motion:** minimal by default; honor `prefers-reduced-motion` (skip any web transition).
- **Color-blind safety:** weather conditions and trip status never rely on hue alone.

## Responsive & Platform

- **Email** is mobile-first, single-column, fluid to 600px, table-based with inline styles. Must degrade gracefully across major clients (Apple Mail, Gmail web/app, Outlook). Dark-mode-aware (see `DESIGN.md` dark tokens); never assume a white background. Rounded corners and any enhancement are progressive — square/flat fallback is acceptable.
- **Landing** — single responsive column; hero + form stack cleanly on mobile; SSR for SEO. The form must be fully usable above the fold on a phone.
- **Dashboard / admin** — responsive single-rail; trip cards stack on mobile; admin table becomes horizontally scrollable or stacks to cards on small screens. [ASSUMPTION] admin is desktop-primary (operational), so a scrollable table is acceptable.

## Inspiration & Anti-patterns

- **Lifted — the daily email-as-product model (Morning Brew / NYT Cooking digests):** the inbox is the surface; no app to open. tripcast's entire value is *not* visiting a dashboard.
- **Lifted — magic-link-only auth (Notion, Slack invites):** one field, no password, friction near zero for a validation build.
- **Rejected — busy weather-app dashboards (Weather.com, AccuWeather):** dense panels, radar, hourly tickers, ads. The explicit anti-reference; tripcast is the calm opposite.
- **Rejected — anxiety-inducing severe-weather styling (red banners, push alerts):** tripcast is never alarmist; rough weather is stated helpfully, in calm blue, in the morning email — nothing interrupts.
- **Rejected — gamified onboarding / streaks / re-engagement nudges:** the product *stops on its own* after the trip. No retention dark patterns.

## Key Flows

### Flow 1 — Setup before signup (Maya, ~3 weeks out, arrived from a friend's link)

1. Maya lands on tripcast from a shared link; the tagline and an inline trip form are the hero — **no signup wall first**.
2. She types "Edinburgh", picks departure + return dates, submits. Validation passes; her values carry forward.
3. She's asked for **one thing — her email**. No password.
4. tripcast confirms the canonical place ("Edinburgh, United Kingdom") and tells her a link is on its way; a magic link hits her inbox.
5. She clicks it.
6. **Climax:** she lands in the dashboard with the Edinburgh trip already saved, and a Welcome Email already waiting that says tripcast is watching and when digests begin.
7. **Resolution:** she closes the tab and does nothing. Nothing is required of her until the forecast window opens.
   - Failure: geocoding can't resolve "Edinburgh" → clear error, no unmonitorable trip created; she can edit and retry. Magic link expired → calm "want a fresh one?" with one tap.

### Flow 2 — The morning digest, run-up and during the trip (Maya, breakfast, not logged in)

1. Five days out, Maya opens her inbox at breakfast — no app, not logged in.
2. A single calm email: **"5 days until Edinburgh"**, a clean rolling 7-day forecast (°F *and* °C, conditions, precip), and one low-friction feedback line.
3. Each morning it updates; once she arrives, the header becomes **"Day 2 in Edinburgh."**
4. **Climax:** she packs a rain shell because Thursday looks wet — and never opens a weather app. The compulsive-checking behavior simply never starts.
5. **Resolution:** the morning after her return date, the emails just stop. No "we'll miss you", no nudge.
   - Edge: one morning the weather source is down → she receives **nothing** rather than a broken email; the next day resumes silently.
   - Partial data → a "limited data today" line instead of invented numbers.

### Flow 3 — Manage trips from the dashboard (Maya, weeks later)

1. Maya returns to the site; her persistent session means she's still logged in.
2. The dashboard lists her trips — destination, dates, days-until-departure, status.
3. She adds a new trip (same confirm-the-place step), pauses one she's unsure about, deletes another.
4. **Climax / Resolution:** the list reflects every change immediately; the paused trip sends no emails until she resumes it. She logs out when done.
   - Failure/guard: delete asks a single calm confirm ("Stop watching Edinburgh and remove it? This can't be undone." → "Remove trip" / "Keep it.") — no accidental destructive action.

### Flow 4 — Admin monitoring (Clayton, validating the beta)

1. Clayton opens the admin view (admin-only).
2. He sees every trip across all users: destination, canonical name, dates, status, owner; the latest forecast snapshot; and a per-trip email log.
3. He spots a send that **failed**, with the logged reason.
4. **Climax / Resolution:** he confirms the retry recovered it the next morning — all without touching the database. The beta is observable from one screen.

### Flow 5 — A recommendation that actually helps (Maya, the morning before a wet arrival)

1. Maya reads her digest: Thursday in Edinburgh looks cold and rainy. Below the 7-day forecast sits **one** calm line — a packable rain shell — with a small thumbnail, "View on Amazon", and a quiet disclosure that tripcast earns from qualifying purchases.
2. It doesn't read as an ad; it reads like the concierge thought of it. She taps it.
3. **Climax / Resolution:** the tap passes through tripcast's signed redirect (the click is logged) and lands her on the Amazon product. No interstitial, no friction. Clayton sees promo clicks climb in the data — the affiliate-engagement signal (SM-4) that tells him the service can sustain itself. For an `ad_free` user, the slot simply isn't there, and nothing about the digest feels emptier.
