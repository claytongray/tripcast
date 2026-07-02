---
baseline_commit: 5322698
---

# Story 9.2: Landing explainer, site footer & link previews

Status: review

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

As a first-time visitor,
I want the landing page to show me what tripcast actually is,
so that I understand the product before handing over my email.

## Acceptance Criteria

**AC1 — Fresh-eyes comprehension audit drives the copy** *(FR-24)*
- **Given** the current landing page
- **When** a fresh-eyes comprehension audit is run (**Claude pass — first task of the story**)
- **Then** its gap findings are recorded in the story file (Dev Agent Record) and drive the section copy.

**AC2 — One lean below-the-fold explainer section** *(FR-24)*
- **Given** the landing page below the hero
- **When** the visitor scrolls
- **Then** one lean section explains the product: **what a tripcast is**, **how it works** (enter trip → daily email from 7 days out → stops after return), **a real digest screenshot**, and a **"Send me a sample" CTA reprise** — deliberately not a marketing site.

**AC3 — Site footer with legal links on public pages** *(FR-24)*
- **Given** any public page
- **When** it renders
- **Then** a site footer shows Privacy/Terms links (Story 9.1 pages).

**AC4 — Link previews** *(FR-24)*
- **Given** a link to tripcast shared elsewhere
- **When** the preview is generated
- **Then** `app.blade.php` serves a meta description + Open Graph/Twitter tags (title, description, image).

## Tasks / Subtasks

