# Welcome + first tripcast on signup

**Date:** 2026-07-03
**Status:** Approved (design)

## Goal

Get real value into a new user's inbox as soon as possible. When someone signs
up by creating a trip that is already inside the forecast window, their welcome
email should *carry their first real tripcast* — a "welcome + tripcast" in one
email. When the trip is outside the window (too far out to forecast), send the
welcome with a heads-up on when the first tripcast will arrive, plus an offer to
see a sample now.

The driving principle: **if we have the data, show value immediately** rather
than making the user wait for the next scheduled send.

## Guiding constraints (unchanged)

- **Confirmed-first stays.** A brand-new (unconfirmed) signup still receives only
  the magic-link email first. The welcome (and any tripcast) fires the moment
  they click the link and confirm. We do not send forecast data before
  confirmation.
- **One tripcast per trip per day.** The immediate in-window send must not
  produce a second tripcast for the same trip on the same day.
- **Single cadence authority.** "Is this trip in-window today?" is decided by the
  existing `CadencePredicate` — the same predicate the 7am ET daily job uses.
  No duplicate window math.

## Trigger points

Both existing entry points already funnel through the `SendWelcomeEmail` action;
we add one branch inside that path:

- **Already-confirmed user** creates a trip → welcome path fires immediately
  (`CreateTrip::handle`).
- **New user** confirms via magic link → welcome path fires per trip created
  while unconfirmed (`MagicLinkController::consume`).

The new decision in the welcome path: **is this trip in-window today?**, using
`CadencePredicate` (`departure − horizon_days ≤ today ≤ return`, user confirmed,
trip active, not opted out).

## In-window path — welcome-mode tripcast (immediate catch-up)

If the trip is already inside the window, the user has effectively "missed" the
tripcast(s) that would have gone out before they signed up. We send one
immediately as a catch-up, as if they had signed up the day before.

- Instead of the plain welcome, dispatch `SendTripDigest` in a new **welcome
  mode**.
- Reuse the entire existing path: **claim `(trip_id, today ET)` slot → fetch
  weather once → persist snapshot → render → retry-safe send.** No duplicated
  dedup/snapshot/retry logic.
- Claiming today's `(trip_id, send_date)` slot means the **7am ET daily job skips
  this trip today** — exactly one tripcast per day. Normal cadence resumes the
  next morning.
- **Rendering:** reuse the existing forecast partials, with a **welcome intro
  block at the top** (greeting + trip framing). Implemented as a `welcome` flag
  on the digest template that conditionally renders the intro; forecast rows,
  footer, and legal footer are identical to a normal tripcast. Welcome-flavored
  subject line.

## Out-of-window path — welcome heads-up + sample offer

- Send the existing `WelcomeMail`, which already states the first-tripcast date
  via `CadencePredicate::firstSendDate()`.
- Add a **sample CTA** — "Want to see one now? Send me a sample" — wired to the
  **existing generic Reykjavik sample** (`SampleController` / `SampleDigestMail`)
  through the existing sample-trigger mechanism. No new sample logic.

## Multiple trips at confirmation

Each trip created while unconfirmed is evaluated **independently**: an in-window
trip gets a welcome-mode tripcast, an out-of-window trip gets the heads-up
welcome. A single confirmation can therefore produce a mix.

## Edge cases

- **Weather fetch fails in welcome mode.** It is a normal tripcast send — the
  existing 3-attempt retry and terminal `failed` status apply. If it ultimately
  fails, the next 7am run resumes cadence (the window is still open). No separate
  fallback welcome for v1 (YAGNI); noted as a known tradeoff.
- **Slot already claimed today.** The claim-first check prevents a double-send.
  Not realistically reachable for a brand-new trip, but handled by the existing
  mechanism.

## Testing (Pest feature tests)

- Confirmed user, in-window trip → welcome-mode tripcast dispatched, slot claimed,
  same-day 7am job skips the trip.
- Confirmed user, out-of-window trip → heads-up welcome with sample CTA, no
  tripcast.
- New user confirms, in-window trip → welcome-mode tripcast.
- New user confirms, out-of-window trip → heads-up welcome.
- Mixed multi-trip confirmation → each trip branches correctly.
- Update existing `WelcomeMail` tests for the new sample CTA.

## Deferred / future scope

- Historical-data preview of the user's *own* destination for the out-of-window
  case (pull past conditions from the API), replacing the generic Reykjavik
  sample. Explicitly out of scope here.

## Open copy details (non-blocking)

- Subject line for the combined in-window email (default: keep
  "You're all set for {city}").
- Exact sample CTA wording.
