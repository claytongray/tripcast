---
baseline_commit: 8e5c60a
---

# Google Analytics 4 tag + key events

Status: done

> **Provenance note:** executed 2026-07-04 as a direct light-triage build
> (clear+small → TDD, no superpowers/BMAD scoping flow). The single product
> decision — which actions count as key events — was put to Clayton
> (Trip created, Sign-up/login, Sample requested, Feedback submitted; all
> selected). This artifact is the retrospective paper trail per the BMAD
> paper-trail preference.

## Story

As the operator,
I want Google Analytics 4 page-view tracking plus key events on the core
conversion actions,
so that I can understand web traffic and the acquisition funnel.

## Acceptance Criteria

**AC1 — Config-gated base tag**
- **Given** the root document (`resources/views/app.blade.php`)
- **When** `services.google_analytics.measurement_id` (env `GOOGLE_ANALYTICS_ID`)
  is set
- **Then** the gtag.js snippet renders with that ID; when unset, no tag renders
  at all — so local/dev traffic never reaches Analytics. The snippet configures
  `send_page_view: false` because this is an Inertia SPA (see AC2).

**AC2 — SPA page views**
- **Given** Inertia does client-side navigation (no document reload)
- **When** the app boots and on every subsequent Inertia navigation (incl.
  browser back/forward)
- **Then** exactly one `page_view` event is sent per real navigation — one on
  boot (Inertia's `navigate` doesn't fire on the initial full-document load),
  then one per `router.on('navigate')`. gtag's automatic initial page_view is
  suppressed to avoid a double-count.

**AC3 — Key events fire server-signalled, client-sent**
- **Given** the four selected conversion actions
- **When** each action succeeds
- **Then** the controller flashes an `analytics` payload via `Inertia::flash`
  and the client listener (`router.on('flash')`) fires `gtag('event', …)`
  exactly once on the next page. Event names live in one place
  (`App\Services\Analytics\KeyEvent`), not scattered across Vue forms:

  | Event | Fired at | params |
  |---|---|---|
  | `trip_created` | landing email-capture (`LandingController@createTrip`) + dashboard add (`TripController@store`) | `source: landing\|dashboard` |
  | `login_link_requested` | `/login` request (`MagicLinkController@store`) | — |
  | `sign_up` / `login` | magic-link consume, new vs returning (`MagicLinkController@consume`, keyed on `$justConfirmed`) | `method: magic_link` |
  | `sample_requested` | public form (`SampleController@store`) + dashboard button (`storeForSelf`) | `source: landing\|dashboard` |
  | `feedback_submitted` | feedback form (`FeedbackController@store`) | `source: dashboard\|nav` |

**AC4 — Safe no-op without gtag**
- **Given** an environment with no Measurement ID (local/dev)
- **When** `initializeAnalytics()` runs and any event would fire
- **Then** every `gtag()` call is a guarded no-op (`typeof window.gtag ===
  'function'`); the frontend code runs unconditionally and is inert.

## Tasks / Subtasks

- [x] **Config + env** — `services.google_analytics.measurement_id`;
  `GOOGLE_ANALYTICS_ID` in `.env.example` + go-live checklist.
- [x] **Base tag** — config-gated gtag.js in `app.blade.php`, `send_page_view:
  false`, ID emitted via `@json`.
- [x] **Frontend wiring** — `resources/js/lib/analytics.ts` (boot page view +
  `navigate` page views + `flash` key events), initialized in `app.ts`.
- [x] **Key-event helper** — `App\Services\Analytics\KeyEvent` (name constants +
  `flash()` over `Inertia::flash`).
- [x] **Controller flashes** — 5 controllers (Landing, Trip, MagicLink, Sample,
  Feedback) fire the events above at their success points.
- [x] **Tests** — `tests/Feature/Analytics/KeyEventsTest.php`: 10 tests
  asserting each flash via `assertInertiaFlash('analytics.event', …)` plus the
  config-gating of the tag (renders / omits).

## Dev Notes

- **TDD:** tests written first (9 red on missing flash + 1 already-green
  "omits snippet"), then implementation to green. Final: analytics suite 10/10;
  touched suites (Feedback/Sample/Landing/Trip/Auth) 153/153; Pint, `types:check`
  (vue-tsc), eslint all clean.
- **Transport choice:** server flashes the event, client fires it. Chosen over
  sprinkling `gtag()` in Vue success callbacks because it centralizes event
  names, keeps the browser-only gtag concern out of components, and makes every
  event assertable in a PHP feature test. Rides the same `Inertia::flash` /
  `router.on('flash')` mechanism the existing toast system uses.
- **Deploy safety:** no schema/migration; nothing PHP-object-cached in Redis
  (flash is plain-array session data). Frontend change is covered by
  `npm run build:ssr` in the deploy script.
- **Operational follow-up (open):** the tag reads a **cached** config value, so
  GA does not turn on from a push alone. Set `GOOGLE_ANALYTICS_ID=G-3CYVCR49XB`
  in the Forge Environment editor, then re-deploy (or `artisan config:clear`) so
  `artisan optimize` bakes it into the release config cache. Until then the tag
  is absent in prod (no error, no data). After enabling, mark the events as
  **Key events** in GA4 Admin → Events (event firing is done; promotion is a
  GA-UI toggle).
