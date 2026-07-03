# Timezone-aware 7am sends

**Date:** 2026-07-03
**Status:** Draft — awaiting review before implementation plan
**Supersedes:** AD-7's single-clock assumption (one fixed 09:00 America/New_York
send for everyone; home timezone "collected but unused"). This feature makes the
send clock per-trip and phase-aware, and actually wires up the home timezone.

## Problem

Every digest sends at a single fixed **09:00 America/New_York** for all users
(`digests:send` scheduled `->dailyAt('09:00')`, `CadencePredicate::SEND_HOUR = 9`).
Two things are wrong with that:

1. **9am is late** for a morning weather email — we want 7am.
2. **A single zone is wrong for a traveler.** Someone based in San Francisco with
   a trip to Edinburgh (UTC−7 vs UTC+1) receiving "7am Pacific" gets the email at
   ~3pm local *while they're in Edinburgh* — the day is half over. Conversely,
   sending in the destination zone the whole time delivers pre-trip planning
   emails at ~11pm at home.

The right mental model is **"7am wherever I am right now."** A traveler's "here"
changes exactly once per trip: they're **home before departure** and **at the
destination after**. So the send hour is a fixed 7am and the system resolves
*which zone that 7am lands in* per trip, per day.

## Goal

- Default send hour becomes **7am**, sourced from one config knob.
- Each digest sends at **7am in the zone applicable to that send date**:
  - send date **on or before** `departure_date` → the traveler's **home** zone
    (`users.timezone`);
  - send date **after** `departure_date` → the trip's **destination** zone
    (`trips.destination_timezone`).
- Home and destination zones are actually captured (today neither is used for
  sends; home is always the ET default).
- The scheduler runs **hourly** and dispatches whichever trips have just reached
  7am in their applicable zone and have not yet sent for that local day.

Non-goal (this spec): letting a user pick a *different* hour than 7am, minute
granularity, or any location awareness beyond the departure-date phase switch.

## Decisions (resolved during brainstorming)

- **Fixed 7am, config-backed — not user-editable yet.** The hour lives in
  `config('tripcast.send.default_hour')`. A per-user editable hour is a clean
  fast-follow: that same config value becomes the column default. Out of scope
  here.
- **Phase-aware zone**, not home-only (fails the at-destination case) or
  destination-only (fails the planning case). The extra input over
  destination-only — the home zone — is one field we already have a column for.
- **Hourly scheduler** with the hour/zone match done in PHP over the small
  in-window candidate set (SQL cannot convert arbitrary per-row zones).
- **Destination zone is captured free** from the WeatherAPI response
  (`location.tz_id`), which we already fetch.

## Data model

### `config/tripcast.php` — `send.default_hour`

Add to the existing `send` block:

```php
'default_hour' => min(23, max(0, (int) env('TRIPCAST_SEND_HOUR', 7))),
```

The single authority for the send hour. Both the scheduler and the cadence
predicate read it; there is no second literal.

### `trips.destination_timezone` — new column

`string`, **nullable** (backfill is lazy). The IANA zone of the destination
(e.g. `Europe/London`), captured from the weather provider. Nullable so existing
trips and any fetch that lacks a zone fall back to the home zone until filled.

### `users.timezone` — start populating it

The column already exists (`->default('America/New_York')`). No migration; we
begin writing a real value (see Capture below).

## The spine: `send_date` becomes zone-aware

`send_date` is both the `unique(trip_id, send_date)` dedup key (AD-3) and the
"which day's forecast" label. Today it is `now('America/New_York')->toDateString()`.

New rule: **`send_date` is the applicable zone's calendar date** at the moment of
sending. A trip is dispatched when:

- `now` converted to its applicable zone is **at or past 7am**, and
- no `email_logs` row exists for `(trip_id, that local date)`.

The existing unique index + claim-first job (AD-3) continue to guarantee exactly
one send per trip per local day, unchanged. Using **`>= 7am`, once per local
day** (rather than `== 7`) makes the selector both **DST-safe** (a skipped or
repeated wall-clock hour cannot skip or duplicate a send) and **catch-up-safe**
(a missed hourly run still sends later that day).

### Zone resolution

```
applicableTimezone(Trip, sendDate):
    sendDate <= departure_date  →  user.timezone
    sendDate  > departure_date  →  destination_timezone ?? user.timezone
```

Phase boundary is `departure_date`: home through departure day (the traveler is
likely still home / in transit that morning), destination from the first full day
onward. **Guaranteed** across the transition, in both travel directions, the
`send_date` values stay consecutive — no double-send, no skipped day (proven by
the unique key + monotonic local dates). The one accepted fine-grained edge: on
the single transition day the first destination send may land at the zone-offset
hour rather than exactly 07:00; this is a tested boundary, never a correctness
problem.

## Scheduler: daily → hourly

`routes/console.php`:

- `digests:send` schedule changes from `->dailyAt('09:00')->timezone(...)` to
  `->hourly()` (UTC; zone logic is per-row, so the schedule itself is
  zone-agnostic).

`SendDailyDigests` command:

