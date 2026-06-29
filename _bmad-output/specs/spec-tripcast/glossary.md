# Tripcast — Glossary

Load-bearing domain vocabulary for Tripcast v1. Every capability in `SPEC.md` is anchored in these terms; downstream skills resolve trip/cadence/auth language here. Attribute-level invariants live in the architecture spine (`ARCHITECTURE-SPINE.md`).

- **Trip** — A single destination + date range belonging to one User. Has a Departure Date, a Return Date, and a Trip Status. The atomic unit Tripcast monitors.
- **Destination** — The free-text location a User enters for a Trip (e.g., "Edinburgh").
- **Canonical Place Name** — The resolved, unambiguous place label produced by geocoding the Destination (e.g., "Edinburgh, Scotland, UK"), stored with latitude/longitude on the Trip.
- **Departure Date** — The first day of a Trip.
- **Return Date** — The last day of a Trip. The last Daily Digest is sent on this date; none are sent after it.
- **Forecast Window** — The 7-day span ending at the Departure Date during which destination forecast data becomes available. Daily Digests begin when this window opens (7 days before Departure Date).
- **Trip Status** — One of: **active** (being monitored), **paused** (no emails), **completed** (past Return Date or ended early). `completed` is terminal.
- **Welcome Email** — The one-time email sent immediately on signup/trip creation, confirming Tripcast is watching the Trip and stating when digests begin.
- **Daily Digest** — The recurring morning email for an active Trip, sent once per day from when the Forecast Window opens through the Return Date.
- **Feedback Click** — A one-tap reaction link in a Daily Digest footer ("This helped" / "Not helpful") used as the primary engagement signal; requires no login.
- **Magic Link** — A signed, time-limited, single-use URL emailed to a User that authenticates them on click. The sole login mechanism. (Login uses a stored single-use token, not a bare signed URL — see architecture AD-6.)
- **Session** — A long-lived authenticated state, persisted via cookie and refreshed on activity, that keeps a User logged in indefinitely until explicit logout.
- **Email Log** — A per-send record (Trip, send date, sent/failed + reason, weather snapshot/reference) used for de-duplication, debugging, and admin monitoring. The single per-send source of truth.
- **Forecast History** — The day-by-day record of forecast snapshots captured for a Trip across its Forecast Window and trip days, enabling day-over-day comparison. It is the time-series of per-send snapshots stored on the Email Log (no separate forecast store); a retention sweep nulls those snapshots ~30 days after the Return Date while keeping the send outcome rows.
- **Narration** — A short, calm, AI-generated line in a Daily Digest that describes notable day-over-day forecast changes (e.g., a dropping rain probability), grounded strictly in stored Forecast History. Enhancement-only — omitted when absent or on failure, never blocking the digest.
- **Plan** — The account entitlement field driving the ads/ad-free switch: `free` (ad-supported, the v1 default) or `ad_free` (paid, architecture-ready, not sold in v1).
- **Free-Tier Cap** — A cost-control limit of **three active Trips** per free-tier User. Attempting to add a fourth active Trip shows a calm "trip limit reached" message — no upsell, no billing. Completed/past Trips do not consume a slot; a paused Trip does not occupy one.
- **Promo Unit** — A single calm, native promotional element (v1: a weather-keyed Amazon Associates affiliate recommendation) rendered in a dedicated Daily Digest slot below the forecast for free-tier Users; suppressed for ad-free Users; never blocks or delays a send.
- **Promo Event** — A recorded promo impression or click (keyed by Trip + send date + promo), used to measure affiliate engagement (SM-4); written via the signed attribution redirect (FR-18).
- **User** — An account identified by email address that owns Trips.
- **Admin** — A privileged User (single `is_admin` flag) who can access the admin monitoring view.
