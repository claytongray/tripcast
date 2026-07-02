---
baseline_commit: 6cbd8e4
---

# Story 9.1: Legal & compliance pages

Status: review

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

As a visitor or email recipient,
I want to read tripcast's privacy policy and terms,
so that I know how my email and data are handled before and after I sign up.

## Acceptance Criteria

**AC1 — Public `/privacy` and `/terms` pages render for a logged-out visitor** *(FR-26)*
- **Given** a logged-out visitor
- **When** they open `/privacy` or `/terms`
- **Then** a calm, readable page renders — **privacy** covers exactly what's stored (email, trip destinations/coordinates, **no passwords** — NFR-5), the login-free unsubscribe/end-trip actions, and the **~30-day forecast-history purge** (NFR-7); **terms** cover the beta/no-warranty basics and the affiliate disclosure. Copy in the established voice (lowercase **tripcast**).

**AC2 — Email footers carry Privacy/Terms links + postal address** *(FR-26)*
- **Given** the digest, welcome, and sample emails
- **When** their footers render
- **Then** they include Privacy and Terms links (**absolute URLs**) and the physical postal address; `TRIPCAST_POSTAL_ADDRESS` joins the production env checklist (the config seam already exists at `config/tripcast.php:197`).

## Tasks / Subtasks

- [x] **Task 1 — Routes + Inertia pages `/privacy` and `/terms`** (AC: 1)
  - [x] `routes/web.php`: two public GET routes named `privacy` and `terms` in the guest section (near the landing routes). Simple static pages — `Route::inertia('/privacy', 'Privacy')->name('privacy')` and `Route::inertia('/terms', 'Terms')->name('terms')` (no controller needed; no auth middleware; SSR renders them like every other page). **Note:** this is the first `Route::inertia` use in this app — the macro ships with inertia-laravel (no import needed); do not build controllers for these two static pages.
  - [x] `resources/js/pages/Privacy.vue` + `resources/js/pages/Terms.vue`: single-column readable content pages using the existing design tokens (`bg-surface`, `max-w-*` container, `text-title`/`text-body`/`text-ink`/`text-ink-secondary`, `space-y-*`), `<Head title="Privacy">` / `<Head title="Terms">`, an `<h1>` + dated "Last updated: July 1, 2026" line, and a quiet link back to `/` ("← back to tripcast"). Semantic landmarks (`<main>`, headings in order) per the UX-DR18 floor. No new base components needed.
  - [x] **Privacy copy must state, in the calm voice (not legalese):** what tripcast stores — your email address and your trips (destination, its resolved place name + coordinates, dates); **never a password** (magic-link login only); forecast snapshots kept per trip then **deleted about 30 days after the trip's return date**; unsubscribe (all emails, one click, no login) and end-trip work straight from any email footer; no data sold, no ad trackers — the only third parties are the services that run the product (email delivery, geocoding, weather). Beta framing: personal beta, no formal GDPR/CCPA program yet.
  - [x] **Terms copy must state:** tripcast is a free beta, provided as-is with **no warranty** — forecasts come from a third-party provider and can be wrong; don't rely on it for safety-critical decisions; service may change or pause during beta; and the **verbatim affiliate disclosure**: "As an Amazon Associate, tripcast earns from qualifying purchases" *(the canonical literal is **period-free** everywhere it exists — `digest.blade.php:93`, `digest-text.blade.php:23`, `DigestMailTest.php:416/420`; a sentence-ending period on the Terms page is fine, but tests should match the substring without trailing punctuation — FTC + Amazon Associates requirement)*