- [x] **Task 1 — Fresh-eyes comprehension audit (FIRST — before any code)** (AC: 1)
  - [x] Spawn a fresh-context subagent given ONLY the rendered content of `resources/js/pages/Landing.vue` (hero copy, form labels, sample CTA — no PRD/epics/product context) and ask: "You just landed on this page from a shared link. What is this product? What will happen after you submit the form? What's unclear or missing?" Record its gap findings verbatim in Dev Agent Record → Comprehension Audit Findings.
  - [x] Let the findings choose what the explainer must answer (expected gaps: what arrives by email and when, that it stops by itself, that there's no app/password, what "sample" means). Copy decisions trace to findings.
- [x] **Task 2 — Real digest screenshot asset** (AC: 2)
  - [x] Generate a real digest screenshot: render `DigestMail` HTML with a representative trip + snapshot — use the fixture shapes `digestTripSpanningSnapshot()` + `fullForecastSnapshot()` for the 7 snapshot dates (2026-06-29…07-05) from `tests/Feature/Digest/DigestMailTest.php`. **NOT `digestTrip()`+`digestSnapshot()`:** `DigestMail::dayRows()` clips the forecast to the trip window (`DigestMail.php:160–180`), and that pair intersects to only 2 rows, one of them the limited marker — the opposite of "show the product". Render via a small tinker/artisan script writing `$mail->render()` to a scratch HTML file, open in the browser at 600px-content width, screenshot the card at 2×, save as `public/images/digest-sample.png`. Light mode, no promo slot in frame (the screenshot shows the product, not the ad); any calm header state is acceptable — the 7 forecast rows are the subject of the shot.
  - [x] Add `public/og-image.png` (1200×630) for link previews — the digest card composed on the `surface-wash` band with the tagline; a cropped/padded variant of the same screenshot is fine. Lean, not a designed marketing asset.
  - [x] Reference the screenshot from the explainer with a meaningful `alt` ("A tripcast: 7-day forecast email for Edinburgh") — meaning never in the image alone (UX-DR18): the how-it-works text carries the content.
- [x] **Task 3 — Below-the-fold explainer section in `Landing.vue`** (AC: 2)
  - [x] Add one section below the hero (`resources/js/pages/Landing.vue` — hero ends ~line 221; the page has no layout wrapper, `bg-surface-wash` full-page): alternate onto `bg-surface` (or keep the wash — match the hero band pattern, DESIGN.md's gentle section fills) with the same `max-w-[720px]` rail.
  - [x] Content, in the calm concierge voice (lowercase tripcast, no exclamation pile-ups, audit findings drive final wording): (a) **what a tripcast is** — one short paragraph anchored on the PRD framing ("a daily weather email for your trip", inbox not app, zero ongoing effort); (b) **how it works** — three quiet steps: enter your trip → a daily email starts 7 days before departure → it stops by itself after you're home; (c) the **digest screenshot** (`/images/digest-sample.png`, width-constrained, `rounded-lg border border-hairline`; the fixed light-mode asset is acceptable in dark mode — no dark variant); (d) **"Send me a sample" reprise** — a button calling the existing `openSample()` (same modal, same `sampleForm` — do NOT duplicate the modal or POST logic).
  - [x] Deliberately lean: one section, no feature grid, no testimonials, no pricing, no second CTA style. Semantic `<section>` with a proper heading level under the page's existing structure (UX-DR18).
- [x] **Task 4 — `SiteFooter.vue` on public pages** (AC: 3)
  - [x] New `resources/js/components/SiteFooter.vue`: quiet one-line footer — "Privacy · Terms" as `Link`s to the Story 9.1 routes (wayfinder `privacy()`/`terms()` from `@/routes`), `text-meta text-ink-secondary`, hairline top border, generous padding; ≥44px tap targets on the links (UX-DR18).
  - [x] Mount it (structural guidance — 3 of the 4 sites need restructuring, don't just append):
    - **`Landing.vue`** — ready as-is: root is `div.flex.min-h-svh.flex-col` with `main.flex-1` (lines 69, 97–98); place `<SiteFooter />` after `</main>` inside the existing flex column.
    - **`AppLayout.vue`** — root is a non-flex `div.min-h-screen` (line 11): change it to `flex min-h-screen flex-col`, make the slot region `flex-1` (or `mt-auto` on the footer) so the footer pins to the viewport bottom on short pages (Settings, TripAdded). Covers Privacy, Terms, Dashboard, Settings, Admin, TripAdded.
    - **`auth/AuthSimpleLayout.vue`** and **`TripDetail.vue`** — each has a **single `<main>` root** with `min-h-svh justify-center` (AuthSimpleLayout lines 13–15; TripDetail lines 45–47): restructure to a wrapper `<div class="flex min-h-svh flex-col">` containing `<main class="flex flex-1 flex-col items-center justify-center …">` + `<SiteFooter />` — keeps the project's single-root rule and the `<footer>` landmark **outside** `<main>` (UX-DR18 semantics). Covers login, magic-link, and the public email-action confirm/result pages.
    - One component, four mount sites — no per-page copies.
- [x] **Task 5 — Meta description + Open Graph/Twitter tags** (AC: 4)
  - [x] `resources/views/app.blade.php` `<head>`: static global tags — `<meta name="description">` using the positioning line ("Stop checking the weather for your trip. tripcast watches your destination and sends one calm morning email — starting 7 days out, stopping after you're home." — final wording may tighten, keep the tagline/positioning framing); `og:type` website, `og:site_name` tripcast, `og:title` (tagline form), `og:description` (same as description), `og:url` `{{ url('/') }}`, `og:image` `{{ url('/og-image.png') }}` (+ `og:image:width/height`), `twitter:card` `summary_large_image`, `twitter:title/description/image`. Static in blade is correct — every page shares the one product preview (no per-page OG in this story).
- [x] **Task 6 — Tests** (AC: 2, 3, 4)
  - [x] `tests/Feature/Landing/LandingMetaTest.php` (new, Pest): `GET /` response contains `name="description"`, `property="og:title"`, `property="og:image"` + the absolute og-image URL, and `name="twitter:card"` (assert on raw HTML, `assertSee(..., false)`). Also assert `file_exists(public_path('og-image.png'))` and `public_path('images/digest-sample.png')` so the assets can't silently vanish from the repo.
  - [x] Existing `tests/Feature/Landing/TripSetupTest.php` must stay green (Landing component render, session repopulation, validation — don't disturb the form).
  - [x] Vue content (explainer copy, footer links) lives client-side — enforced by source review + `npm run build:ssr` compiling, same convention as Privacy/Terms in 9.1.
  - [x] **Gates:** `php artisan test --compact`, `vendor/bin/pint --dirty --format agent`, `./vendor/bin/phpstan analyse`, `npm run build:ssr`.

## Dev Notes

### Scope boundary (read first)
- **In scope:** the audit, one explainer section on Landing, the screenshot + og-image assets, `SiteFooter.vue` + three mounts, static meta/OG tags, tests. **Out of scope:** any marketing-site expansion (multi-section, nav, blog — PRD locks single page), per-page OG tags, mobile date-field styling (9.3), destination autocomplete (9.4), postal address on web pages (email-only requirement), redesigning the hero (tagline + form-is-the-hero are locked brand assets — DESIGN.md line 96/152). [Source: epics.md#Story-9.2; prd.md#4.1]

### Architecture / design (binding)
- **Locked brand assets:** tagline "The weather app you never have to open." must stay on the landing page; the form **is** the hero — the explainer sits below, it does not compete. Landing rail is `max-w-[720px]`, no sidebars. [Source: DESIGN.md lines 96, 133, 152]
- **Positioning copy for description/explainer:** "Stop checking the weather for your trip. We'll do it for you." and the PRD vision framing (passive trip-concierge, one clean morning email, stops on its own, value in the inbox with zero ongoing effort). [Source: prd.md §1 lines 17–21, §4.1 line 91]
- **Voice:** calm concierge — brief, confident, never alarmist; lowercase tripcast; one idea per line. The how-it-works flow is exactly "enter trip → daily email from 7 days out → stops after return". [Source: EXPERIENCE.md#Voice-and-Tone; epics.md line 667]

### Code intel (exact patterns to reuse)
- **`Landing.vue` (297 lines, no layout):** header nav lines 70–95; hero + form lines 97–221 (`max-w-[720px]`, card `rounded-lg border border-hairline bg-surface-raised`); sample CTA lines 211–220 calls `openSample()`; the Dialog modal lines 224–294 with `showSample`/`sampleSent`/`sampleForm` state. The explainer reuses `openSample()` — the modal/POST logic must not be duplicated. [Source: resources/js/pages/Landing.vue]
- **Layout resolution:** `resources/js/app.ts` lines 11–22 — Landing/TripDetail → null layout; `auth/*`+`email/*` → AuthLayout (`resources/js/layouts/auth/AuthSimpleLayout.vue`); everything else → AppLayout. That's why the footer needs three mounts. [Source: resources/js/app.ts]
- **`app.blade.php` (52 lines):** head currently has charset/viewport/color-scheme/theme-script/favicons/`@fonts`/`@vite`/`<x-inertia::head>` — **no** description/OG/Twitter tags today. Title already resolves "tripcast" via `<Head>` + `VITE_APP_NAME` (APP_NAME=tripcast — no "Laravel" risk). Add the static tags directly in the blade head. **Known pre-existing quirk:** Landing's `<Head title="tripcast — the weather app you never have to open">` + the app.ts title template doubles to "… - tripcast" in the tab title — do NOT silently "fix" the Head/title template in this story (note it for polish instead). [Source: resources/views/app.blade.php; resources/js/app.ts lines 7–10; resources/js/pages/Landing.vue:67]
- **Assets:** `public/` has only favicons + build output — no images dir yet; create `public/images/`. Public-path references (`/images/digest-sample.png`) are fine for a static content image (no Vite hashing needed; it's also the OG target pattern). [Source: public/]
- **Wayfinder:** `privacy()`/`terms()` route helpers exist since 9.1 (`@/routes`); use them in `SiteFooter.vue` like `TripAdded.vue` uses `dashboard()`. [Source: resources/js/pages/TripAdded.vue]
- **Digest fixtures for the screenshot:** use `digestTripSpanningSnapshot()` (trip 2026-06-29→07-05, spans the snapshot) + `fullForecastSnapshot()` over the 7 snapshot dates so all 7 day-rows render — `DigestMail::dayRows()` clips to the trip window, which is why the `digestTrip()`/`digestSnapshot()` pair yields only 2 rows. Mirror those shapes in the render script so the screenshot is a *real* render of `emails/digest.blade.php`, not a mockup. Note `DigestMail` builds signed URLs — needs a saved Trip/User (use a local DB record or the test env). [Source: tests/Feature/Digest/DigestMailTest.php:28–52; app/Mail/DigestMail.php:160–180]

### Testing standards
- Pest feature tests; raw-HTML assertions with `assertSee($fragment, false)` for the blade-served meta tags (Inertia responses still pass through `app.blade.php`, so `GET /` carries them). Asset-existence assertions via `expect(file_exists(public_path(...)))->toBeTrue()`. Don't attempt to assert Vue-rendered copy over HTTP (SSR content isn't rendered in feature tests) — that's what the build gate + review are for. [Source: tests/Feature/Landing/TripSetupTest.php; 9.1 convention]

### Project Structure Notes
- **New:** `resources/js/components/SiteFooter.vue`, `public/images/digest-sample.png`, `public/og-image.png`, `tests/Feature/Landing/LandingMetaTest.php`.
- **Modified:** `resources/js/pages/Landing.vue` (explainer section + footer mount), `resources/js/pages/TripDetail.vue` (footer mount), `resources/js/layouts/AppLayout.vue` (footer mount), `resources/js/layouts/auth/AuthSimpleLayout.vue` (footer mount), `resources/views/app.blade.php` (meta/OG tags).

### Previous story intelligence (9.1)
- Privacy/Terms are `Route::inertia` pages named `privacy`/`terms`; they render inside **AppLayout** (default layout) — the AppLayout footer mount gives them their footer automatically; their inline "← back to tripcast" link stays.
- 9.1's pattern held: copy lives in Vue, feature tests assert route+component+blade-level HTML only; full suite 316 passed at baseline `5322698`.
- Landing currently redirects authenticated users to the dashboard (Story 6.1) — the explainer is guest-facing; `TripSetupTest` line 41–44 pins the redirect, don't break it.

### References
- [Source: _bmad-output/planning-artifacts/epics.md#Story-9.2 (lines 653–675), #FR-24 (line 45)]
- [Source: _bmad-output/planning-artifacts/prds/prd-tripcast-2026-06-28/prd.md §1, §4.1]
- [Source: _bmad-output/planning-artifacts/ux-designs/ux-tripcast-2026-06-28/DESIGN.md lines 96–152; EXPERIENCE.md lines 27, 44–46, 195]
- [Source: resources/js/pages/Landing.vue; resources/js/app.ts; resources/views/app.blade.php; tests/Feature/Landing/TripSetupTest.php]

## Dev Agent Record

### Agent Model Used

claude-fable-5

### Comprehension Audit Findings

_(Task 1 — fresh-context Claude pass over the rendered landing copy only, recorded before implementation. 11 gap findings, condensed; the full list drove the copy.)_

1. **No delivery channel stated** — "never have to open" implies not-an-app, but the page never says the forecast arrives by *email*; the main form doesn't even collect one.
2. **No frequency stated** — one forecast? daily? unknown.
3. **No start/end timing** — does it start today or near departure? stop at return? only inferable.
4. **No cost/catch** — silence on price reads as "about to be upsold".
5. **Email asymmetry** — form collects destination/dates but no email, so "how does it reach me?" is confusing.
6. **"Sign in" without "Sign up"** — account relationship to the form undefined.
7. **No stop/unsubscribe story** — trust blocker for a recurring email.
8. **Spam/data trust unaddressed.**
9. **What a tripcast looks like is invisible** — the one convincing artifact was gated behind giving an email. "Show, don't gate."
10. **"tripcast" as a noun undefined** on first read.
11. Single-destination question (minor).

**Priority questions the explainer must answer:** what arrives & where → how often & start/stop → show one → free? → account? → how to stop. The shipped copy maps 1:1: "a short weather email for one trip… It's free, and there's no app to open" (gaps 1, 4, 10), step 1 "destination, dates, and your email — no password" (5, 6), step 2 "one calm email each morning… starting 7 days before departure" (2, 3), step 3 "stops by itself… one-click unsubscribe" (3, 7), the real screenshot un-gates the artifact (9), and the sample reprise stays for inbox-level proof.

### Debug Log References

- Playwright MCP browser profile was locked by a running Chrome → switched to the chrome-devtools MCP instance. First digest screenshot rendered dark (browser `prefers-color-scheme`) → re-took with `emulate colorScheme: light`. Herd serves https with an untrusted cert in the automation browser → visual checks ran against a temporary `php artisan serve --port=8765`.

### Completion Notes List

- **Audit (AC1):** fresh-context comprehension audit run first; findings above drove every copy decision in the explainer.
- **Assets (AC2):** `public/images/digest-sample.png` — a *real* render of `emails/digest.blade.php` (Edinburgh, trip 2026-06-29→07-05, full 7-day varied snapshot, trip/user created inside a rolled-back DB transaction), screenshotted at 2× in forced light mode, downscaled to 1200w (~233 KB). It shows all 7 day-rows, feedback chips, and the 9.1 legal footer. `public/og-image.png` (2400×1260 @2×) — tagline left, the same digest card right, on the `surface-wash` band.
- **Explainer (AC2):** one `<section aria-labelledby>` below the hero on `bg-surface` with the 720px rail: "What's a tripcast?" paragraph, three numbered steps (enter trip → daily email from 7 days out → stops by itself + one-click unsubscribe), the screenshot in a `<figure>` with meaningful alt, and a "Send me a sample" reprise calling the existing `openSample()` (no modal/POST duplication). Hero and form untouched.
- **Site footer (AC3):** `SiteFooter.vue` (Privacy · Terms via wayfinder `privacy()`/`terms()`, h-11 tap targets) mounted at all four sites with the validated restructures: Landing (append to flex column), AppLayout (root → `flex min-h-screen flex-col`, slot wrapped `flex-1`), AuthSimpleLayout + TripDetail (single-root rewrap: `div.flex.min-h-svh.flex-col` > `main.flex-1` + footer, `<footer>` landmark outside `<main>`). Verified in-browser: footer pins to viewport bottom on short pages (login), content stays centered.
- **Link previews (AC4):** static meta description + og:type/site_name/title/description/url/image(+dimensions) + twitter card/title/description/image in `app.blade.php` head, absolute URLs via `url()`. Pre-existing doubled tab title left alone as directed.
- **Verification:** full suite **318 passed** (1108 assertions) incl. 2 new `LandingMetaTest` tests; eslint + prettier clean on touched Vue files; pint clean; phpstan 0 errors; `build:ssr` green; landing/login/privacy visually verified over HTTP.

### File List

**New:**
- `resources/js/components/SiteFooter.vue`
- `public/images/digest-sample.png`
- `public/og-image.png`
- `tests/Feature/Landing/LandingMetaTest.php`

**Modified:**
- `resources/js/pages/Landing.vue` (explainer section + footer mount)
- `resources/js/pages/TripDetail.vue` (single-root rewrap + footer)
- `resources/js/layouts/AppLayout.vue` (flex column + footer)
- `resources/js/layouts/auth/AuthSimpleLayout.vue` (single-root rewrap + footer)
- `resources/views/app.blade.php` (meta description + OG/Twitter tags)

### Change Log

- 2026-07-01 — Implemented Story 9.2: fresh-eyes comprehension audit (11 findings, recorded above) → one lean below-the-fold explainer on the landing page with a real digest screenshot and sample-CTA reprise; `SiteFooter.vue` (Privacy/Terms) on every public surface via four mounts; global meta description + Open Graph/Twitter tags with a real og-image. 2 new tests; full suite 318 passed.