- Selection splits into an **SQL prefilter** (active, not soft-deleted, verified &
  not opted-out user, and window overlapping "now" with a ±1-day tolerance so no
  zone is missed) and a **PHP refinement** (per candidate: resolve applicable
  zone, convert `now`, check `>= 7am`, check window membership on the local date,
  check no existing `email_logs` row for that date). Candidate set is tiny.
- Dispatches one `SendTripDigest($trip, $localDate)` per match — the job is
  unchanged.

## Ripples from going hourly

1. **Purge sweep** (`PurgeForecastHistory`, AD-16) currently piggybacks on the
   once-daily run. Move it to its **own daily schedule** (e.g. `->dailyAt('03:00')`
   ET) so it does not run 24×/day. `digests:send` no longer calls it.
2. **Heartbeat / liveness** (AD-14): the dead-man's-switch monitor's expected
   period changes daily → hourly (`TRIPCAST_HEARTBEAT_URL` is currently unset in
   prod, so low blast radius; document the new cadence in the env checklist). The
   admin liveness snapshot becomes "last hourly run"; `healthy = !(due > 0 &&
   dispatched == 0)` per run still holds, and most hours legitimately show
   `due: 0` (nobody hit 7am-local that hour). Note this in the admin copy so an
   empty hour does not read as a fault.
3. **Predicate signature**: `isDue`/`dueOn` take a *date* today; they need the
   actual **`now` instant** to compare hour-in-zone. The display companions
   (`firstSendDate`, `nextSendDate`, the dashboard beacon at `Dashboard.vue:448`,
   the `TripAdded` "first forecast" copy) become zone-aware and stop hardcoding
   "9am"/"morning at 9".

## Capturing the two zones

### Home zone → `users.timezone`

- Frontend sends `Intl.DateTimeFormat().resolvedOptions().timeZone` (e.g.
  `America/Los_Angeles`) as a `timezone` field on the trip-create / magic-link
  request.
- Validate with Laravel's `timezone` rule; fall back to `America/New_York` if
  absent or invalid.
- Write it in the `firstOrCreate` **create-attributes** in `CreateTrip` (and the
  magic-link path) so an existing user keeps theirs.
- Add a home-timezone field to the user settings page so it is correctable (a
  user who relocates between trips).

### Destination zone → `trips.destination_timezone`

- Add a `timezone` field to the `Forecast` value object; `WeatherApiProvider`
  reads `location.tz_id` from the response (already present, currently unread).
- Persist it on the trip when the forecast is first fetched for it.
- **Backfill**: existing trips have `null` → they fall back to the home zone (the
  resolver's `?? user.timezone`) until their next fetch fills it. Optionally a
  one-off command to derive zones for active trips from `latitude`/`longitude`.

## Testability (a first-class requirement)

Time-based behavior must be verifiable without waiting for wall-clock 7am
anywhere. Two affordances:

- **`digests:send --now="<datetime with zone>"`** — a new, **production-guarded**
  option that sets `Carbon::setTestNow()` for that single run, so the real
  selector can be exercised as of any instant:
  `php artisan digests:send --now="2026-08-01 07:05 Europe/London"`. Locally
  `MAIL_MAILER=log`, so the rendered email lands in the log (or Mailpit).
- Existing tools remain: `digest:send {trip} --date= --to=` (force one trip's
  digest to any inbox for a chosen day/phase, bypasses cadence) and
  `digests:preview` (three sample render states).

## Testing

The cadence predicate is the single authority (AD-11), which bounds the matrix.
All clock-pinned via `Carbon::setTestNow(...)` (the established pattern).

**Unit — zone resolution:** table of `(home_tz, dest_tz, departure, sendDate)` →
expected zone, covering pre-trip, departure day, and at-destination.

**Feature — selector** (frozen `now`), for eastward (SF→Edinburgh) and westward
(Edinburgh→SF):

- Pre-trip, `now` = 07:00 home zone, in window → dispatches with the home-zone
  local date.
- At destination, `now` = 07:00 destination zone → dispatches with the
  destination-zone local date.
- `now` before 07:00 local → no dispatch.
- **Idempotency**: running the selector at 07:00, 08:00, 09:00 local produces
  exactly one `email_logs` row for that local date.
- **Transition day**: a single trip across `departure_date` yields consecutive
  `send_date`s with no duplicate and no gap.
- **DST boundary**: a send-hour that falls on a spring-forward / fall-back date
  still sends exactly once.

**Feature — capture:**

- A trip-create request carrying a valid `timezone` writes `users.timezone`; an
  invalid/absent one falls back to the ET default.
- A WeatherAPI stub returning `location.tz_id` persists
  `trips.destination_timezone`; a response without it leaves `null` and the
  resolver falls back to the home zone.

**Feature — display:** `nextSendDate`/dashboard beacon reflect the applicable
zone (e.g. an at-destination trip's "next forecast" is computed in the
destination zone).

**Command:** `digests:send --now=` dispatches the expected set for a given
instant and is refused in production.

## Out of scope

- Per-user or per-trip **editable** send hour, and minute granularity.
- Any location awareness beyond the `departure_date` phase switch (no live
  geolocation, no multi-leg trips).
- Changing the digest **content** (still the destination forecast for the send
  date) or the render pipeline.
