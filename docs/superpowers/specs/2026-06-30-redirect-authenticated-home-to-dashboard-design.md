# Redirect authenticated users from `/` to their dashboard

**Date:** 2026-06-30
**Status:** Approved — ready for implementation plan

## Problem

A returning, logged-in user who visits `tripcast.com` (`GET /`) lands on the
new-user trip-setup form, not their trips. The home route renders the `Landing`
page for guests and authenticated users alike. Existing users expect to see
their trips when they return; their trips only live at `/dashboard`, and nothing
sends them there.

## Goal

When an authenticated user requests `GET /`, redirect them to
`route('dashboard')`. Guests continue to see the `Landing` trip-setup form
unchanged.

## Approach

In-controller guard at the top of `LandingController@show`:

```php
if ($request->user() !== null) {
    return redirect()->route('dashboard');
}
```

The method's return type widens to `Response|RedirectResponse`. This is the
entire production change.

### Why this approach

- **vs. `guest` middleware on the route** — the framework default
  `RedirectIfAuthenticated` has an implicit redirect target and would also block
  the POST funnel (`POST /`, `/trip`), exceeding the intended scope. The
  controller guard names `route('dashboard')` explicitly and touches only the
  GET landing.
- **vs. merging the dashboard into `/`** — rejected during brainstorming; the
  decision was to redirect, not to render trips inline at `/`.

### Why the rest of the guest funnel needs no changes

`LandingController@tripDetail` and `LandingController@createTrip` already
redirect to `route('home')` when there is no complete `pending_trip` in the
session. A logged-in user therefore cannot meaningfully re-enter the
email-capture path: they bounce back to `/`, which now redirects them on to the
dashboard. No additional guards are required.

## Testing

One new feature test covering the home route:

- An **authenticated** user requesting `GET /` is redirected to
  `route('dashboard')`.
- A **guest** requesting `GET /` still receives the `Landing` Inertia page
  (regression guard for existing behavior).

## Out of scope

Explicitly unchanged: the new-user steps (trip form → confirm-destination →
email capture → check-email → click), welcome/activation timing, and the
logged-in add-trip path.
