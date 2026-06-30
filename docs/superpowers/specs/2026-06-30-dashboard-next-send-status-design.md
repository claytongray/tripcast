# Dashboard per-trip next-send status

**Date:** 2026-06-30
**Status:** Approved — ready for implementation plan
**Series:** Spec B of two (the user settings page was Spec A)

## Problem

The dashboard trip cards show the destination, a status badge, the date range,
and a countdown to departure. They never say **when the next forecast email will
actually arrive**. A new user who just set up a trip 70 days out has no on-page
signal that their first forecast is weeks away; a user mid-window has no signal
that one is landing tomorrow morning. The success screen states the first-send
date once, but the dashboard — the place users return to — does not.

## Goal

On each upcoming trip card, surface the next send:

- **In the send window** (sending at the next 09:00 ET): a pulsing green beacon
  plus "Next forecast this morning" / "tomorrow morning".
- **Before the window** (e.g. 70 days out): no beacon, "First forecast in 63
  days · Sep 6" — upfront about both the distance and the concrete date.
- **Paused**: the existing "Paused — no emails until you resume." note, no beacon.
- **Past trips**: unchanged.

All date math stays server-side, consistent with the single cadence authority
(AD-11), so there is no client clock or timezone drift.

## Server

### `CadencePredicate::nextSendDate(Trip $trip, CarbonInterface $now): ?CarbonImmutable`

One new method returning the next calendar date the digest will send for a trip,
or `null` when none is upcoming. It folds the cases into one place:

- Returns `null` if the trip is not active, is soft-deleted, or its user is
  unconfirmed or opted out (mirrors the eligibility checks in `isDue`).
- Applies the **09:00 America/New_York** send boundary (the fixed `digests:send`
  schedule, AD-2/AD-7): the earliest candidate date is today if `now` is before
  09:00, otherwise tomorrow. The send hour is a private constant in the predicate
  with a comment tying it to the scheduler.
- Clamps the candidate into the send window `[departure − horizon, return]`:
  - candidate before window-open → window-open date (the first send);
  - candidate inside the window → the candidate date;
  - candidate after `return_date` → `null`.

`horizonDays()` (currently 7) is the existing private knob; reuse it.

`firstSendDate` is left untouched — it still backs the success screen. The new
method is the more complete, display-facing version; the two are not merged in
this spec to keep the change focused.

### `DashboardController`

Compute `$now = Carbon::now('America/New_York')` once. For each **upcoming**
card add three fields, all derived from the predicate:

- `next_send_date`: `?string` — `nextSendDate(...)?->toDateString()`.
- `days_until_send`: `?int` — whole calendar days from today to `next_send_date`
  (0 = today / "this morning", 1 = tomorrow, N otherwise), or `null`.
- `is_sending`: `bool` — `isDue($trip, $today)`; true exactly when the trip is in
  its active send window now. Drives the beacon.

Past (completed) cards are unchanged.

## Client — `Dashboard.vue`

`TripCard` gains `next_send_date: string | null`, `days_until_send: number |
null`, and `is_sending: boolean`.

Keep the existing line (`dateRange · {countdown(days_until_departure)}`). Add:

- **Beacon** beside the destination/badge when `is_sending`: a small pulsing
  green dot — an `animate-ping` ring over a solid dot — using a success/positive
  design token if one exists, otherwise green.
- **Send-status line**:
  - `is_sending` → "Next forecast **this morning**" when `days_until_send === 0`,
    else "**tomorrow morning**" (`=== 1`).
  - else if `next_send_date !== null` → "First forecast in **{days_until_send}
    days · {Mon D}**" (singular "1 day" handled).
  - paused → the existing paused note (no new line).

Date formatting reuses the card's existing `formatDay` helper; no new client date
math beyond reading the server-provided values.

## Testing

**Unit — `CadencePredicate::nextSendDate`** (clock pinned to America/New_York):

- Before the window → the window-open date (`departure − 7`).
- In the window, `now` before 09:00 → today.
- In the window, `now` at/after 09:00 → tomorrow.
- `now` past `return_date` → `null`.
- Paused / completed trip → `null`.
- Unconfirmed user / opted-out user → `null`.

**Feature — `DashboardController`** (clock pinned):

- An in-window active trip card carries `is_sending = true` and the correct
  `days_until_send` (0 or 1 depending on the pinned hour).
- A far-off active trip card carries `is_sending = false`, the right
  `days_until_send`, and a `next_send_date` equal to `departure − 7`.

## Out of scope

The send pipeline, the success screen, `firstSendDate`, and any change to how
trips are ordered (already by departure date). This is display only.