- [x] **Task 2 — Shared email legal-footer partials** (AC: 2)
  - [x] `resources/views/emails/partials/legal-footer.blade.php` (HTML) + `legal-footer-text.blade.php` (plain text): Privacy + Terms links as **absolute URLs** (`route('privacy')` / `route('terms')` — absolute by default) and the postal-address line (conditional on `$postalAddress`, exactly like today's digest footer). HTML: table-safe markup, inline styles matching the existing digest footer (`meta` size ≥13px, `ink-secondary` on the rendered background — UX-DR17 type minimums / AA). Text twin: literal tappable URLs on their own lines ("Privacy: {url}" / "Terms: {url}") + address.
  - [x] `resources/views/emails/digest.blade.php` + `digest-text.blade.php`: replace the inline postal-address block — **the `@if ($postalAddress)`…`@endif` block only** (HTML lines 122–126, text lines 31–34) — with the shared partial; legal links sit with the address below the existing End-trip/Unsubscribe links. **Do not touch** the feedback/end-trip/unsubscribe links immediately above it (HTML 117–121, text 26–30 — Story 2.5/2.6 surface).
  - [x] `app/Mail/WelcomeMail.php` + `resources/views/emails/welcome.blade.php` / `welcome-text.blade.php`: pass `'postalAddress' => config('tripcast.postal_address')` and include the partials — the welcome email gains its first footer (legal links + address). Keep the welcome body untouched (locked voice copy — see Dev Notes). **Dark-mode gap:** `welcome.blade.php:11–16` defines only `.tc-body/.tc-card/.tc-ink/.tc-ink-secondary` — if the partial uses the digest's `tc-divider` hairline, add `.tc-divider { border-color: #24313D !important; }` to welcome's dark `<style>` block (`sample-digest.blade.php:16` already has it).
  - [x] `app/Mail/SampleDigestMail.php` + `resources/views/emails/sample-digest.blade.php` / `sample-digest-text.blade.php`: same — footer below the "Get started" CTA. Sample still has **no** unsubscribe/feedback/promo (Story 6.4 decision); only the legal footer is added.
- [x] **Task 3 — Env checklist seam** (AC: 2)
  - [x] Add `TRIPCAST_POSTAL_ADDRESS=` to `.env.example` with a one-line comment (CAN-SPAM postal address, required for production sends — Story 9.6 env checklist). Config seam already exists; **no config change**. Setting the real production value is Story 9.6, not this story.
- [x] **Task 4 — Tests** (AC: 1, 2)
  - [x] `tests/Feature/Legal/LegalPagesTest.php` (new, Pest): `GET /privacy` and `GET /terms` → `assertOk()` + `assertInertia(fn ($page) => $page->component('Privacy'/'Terms'))` as a **guest** (no auth redirect); routes are named `privacy`/`terms`.
  - [x] `tests/Feature/Digest/DigestMailTest.php` (extend): footer HTML **and** text contain the absolute `/privacy` and `/terms` URLs; existing postal-address test still passes against the partial.
  - [x] `tests/Feature/Mail/WelcomeMailTest.php` (extend): HTML + text contain privacy/terms URLs and the postal address when configured. **Heads-up:** the existing `assertDontSeeInHtml('<a ', false)` no-CTA assertion will now conflict with footer anchor tags — narrow it to the body region or replace with an assertion that no *button/CTA* markup exists (the no-CTA rule is about celebration CTAs, UX-DR7; quiet legal links in a footer don't violate it — note the change in the story record).
  - [x] `tests/Feature/Sample/SampleDigestMailTest.php` (extend): footer links + address present in HTML and text. **Guard to keep green:** `it('omits unsubscribe and feedback (a sample is not a subscription)')` (lines 50–54) asserts `assertDontSeeInHtml('Unsubscribe'/'unsubscribe')` — the legal footer must not introduce the string "unsubscribe"; do not weaken this guard.
  - [x] **Gates:** `php artisan test --compact`, `vendor/bin/pint --dirty --format agent`, `./vendor/bin/phpstan analyse`, `npm run build:ssr` (two new SSR pages must compile).

## Dev Notes

### Scope boundary (read first)
- **In scope:** the two public pages, the email legal footer (digest + welcome + sample, HTML and text twins), `.env.example` entry, tests. **Out of scope:** the **site footer** on web pages (Story 9.2 links these pages from every public page), setting the real postal-address value / production env (Story 9.6), List-Unsubscribe headers (Story 9.6 gate `#MS42235`), delete-account/GDPR tooling (deferred per PRD non-goals), magic-link email (transactional, not in the AC's email list), `ConditionsPreviewMail` (dev-only emoji reference sheet, never in the production pipeline — no footer). [Source: epics.md#Story-9.1, #Story-9.2, #Story-9.6]

### Architecture (binding)
- **NFR-5 (privacy & data):** "Stored personal data is limited to email + Trip destinations/coordinates. No passwords are stored. Unsubscribe/end-trip works from email without login. [ASSUMPTION: no formal GDPR/CCPA program in v1 personal beta; revisit before public scale.]" — the privacy page must say exactly this, no more claims. [Source: prd.md#Cross-Cutting]
- **NFR-7 (retention):** per-Trip snapshots purged ~30 days after Return Date (AD-16 nulls only `weather_snapshot`; send-outcome rows survive) — the page says "about 30 days after your trip ends", not a hard promise of full-row deletion. [Source: prd.md#Cross-Cutting; ARCHITECTURE-SPINE.md#AD-16]
- **CAN-SPAM footer invariant:** "a stable physical postal address in every footer" + footer type ≥13–14px at AA contrast on the rendered background (dark-mode safe — use the same inline styles as the existing digest footer block, which already satisfies this). [Source: EXPERIENCE.md#Email-Delivery-Inbox-Invariants; DESIGN.md#Components]
- **AD-13 nuance for the privacy copy:** unsubscribe is **account-level** (stops all tripcast email), end-trip is **per-trip** — the page should present them as two distinct actions, matching the real footer links. [Source: ARCHITECTURE-SPINE.md#AD-13]

### Code intel (exact patterns to reuse)
- **Config seam exists:** `config/tripcast.php:197` `'postal_address' => env('TRIPCAST_POSTAL_ADDRESS')` — digest already passes it (`app/Mail/DigestMail.php:105`) and renders it conditionally (`resources/views/emails/digest.blade.php:122–126`, `digest-text.blade.php:31–34` — the `@if ($postalAddress)` block). The partial replaces that block; Welcome/Sample copy the same `with`-map pattern. [Source: config/tripcast.php; app/Mail/DigestMail.php]
- **Welcome/Sample mailables** currently pass **no** `postalAddress` and have **no footer**: `WelcomeMail.php:33–44`, `SampleDigestMail.php:38–62`. Both use the HTML-view + text-view pair convention — keep it. [Source: app/Mail/WelcomeMail.php; app/Mail/SampleDigestMail.php]
- **Absolute URLs:** `route('privacy')`/`route('terms')` return absolute URLs (APP_URL) — correct for email. Do not hand-build URLs.
- **Page conventions:** every page is `Inertia::render` + SSR; tokens/utilities in `resources/css/app.css` (`text-title` 22px, `text-body` 16px, `text-meta` 13px, `bg-surface`, `border-hairline`, radius `sm/md/lg`); dark mode via token pairs — no hardcoded colors. There is **no existing web footer component** — don't create one here (9.2 builds it). [Source: resources/css/app.css; resources/js/layouts/AppLayout.vue]
- **Wayfinder:** new named routes regenerate typed helpers on build; nothing manual.

### Voice & copy (locked rules)
- Lowercase **tripcast** as the product noun everywhere (including page `<h1>`s and legal body). Calm concierge voice, one idea per line, never legalese; month-first absolute dates ("July 1, 2026"). Avoid the retired "watching" motif on new surfaces. The affiliate disclosure is a **verbatim literal**: "As an Amazon Associate, tripcast earns from qualifying purchases." [Source: EXPERIENCE.md#Voice-and-Tone, #Component-Patterns; copy-voice conventions from the 2026-07-01 welcome-surface pass (commit 8e5f561)]

### Testing standards
- Pest feature tests; `use function Pest\Laravel\get;` + `assertInertia` for pages (see `tests/Feature/Landing/TripSetupTest.php:34–37`). Mail content via mailable render assertions — `assertSeeInHtml`/`assertSeeInText` (see the postal-address example at `tests/Feature/Digest/DigestMailTest.php:333–339`); set `config(['tripcast.postal_address' => '…'])` in the test, don't rely on env. Page copy lives in Vue, so feature tests assert route + component only — the AC's copy requirements are enforced by the Vue source (reviewable) not by HTTP assertions. [Source: tests/Feature/Digest/DigestMailTest.php; tests/Feature/Mail/WelcomeMailTest.php]

### Project Structure Notes
- **New:** `resources/js/pages/Privacy.vue`, `resources/js/pages/Terms.vue`, `resources/views/emails/partials/legal-footer.blade.php`, `resources/views/emails/partials/legal-footer-text.blade.php`, `tests/Feature/Legal/LegalPagesTest.php`.
- **Modified:** `routes/web.php` (two `Route::inertia` lines), `app/Mail/WelcomeMail.php`, `app/Mail/SampleDigestMail.php`, `resources/views/emails/digest.blade.php` + `digest-text.blade.php` (swap inline block → partial), `welcome.blade.php` + `welcome-text.blade.php`, `sample-digest.blade.php` + `sample-digest-text.blade.php`, `.env.example`, `tests/Feature/Digest/DigestMailTest.php`, `tests/Feature/Mail/WelcomeMailTest.php`, sample mail test.

### Previous story intelligence (Epic 6 + welcome-surface voice pass)
- The 2026-07-01 voice pass (commit `8e5f561`) touched `welcome.blade.php`/`welcome-text.blade.php` and locked the welcome copy — add the footer **below** the existing body without editing body strings, or `WelcomeMailTest`'s exact-fragment assertions will break.
- Story 6.4 deliberately excluded unsubscribe/feedback/promo from the sample email; the legal footer does **not** reverse that — links + address only.
- MailerSend `DigestMail` List-Unsubscribe currently 422s on the plan gate (open retro item A2) — unrelated to this story; footer changes are render-level and testable without sending.

### References
- [Source: _bmad-output/planning-artifacts/epics.md#Story-9.1 (lines 637–651), #FR-26 (line 47)]
- [Source: _bmad-output/planning-artifacts/prds/prd-tripcast-2026-06-28/prd.md#NFR-5, #NFR-7, #Non-Goals]
- [Source: _bmad-output/planning-artifacts/architecture/architecture-tripcast-2026-06-28/ARCHITECTURE-SPINE.md#AD-13, #AD-16, #Email-invariants]
- [Source: _bmad-output/planning-artifacts/ux-designs/ux-tripcast-2026-06-28/EXPERIENCE.md#Email-Delivery-Inbox-Invariants, #Voice-and-Tone; DESIGN.md#Components]
- [Source: config/tripcast.php:186–197; app/Mail/DigestMail.php; resources/views/emails/*.blade.php; tests/Feature/Digest/DigestMailTest.php:333–339]

## Dev Agent Record

### Agent Model Used

claude-fable-5

### Debug Log References

- None. TDD red→green: 6 new assertions failed (404s + missing `privacy` route + welcome anchor count), then passed after implementation. No blockers.

### Completion Notes List

- **Pages:** `Route::inertia('privacy'/'terms')` (first `Route::inertia` use in the app — no controllers) + `Privacy.vue`/`Terms.vue`: single-column token-styled content pages with `<main>` landmark, "Last updated: July 1, 2026", back-link to `/`. Privacy states exactly what's stored (email + trips: destination, resolved place name/coordinates, dates), never-a-password, ~30-day snapshot deletion after return, one-click no-login unsubscribe vs. per-trip end-trip (AD-13 distinction), no selling/trackers + the three operational third parties, and the beta/no-GDPR-program note. Terms: free beta as-is/no warranty, third-party forecasts can be wrong (not for safety-critical plans), may change/pause, and the affiliate disclosure (canonical period-free literal, sentence-ended on the page).
- **Email legal footer:** shared partials `emails/partials/legal-footer.blade.php` + `legal-footer-text.blade.php` (Privacy/Terms via `route()` absolute URLs, 13px `ink-secondary`, conditional postal address). Digest swaps its inline `@if ($postalAddress)` block for the partial (End-trip/Unsubscribe/feedback links untouched); welcome and sample gain their first footer under a `tc-divider` hairline; welcome's dark-mode `<style>` block gained the missing `.tc-divider` override. `WelcomeMail`/`SampleDigestMail` now pass `postalAddress`; sample still carries no unsubscribe/feedback/promo (guard test stays green).
- **Env checklist:** `TRIPCAST_POSTAL_ADDRESS=` added to `.env.example` with the CAN-SPAM/Story-9.6 comment; no config change (seam already existed).
- **Tests:** new `LegalPagesTest` (guest 200 + Inertia components + named routes); Digest/Welcome/Sample mail tests extended for footer links + address in both twins. Welcome's `assertDontSeeInHtml('<a ', false)` no-CTA assertion replaced with an exact anchor count of 2 (the two quiet legal links — the UX-DR7 no-CTA rule is about celebration CTAs, preserved in intent).
- **Verification:** full suite **316 passed** (1099 assertions); pint clean; phpstan 0 errors; `npm run build:ssr` green with `Privacy`/`Terms` chunks in both client and SSR bundles.

### File List

**New:**
- `resources/js/pages/Privacy.vue`
- `resources/js/pages/Terms.vue`
- `resources/views/emails/partials/legal-footer.blade.php`
- `resources/views/emails/partials/legal-footer-text.blade.php`
- `tests/Feature/Legal/LegalPagesTest.php`

**Modified:**
- `routes/web.php` (`privacy` + `terms` via `Route::inertia`)
- `app/Mail/WelcomeMail.php` + `app/Mail/SampleDigestMail.php` (`postalAddress`)
- `resources/views/emails/digest.blade.php` + `digest-text.blade.php` (postal block → partial)
- `resources/views/emails/welcome.blade.php` + `welcome-text.blade.php` (legal footer + `.tc-divider` dark override)
- `resources/views/emails/sample-digest.blade.php` + `sample-digest-text.blade.php` (legal footer)
- `.env.example` (`TRIPCAST_POSTAL_ADDRESS`)
- `tests/Feature/Digest/DigestMailTest.php`, `tests/Feature/Mail/WelcomeMailTest.php`, `tests/Feature/Sample/SampleDigestMailTest.php`

### Change Log

- 2026-07-01 — Implemented Story 9.1: public `/privacy` and `/terms` Inertia pages in the locked voice; shared email legal-footer partial (Privacy/Terms absolute links + CAN-SPAM postal address) wired into digest, welcome, and sample emails (HTML + text twins); `TRIPCAST_POSTAL_ADDRESS` added to `.env.example`. 6 new tests; full suite 316 passed.
