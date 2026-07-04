# Admin-triggered trip digest — design

**Date:** 2026-07-04
**Status:** Approved (brainstorm) → ready for implementation plan

## Problem

Admins have no way to send a trip's daily digest on demand. Two real needs:

1. **Support** — a user reports "I never got my email"; the admin wants to
   (re)send today's digest to that user, even if the scheduled send already ran.
2. **Preview** — the admin wants to eyeball the real rendered digest for any
   trip without emailing the user.

Today the only send path is the scheduled `digests:run` → `SendTripDigest` job.
The Admin → Monitoring page lists every trip read-only, with no actions.

## Decisions (locked)

- **Recipient is chosen per send.** Each trip exposes two actions: **Send to
  owner** and **Send to me** (the acting admin's address).
- **Send to owner** force-delivers past the same-day dedup lock, but is logged
  **out-of-band** — never written to `email_logs`, so it never distorts
  send-health metrics — and **fires no promo impression**.
- **Send to owner honors suppression.** If the owner is opted out
  (`email_opted_out`, AD-13) or unconfirmed (`email_verified_at` null, AD-11),
  the send is refused and the admin is told why. The scheduled path already
  excludes these users; the admin action must not be a backdoor around AD-13.
- **Send to me** renders the digest exactly as the owner would see it —
  including the entitlement-gated promo slot (`shouldShowPromo()`, AD-19) — but
  **records no promo impression**. It is always allowed (it is a preview to the
  admin, not a send to the user), regardless of the owner's suppression state.
- **Shared compose logic (approach A).** The forecast + narration + promo
  assembly is extracted into one `DigestComposer` service used by both
  `SendTripDigest` and the new admin sender. No duplicated narration seam.

## Architecture

Three units, each with one job:

### 1. `App\Services\Digest\DigestComposer` (new)

The single authority for turning a trip + send-date + weather snapshot into a
ready-to-send `DigestMail`. Owns the narration and promo-selection logic that
currently lives as private methods on `SendTripDigest`.

```
compose(Trip $trip, array $snapshot, string $sendDate, bool $welcome = false): Composed
```

Returns a small `Composed` value object: `{ mail: DigestMail, promo: ?Promo }`.
The caller decides what to do with `promo` (the job records an impression; the
admin sender never does). Narration reads the trip's prior snapshot from
`email_logs` read-only (AD-9) exactly as today; all failures inside compose are
swallowed to a null line / null promo, never thrown (AD-17).

- `narrate(Trip, array $snapshot, string $sendDate): ?string` — moved verbatim
  from `SendTripDigest::narrate()` + `narrateSafely()`, including the shadow
  compare log.
- `selectPromo(Trip, array $snapshot, string $sendDate): ?Promo` — moved
  verbatim from `SendTripDigest::selectPromo()`, entitlement-gated (AD-19).

### 2. `SendTripDigest` (refactored, behavior-preserving)

Delegates narration + promo to `DigestComposer`. Its claim-first `email_logs`
flow, snapshot persistence, bounded delivery retry, terminal status, and promo
**impression recording** are unchanged. This is the risk surface — its existing
test suite must stay green with zero assertion changes.

### 3. `App\Services\Digest\AdminDigestSender` (new)

Drives one admin-triggered send end to end, synchronously (so the admin gets an
immediate pass/fail):

```
sendToOwner(Trip $trip, User $admin): AdminEmailSend   // throws SuppressedRecipientException if refused
sendToAdmin(Trip $trip, User $admin): AdminEmailSend
```

Flow (both):
1. `sendToOwner` first checks suppression: opted-out or unconfirmed → throw
   `SuppressedRecipientException` with a human reason. `sendToAdmin` skips this.
2. Resolve `sendDate` = `now('America/New_York')->toDateString()` (same anchor
   as `digests:run`).
3. Fetch a fresh forecast via `WeatherProvider::fetchForecast(lat, lng, tz)`.
   A `WeatherProviderFailedException` → record a `failed` audit row + rethrow a
   friendly message.
4. `DigestComposer::compose(...)` → `DigestMail` (+ promo, ignored for
   impressions).
5. `Mail::to($recipient)->send($mail)` — owner's address, or the admin's.
6. Write an `admin_email_sends` audit row (`sent`, or `failed` + reason on a
   delivery throw). **No `email_logs` write. No `PromoEvent`.**

### 4. Persistence — `admin_email_sends` (new table)

Out-of-band audit trail. Deliberately separate from `email_logs` so it never
collides with the sacred `unique(trip_id, send_date)` dedup index (AD-3) and is
invisible to `EmailHealthMetrics`.

| column          | type                          | notes                                  |
|-----------------|-------------------------------|----------------------------------------|
| id              | bigint pk                     |                                        |
| trip_id         | fk trips, cascade on delete   |                                        |
| admin_user_id   | fk users, cascade on delete   | who triggered it                       |
| recipient       | string                        | `owner` \| `admin`                     |
| recipient_email | string                        | the address actually mailed            |
| status          | string                        | `sent` \| `failed`                     |
| failure_reason  | text null                     | on failure                             |
| timestamps      |                               | `created_at` is the trail's time axis  |

Model: `App\Models\AdminEmailSend` with `belongsTo(Trip)` / `belongsTo(User,
'admin_user_id')` and `recipient`/`status` string constants.

## HTTP surface

New mutating admin controller `App\Http\Controllers\Admin\TripDigestController`
(follows the `PromoItemController` convention — POST, inside the existing
`auth` + `can:admin` group, flashes `status` back for Inertia to render).

```
POST admin/trips/{trip}/digest   name: admin.trips.digest.send
  body: { recipient: 'owner' | 'admin' }   (validated)
```

- Resolves the acting admin from `$request->user()`.
- `owner` → `AdminDigestSender::sendToOwner`; catches
  `SuppressedRecipientException` → redirect back with an error flash
  (`Can't send: <reason>`).
- `admin` → `AdminDigestSender::sendToAdmin`.
- Success → redirect back with `status`: `Sent to owner (<email>).` /
  `Sent to you (<email>).`
- Delivery failure → redirect back with an error flash carrying the reason.

`monitoring()` gains a small per-trip `adminSends` list (recent
`admin_email_sends`, newest first: recipient, status, date) so the trail is
visible, mirroring how `emailLogs` is already surfaced.

## Frontend — `Admin/Monitoring.vue`

Per trip card, an actions row with two buttons using Wayfinder + Inertia
`router.post`:

- **Send to me** — posts `{ recipient: 'admin' }`. Always enabled.
- **Send to owner** — posts `{ recipient: 'owner' }`. Guarded by a lightweight
  confirm ("Send a real digest to <owner>?"). If the owner is opted-out or
  unconfirmed, the button is disabled with a tooltip/subtext explaining why
  (the controller enforces this regardless; the UI just avoids a pointless
  round-trip). This needs two new fields on the monitoring payload per trip:
  `owner_opted_out` and `owner_confirmed`.
- A row shows the last admin send outcome (from `adminSends`), and the page
  renders flash `status` / error at the top.

Buttons are disabled while the request is in flight (`processing`).

## Error handling

- **Suppressed owner** → `SuppressedRecipientException`, caught in the
  controller, shown as an error flash. No audit row (nothing was attempted).
- **Weather fetch fails** → `failed` audit row + friendly error flash.
- **Delivery throws** → `failed` audit row + error flash.
- **Compose internals** (narration/promo) never throw (AD-17) — worst case a
  digest with no day-over-day line and no promo slot, same as the live path.

## Testing

Feature tests (`AdminTripDigestTest`):
- Non-admin / guest → 403 / redirect (Gate enforced).
- Send to me → mails the admin's address; writes one `admin_email_sends`
  (`admin`, `sent`); asserts **no** `email_logs` row and **no** `PromoEvent`.
- Send to owner (eligible) → mails the owner; `admin_email_sends`
  (`owner`, `sent`); no `email_logs` row; no `PromoEvent`; delivers even when a
  `sent` `email_logs` row already exists for today (force past dedup).
- Send to owner (opted-out) → refused, error flash, no mail, no audit row.
- Send to owner (unconfirmed) → refused, error flash, no mail, no audit row.
- Weather failure → `failed` audit row + error flash, no mail.
- `Mail::fake()` for delivery/recipient assertions; `FakeWeatherProvider` bound.

Regression:
- Full existing `SendTripDigest` suite stays green unchanged (proves the
  `DigestComposer` extract is behavior-preserving).
- A focused `DigestComposer` unit test: narration reads prior snapshot; promo
  gated by `shouldShowPromo()`.

## How you test it (manual)

1. `npm run dev` running; log in as `claytonjgray@gmail.com` at
   `/admin/monitoring`.
2. On any trip, click **Send to me** → the rendered digest lands in the local
   log mailer / Mailtrap. Confirm countdown + forecast + (if free plan) promo.
3. On a trip you own that already sent today (e.g. the Edinburgh card),
   **Send to owner** → confirm it delivers anyway (dedup bypassed) and that
   `/admin/emails` Sent/Failed totals do **not** move.
4. Opt a user out (or use an unconfirmed one) and confirm **Send to owner** is
   disabled / refused with a clear reason.

## Out of scope (YAGNI)

- Choosing a send-date other than "today".
- Editing/queuing; the admin send is synchronous and immediate.
- Surfacing admin-send analytics anywhere beyond the per-trip trail on
  Monitoring.
- Any change to the scheduled send cadence or `email_logs` schema.
