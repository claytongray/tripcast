# Tripcast PRD — Addendum (Technical & Downstream Detail)

This addendum preserves implementation-level "how" and depth that belongs to downstream architecture/UX work, kept out of the capability-focused PRD. Source: `tripcast-brief.md`.

## Tech Stack (from brief)

- **Backend:** Laravel (PHP).
- **Frontend:** Vue.js + Inertia.js with **SSR enabled** (required for landing-page SEO).
- **Email:** MailerSend via Laravel Mailable + Blade templates (already integrated).
- **Scheduled jobs:** Laravel Scheduler — a single daily command that queries active trips, fetches weather, and sends emails. *(See PRD NFR: this single command is the known pivot point for scaling to chunked/queued processing.)*
- **Hosting:** Laravel Forge on existing server.
- **Geocoding:** Google Maps Geocoding API.
- **Weather:** WeatherAPI.com (Starter plan, ~$7/mo).
- **LLM (narration):** Anthropic Claude, abstracted behind a Laravel service/port like the weather and geocoding providers (swappable model/provider). Recommended default model: **Claude Haiku 4.5** (`claude-haiku-4-5-20251001`) as the cost-appropriate choice for the short daily narration (FR-16); **Claude Opus 4.8** (`claude-opus-4-8`) available for higher quality. (Records the builder's model-choice decision.)
- **Payments:** Stripe — **not in v1.** The `plan` field is a live entitlement (`free` default | `ad_free` paid, architecture-ready, not sold in v1) driving the ads/ad-free switch, not a stub.
- **Affiliate/Promo:** Amazon Associates as the v1 affiliate source; affiliate links are plain tagged URLs (no SDK/API). Reached behind a `PromoProvider` port (mirrors the Narrator port) so an ad-network adapter is a future swap.

## Provider Abstraction Decisions

- **Weather provider abstracted** behind a Laravel service class so a future swap (e.g., Open-Meteo) is a config change, not a rewrite. Forecast is fetched by coordinates only.
- **Geocoding decoupled from weather:** destination is geocoded once at trip creation (Google) to lat/lng + canonical name; swapping weather APIs requires zero geocoding changes. WeatherAPI's own geocoding is unused.
- **Narration LLM behind its own port:** the AI narration (FR-16) is reached through a dedicated Laravel service/port so the model/provider is a config swap (Anthropic today, swappable later). Narration is grounded **only** in already-stored forecast snapshots (Forecast History) — the port receives stored values, never fetches or invents figures.

## Data Model (guidance from brief — architecture finalizes specifics)

**Users**
- Email address
- `plan` (string, default `'free'`) — live entitlement: `free` (ad-supported) | `ad_free` (paid, architecture-ready, not sold in v1); drives `shouldShowPromo`
- `timezone` (string) — collected at signup for future configurable send times; default `America/New_York` for v1 sends
- Magic link token + expiry
- Session token

**Trips**
- Belongs to User
- Destination (raw input string)
- Canonical place name (resolved by Google)
- Latitude / longitude
- Departure date
- Return date
- Status (active, completed, paused)
- Created at

**Email logs** (de-dup + debugging)
- Trip ID
- Send date
- Status (sent, failed)
- WeatherAPI response snapshot or reference

> New since brief: **Promo Event** records (PRD FR-18 — impression/click keyed by trip/user + send date + promo) and **Feedback Click** record (PRD FR-8) need persistence — architecture to decide tables/columns (e.g., a feedback table + a `promo_events` table, both keyed by trip/user + date). Pay Intent is removed (monetization pivot).

**Forecast History** (PRD FR-15)
- A per-Trip, per-day forecast snapshot record captured across the Forecast Window + trip days, enabling day-over-day diffing for narration.
- Retention cleanup ~30 days after Return Date (no indefinite accumulation).
- **Architecture decision (OPEN — see PRD §8 Q8):** whether this **reuses** the existing per-send weather snapshot (`email_logs`) or a **dedicated** forecast-snapshots table, reconciled with the architecture's "forecasts cached nowhere else" rule.

> The free-tier **3-active-trip cost-control cap** needs **no new table** — it is a **query over active Trips** for the User (`status==active && deleted_at null`), enforced at a single decision point in trip creation. The affiliate Promo Unit (FR-17) adds the `promo_events` table (FR-18 attribution); selection itself is config (weather-profile → curated product set), not a table.

## External APIs & Keys

| Service | Purpose | Notes |
|---|---|---|
| Google Maps Geocoding API | Destination text → lat/lng + canonical name | Pay-per-use, ~$5/1,000 requests |
| WeatherAPI.com | 7-day forecast by coordinates | Starter plan, $7/mo; plan also provides hourly resolution, weather alerts, and a Search API (all unused in v1, available for future phases) |
| MailerSend | Transactional email | Already configured |
| Anthropic Claude API | Daily Digest narration (day-over-day forecast change summary) | Pay-per-token, bounded by active trips × send days; key in `.env` |

## Email Rendering Detail

- HTML email via Laravel Mailable + Blade.
- Single-column, mobile-first; plain-text fallback included.
- Footer: one-click unsubscribe / end-trip (no login) + Feedback Click links.

## Send Timing Detail

- Fixed **9:00 AM Eastern (America/New_York wall-clock; tracks DST)** for v1 (per-user timezone configurability is future; `timezone` field collected now).
- Daily command logic must: select active trips within/after Forecast Window through Return Date, skip paused, de-dup via Email Log, fetch fresh forecast, send, log outcome, retry ≤3× then defer.

## Deferred / Future Phases (from brief)

- Stripe billing + the ad-free paid tier (architecture-ready via the `plan` entitlement; not sold in v1).
- Third-party ad-network / programmatic display (v1 is affiliate-only; the `PromoProvider` port is wide enough to add a network adapter later).
- Per-send hand-curation of promo products via an admin authoring surface (v1 hand-curation = editing the weather-keyed catalog config).
- User-configurable send time / timezone.
- Packing suggestion line; local events; flight status check.
- Native mobile app; push notifications; multiple destinations per trip; trip sharing.
