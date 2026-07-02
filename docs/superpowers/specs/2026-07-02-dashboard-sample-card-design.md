# Dashboard "Send a sample" card — Design

**Date:** 2026-07-02
**Status:** Approved

## Goal

Let signed-in users (especially new ones waiting days for their first real digest)
request the existing sample tripcast email for Reykjavik, Iceland directly from the
dashboard, so they can preview what they'll get each morning.

## Background

A public sample flow already exists (2026-06-30-sample-tripcast-mvp-design.md):
`POST /sample` (`SampleController::store`) takes an email, issues a magic link,
queues `SampleDigestMail` for the fixed Reykjavik demo trip, and records a
`SampleRequest` for acquisition tracking. This feature reuses the mail + demo-trip
machinery for authenticated users, without the acquisition side effects.

## Card UI (`resources/js/pages/Dashboard.vue`)

- A new section rendered **below the trip lists** — after Past trips, or after the
  empty state for users with no trips. **Always visible** to every user.
- Same visual language as sibling cards: `rounded-md border border-hairline
  bg-surface-raised p-5`.
- Copy (lowercase "tripcast" per copy voice):
  - Heading: **Want to see one now?**
  - Body: *We'll email you a sample tripcast for Reykjavik, Iceland so you can get
    a preview of what your trips will look like.*
  - Button: **Send me a sample**
- No email input — the sample goes to the account's email.
- On success: `vue-sonner` toast ("Sample on its way — check your inbox.") and the
  button flips to a disabled "Sent" state for the rest of the visit (local ref,
  no persistence).
- On error (rate limit): `toast.error` with the server's message.
- Route called via Wayfinder-generated action, matching existing dashboard posts.

## Backend

- New authenticated route `POST /sample/self` → second action on the existing
  `SampleController` (e.g. `storeForSelf`), inside the auth middleware group.
- Behavior:
  - Reuses the private `sampleTrip()` demo-trip builder and the `SampleForecast`
    snapshot (live 7-day Reykjavik forecast, cached by the service).
  - Queues the same `SampleDigestMail` to `$request->user()->email`.
  - `getStartedUrl` = `route('dashboard')` — no magic link is issued; the user is
    already signed in, so the email's "Get started →" CTA just returns them to
    the dashboard.
  - **No `SampleRequest` record** — that table feeds the admin Samples page for
    lead/acquisition tracking; signed-in users would pollute it.
  - Per-user rate limit of 3 sends per hour. Over the limit, respond with a calm
    validation error / flash surfaced as a toast — not a 429 error page.
- Response: redirect back (Inertia), success handled client-side via `onSuccess`.

## Not doing (YAGNI)

- No new mailable or email copy variant.
- No server-side "already sent" persistence or card hiding.
- No destination choice — the sample is always Reykjavik, Iceland.

## Testing

Pest feature test (new file or alongside existing sample tests):

1. Authenticated user posts → `SampleDigestMail` queued to the user's own email,
   with the dashboard URL as the CTA.
2. No `SampleRequest` row is created.
3. Guest posting → redirected to login.
4. Fourth request within the hour → rejected with the friendly rate-limit message,
   no mail queued.
