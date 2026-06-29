# Tripcast — Project Brief

## Product Overview

**Name:** Tripcast  
**Domain:** tripcast.fyi (or TBD)  
**Tagline:** "The weather app you never have to open."  
**Positioning:** Tripcast is a trip concierge meteorologist — a passive intelligence service that monitors your destination's weather and delivers a daily morning email digest from the moment you add a trip until the day you depart. Users set it up once and receive value in their inbox without ever opening an app again.

**The problem it solves:** Travelers obsessively check weather apps for their upcoming destination — switching locations, doing mental math on what conditions mean for packing, and repeating the ritual daily. Tripcast eliminates that habit by sending a single, clean morning email with everything relevant to your upcoming trip. The product competes not with weather apps but with the *behavior* of checking them.

---

## Core User Journey

1. **Landing page** — Hero section includes the trip setup form inline (destination + dates). User fills it out before signing up. First interaction is the product itself, not a CTA button.
2. **Sign up** — After submitting trip details, user is prompted for their email address only. One field. Magic link sent immediately.
3. **Magic link click** — User lands in their dashboard, trip already saved.
4. **Daily emails begin** — User receives a morning email every day leading up to and including their departure day. Emails stop after departure day automatically.
5. **Dashboard** — User can view active and past trips, add new trips, and manage their account.

---

## Authentication

- **Magic link only.** No passwords. User enters email → receives a signed temporary URL → clicks it → authenticated.
- **Sessions persist indefinitely** via a long-lived cookie, refreshed on activity. Users should never need to re-authenticate unless they explicitly log out or clear their browser.
- **No password reset flow** needed — magic link IS the login mechanism.
- Unsubscribe / trip end link baked into every email footer. One click, no login required.

---

## Email Digest

### Schedule
- Sent daily at **9:00 AM EST** (fixed for v1; timezone configurability is a future feature)
- Emails begin the day after a trip is added (or immediately if added same-day before 9am)
- **Last email sent on departure day** — the morning of the first day of the trip
- Emails stop automatically after departure day; no user action required

### Content (v1)
- Destination name and trip countdown ("4 days until Edinburgh")
- **7-day rolling forecast** for the destination, showing each day with:
  - High / low temperature (°F and °C)
  - Conditions description (sunny, partly cloudy, rain, etc.)
  - Precipitation probability
- A note when forecast data is limited (trips far out where only partial data is available)
- Footer with one-click unsubscribe / "end this trip" link

### Content (future phases — not in v1)
- Local events at destination during trip dates
- Flight status check (24-48hrs out, if flight info provided)
- Packing suggestion line based on forecast conditions

### Email rendering
- HTML email via Laravel Mailable + Blade templates
- Single-column layout, mobile-first
- Plain text fallback included
- Sent via MailerSend (already integrated)

---

## Weather & Geocoding

### Geocoding
- **Provider: Google Maps Geocoding API**
- User enters destination as free text (city name, address, region)
- On trip creation, destination string is geocoded to lat/lng + canonical place name
- Both stored on the trip record
- Geocoding happens once at trip creation — never on email send
- Rationale: decoupled from weather provider; swapping weather APIs requires zero geocoding changes

### Weather
- **Provider: WeatherAPI.com (Starter plan, $7/mo)**
- Coordinates passed to WeatherAPI — no geocoding dependency on weather provider
- Fetch 7-day daily forecast for destination coordinates
- Weather fetched fresh each morning at email send time (not cached day-prior)
- WeatherAPI Starter provides: 7-day forecast, daily + hourly resolution, weather alerts, geocoding (unused — Google handles this), Search API
- Weather provider abstracted behind a Laravel service class so future swap (e.g. to Open-Meteo) is a config change, not a rewrite

---

## Tech Stack

- **Backend:** Laravel (PHP)
- **Frontend:** Vue.js + Inertia.js with SSR enabled (Inertia SSR required for landing page SEO)
- **Email:** MailerSend via Laravel Mailable
- **Scheduled jobs:** Laravel Scheduler (single daily command that queries active trips, fetches weather, sends emails)
- **Hosting:** Laravel Forge on existing server
- **Geocoding:** Google Maps Geocoding API
- **Weather:** WeatherAPI.com
- **Payments:** Stripe — **not in v1.** Stub `plan` field on users table (string, defaults to `'beta'`). Stripe integration is a future phase.

---

## Data Model (guidance only — Claude Code determines specifics)

**Users**
- Email address
- Plan field (string, default `'beta'`) — stubbed for future Stripe integration
- Timezone (string) — collect at signup for future configurable send times; default to America/New_York for v1 sends
- Magic link token + expiry
- Session token

**Trips**
- Belongs to user
- Destination (raw input string)
- Canonical place name (resolved by Google)
- Latitude / longitude
- Departure date
- Return date
- Status (active, completed, paused)
- Created at

**Email logs** (for debugging and avoiding duplicate sends)
- Trip ID
- Send date
- Status (sent, failed)
- WeatherAPI response snapshot or reference

---

## Dashboard (v1)

Minimal. Users should be able to:
- View upcoming trips (destination, dates, days until departure, status indicator)
- Add a new trip
- Pause or delete a trip
- View past trips
- Log out

No analytics, no weather preview in dashboard — the email IS the product.

---

## Landing Page

- Built within the same Laravel/Vue/Inertia app — not a separate site
- Inertia SSR enabled for SEO
- Hero section contains the trip setup form inline (destination + dates)
- After form submission, user is prompted for email to receive their forecast
- Positioning copy: "Stop checking the weather for your trip. We'll do it for you."
- Simple, single-page design — no blog, no docs, no complex nav in v1

---

## Billing & Access (v1)

- **No Stripe integration in v1**
- All users treated as beta / full access
- `plan` field stubbed on users table for future use
- No feature gates, no trial limits
- Product is for personal/internal testing while value is validated

---

## Out of Scope for v1

- Stripe / paid plans
- User-configurable send time or timezone
- Packing suggestions
- Local events
- Flight status
- Native mobile app
- Push notifications
- Multiple destinations per trip
- Trip sharing

---

## External APIs & Keys Required

| Service | Purpose | Notes |
|---|---|---|
| Google Maps Geocoding API | Convert destination text to lat/lng | Pay-per-use, ~$5/1,000 requests |
| WeatherAPI.com | 7-day forecast by coordinates | Starter plan, $7/mo |
| MailerSend | Transactional email sending | Already configured |

---

## Success Criteria for v1

- User can add a trip with a destination and date range
- Daily email sends at 9am EST with accurate 7-day forecast for destination
- Email stops automatically on departure day
- Magic link auth works end to end
- Dashboard shows active trips
- Works for international destinations (Edinburgh, Tokyo, etc.)
- Geocoding correctly resolves ambiguous city names (Paris → France, not Texas)
