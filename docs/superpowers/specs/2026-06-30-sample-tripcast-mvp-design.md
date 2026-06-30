# Public sample tripcast (MVP)

**Date:** 2026-06-30
**Status:** Approved — ready for implementation plan

## Problem

A first-time visitor has to commit (enter a trip, give an email, confirm) before
they ever see what a tripcast looks like. There is no low-commitment way to
*experience the product* and no way to measure interest from people who aren't
ready to set up a trip yet.

## Goal

A public "Send me a sample" hook on the landing page: a visitor enters their
email and receives a real-looking sample tripcast for a fixed demo destination.
The sample's footer links to "Get started", which doubles as the account
verify/login — landing them on their dashboard to create a real trip. Every
accepted request is recorded so the team can quantify demand and, later,
conversion.

## Scope

- **One destination: Reykjavik, Iceland.** Cached-live forecast.
- Public landing modal with a single email field.
- A sample email whose "Get started" CTA is a magic link.
- A `sample_requests` table recording each accepted request.

### Out of scope (future)

Destination picker / multiple cities, the logged-in dashboard sample + share
CTA, and any admin UI for the tracked data (the data is captured and queryable;
surfacing it is later).

## Flow

1. Landing page shows a "Send me a sample" affordance (visible to guests).
2. Clicking opens a modal with one email input and a send button.
3. Submit posts to `POST /sample` (`sample.store`).
4. The controller: validates, throttles, creates-or-matches the user and issues a
   magic-link URL, records a `sample_requests` row, and queues the sample email.
5. The modal shows a calm "Your sample is on its way to {email}" success state
   (no redirect).
6. The recipient opens the email and clicks "Get started →" — a standard magic
   link. The existing consume flow confirms the email, logs them in, and (no
   trips yet) redirects to the dashboard, for new and existing emails alike.

## Endpoint, validation, throttle

- Route: `POST /sample` named `sample.store`, guest-facing (not behind `auth`).
- Validation (a `SampleRequest` form request): `email` required, valid, max 255.
- **Throttle:** per-email and per-IP, sharing the magic-link rate limiter
  (`tripcast.magic_link.throttle.*`). Because a sample issues a magic link, this
  endpoint must not become an unthrottled way to email login links. Mirrors
  `MagicLinkController::ensureNotThrottled`.
- Calm validation-error messaging consistent with the existing auth/landing copy.

## Destination, caching, fallback

- Config `tripcast.sample` holds the single destination:
  `{ key: 'reykjavik', label: 'Reykjavik, Iceland', lat, lng }`.
- A `SampleForecast` service exposes `forecast(): Forecast`:
  - `Cache::remember("sample-forecast:reykjavik:{etDate}", endOfEtDay, fn => $weather->fetchForecast($lat, $lng))`
    — one live fetch per day, served from cache thereafter.
  - **Static fallback:** if the live fetch throws
    (`WeatherProviderFailedException`), return a baked-in synthetic snapshot
    (PreviewDigests-style) so the public endpoint never sends a broken or empty
    sample. The fallback result is not cached, so the next request retries live.
- The sample trip is an **unsaved** `Trip` (PreviewDigests pattern) with a short
  window starting tomorrow (departure = tomorrow, return = tomorrow + 2), sized
  to sit inside the ~3-day live forecast reach so the forecast renders complete.

## The "Get started" link is a magic link (refactor)

Extract token issuance from `RequestMagicLink`:

- New `issue(string $email): array{user: User, url: string, ttl_minutes: int}` —
  does the existing lowercase/normalize, `firstOrCreate`, atomic token rotation,
  and returns the `magic.consume` URL **without** sending `MagicLinkMail`.
- `handle()` becomes `issue()` followed by queueing `MagicLinkMail` — behavior
  unchanged for existing callers.
- The sample flow calls `issue()` and embeds the returned URL as the email's
  "Get started" CTA.

`MagicLinkController::consume` is unchanged: first consume confirms the email,
logs the user in, and — with no trips — redirects to the dashboard. This already
covers both a brand-new email and an existing account.

## Sample email

- `SampleDigestMail` (a `Mailable`) + an `emails.sample-digest` Blade view.
- Reuses the forecast day-row markup from the daily digest so the sample looks
  like the real product.
- Footer is replaced with the "Ready to create your own? Get started →" CTA
  pointing at the magic-link URL.
- No `List-Unsubscribe` headers, no feedback links, no promo — a sample is a
  one-off, user-requested email, not a subscription.
- Queued (like `MagicLinkMail`) so a slow transport can't block or 500 the public
  request after the row is written.

## Tracking — `sample_requests`

A new table and `SampleRequest` model. One row per **accepted** request:

- `id`
- `user_id` — FK to `users` (always present; `issue()` creates-or-matches).
- `email` — the address as requested (snapshot).
- `destination` — the destination key (`reykjavik`); future-proofs multi-city.
- `created_at` (timestamps).

This answers both questions directly: **how many samples sent** = row count;
**who asked** = distinct `user_id`. Repeat requests from the same email write
multiple rows — intended, since the metric is "how many times sent." Linking to
`user_id` enables later conversion analysis (did a sampler create a trip?).

The row is written when the request is accepted and the mail is dispatched
(inside the controller, after `issue()`), not gated on async delivery success.

## Testing

**Feature (`POST /sample`):**

- A valid email queues a `SampleDigestMail` to that address, creates-or-matches
  the user, and writes exactly one `sample_requests` row.
- A repeat request writes a second row.
- An invalid/missing email is rejected with a validation error and writes no row.
- The throttle trips after the configured attempts (per-email and per-IP).
- The embedded magic link consumes successfully → user confirmed and redirected
  to the dashboard.

**Unit (`SampleForecast`):**

- Caches the forecast per ET day (a second call within the day does not re-fetch).
- Falls back to the static snapshot when the provider throws, and does not cache
  the fallback.

**Mail (`SampleDigestMail`):**

- Renders the "Get started" magic-link CTA.
- Omits unsubscribe headers and feedback links.

## Implementation notes

- Reuse `WeatherProvider` (`fetchForecast` → `Forecast` → `toArray()` snapshot)
  and the existing digest day-row Blade markup.
- Follow `MagicLinkController` for the throttle helpers and `RequestMagicLink`
  for token issuance.
- Landing modal can reuse the existing `Dialog` UI component and an Inertia
  `useForm` post, matching the dashboard's add-trip panel conventions.
