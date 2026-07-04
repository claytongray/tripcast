---
baseline_commit: ac051fb094c3361b0e11c0beb60b6c38b9104d64
---

# Story 11.3: Apple Weather attribution + provider cutover

Status: review

<!-- Forward-looking story. Design contract: _bmad-output/specs/spec-weatherkit-provider-swap/SPEC.md
(CAP-8) + companion weatherkit-integration.md (Attribution section). Implementation reference:
docs/superpowers/plans/2026-07-04-weatherkit-provider-swap.md (Tasks 9, 10). Third/final story of Epic 11. -->

## Story

As tripcast displaying Apple's weather data (and as the operator meeting Apple's license),
I want the Apple Weather attribution shown in every WeatherKit-sourced digest and the provider flipped to WeatherKit,
so that the migration is legally compliant and live — highs stop running hot in production.

## Context & Provenance

- **Third and final story of Epic 11.** Depends on 11.1 (provider) and 11.2 (timezone capture). This is the go-live story.
- **Attribution is a mandate, not a nicety.** WeatherKit's license **requires** the Apple Weather mark + a link to Apple's legal data-source page wherever its data is shown. This is the "mandate" half of the epic's Why.
- **Email-specific handling.** Email clients strip remote images and external CSS, so the Apple Weather logo must be **inlined** (base64/embedded), not hotlinked to `weatherkit.apple.com`. Assets + the legal URL come from `GET https://weatherkit.apple.com/attribution/en` (and `metadata.attributionURL`).
- **Hard cutover (pre-launch).** Flip `config('tripcast.forecast.provider')` to `weatherkit` after a local `MAIL_MAILER=log` render confirms the fix and the attribution. No shadow-compare (the swap is already live-proven: Kennett 97°F / Dewey 86°F).
- **Design contract:** SPEC CAP-8. Reference: plan Tasks 9, 10. `docs/deployment.md` owns the prod env-checklist / deploy process.

## Acceptance Criteria

1. **Given** `config('tripcast.forecast.provider') === 'weatherkit'`, **when** a digest email renders, **then** its footer shows the **Apple Weather** logo (inlined as a data URI, `alt="Apple Weather"`) linked to Apple's legal attribution page (`https://developer.apple.com/weatherkit/data-source-attribution/` or the endpoint's `legalPageUrl`); the mark and name are unaltered. **Given** the provider is `weatherapi`, **then** no Apple attribution renders.

2. **Given** the attribution assets, **when** implemented, **then** the logo is fetched once from `https://weatherkit.apple.com/attribution/en` and embedded inline (not a remote `<img src>`), so it survives email clients that block remote images; the plain-text digest (if it carries a source line) shows `Weather data by Apple Weather — <legal URL>` under the same provider gate.

3. **Given** a local run with `MAIL_MAILER=log` and `TRIPCAST_WEATHER_PROVIDER=weatherkit`, **when** a digest is force-sent for Kennett Square and Dewey Beach, **then** the logged email shows Kennett high **97°F** (not 105) and Dewey **86°F**, with the attribution present — a manual verification gate before cutover.

4. **Given** a trip created after Story 11.2, **when** checked, **then** `trips.destination_timezone` is a real IANA zone (proving CAP-9 populated it at creation), so the WeatherKit fetch uses the stored zone, not the fallback.

5. **Given** cutover, **then** the production env sets `TRIPCAST_WEATHER_PROVIDER=weatherkit` and the four `APPLE_WEATHERKIT_*` keys per `docs/deployment.md`'s env checklist; `.env.example` keeps the default `weatherapi` (explicit opt-in); the deployment env checklist lists the new keys.

6. **Given** completion, **then** all verification gates pass (`php artisan test --compact`, `pint`, `phpstan`, `types:check`, `lint:check`, `build:ssr`) with the flag on `weatherkit`, and the digest renders byte-stable apart from the added attribution.

## Tasks / Subtasks

- [x] **Task 1: Fetch the attribution assets (AC: 1, 2)** — plan Task 9
  - [x] Fetched `GET https://weatherkit.apple.com/attribution/en` (returns `serviceName: "Apple Weather"` + logo asset paths; **no `legalPageUrl` field**). Downloaded the light **and** dark 2× marks (`Apple_Weather_blk/wht_en_2X` — 154×28), base64-encoded for inlining
  - [x] Legal URL: the endpoint carries none, so used Apple's documented data-source page `https://developer.apple.com/weatherkit/data-source-attribution/` verbatim in the partial + assertions

- [x] **Task 2: Attribution partial, provider-gated (AC: 1, 2)** — plan Task 9
  - [x] Created `resources/views/emails/partials/weather-attribution.blade.php`: `@if (config('tripcast.forecast.provider') === 'weatherkit')` → inlined `<img alt="Apple Weather" src="data:image/png;base64,…">` (both marks; dark swap via `.tc-aw-*` + the digest's existing `@media (prefers-color-scheme: dark)`) linked to the legal URL. Plain-text twin `weather-attribution-text.blade.php`
  - [x] Included in the digest footer (after `legal-footer`) **and** the public sample footer — the sample renders provider-fetched weather too, so Apple's mark is mandated there as well. Text twins added under the same gate
  - [x] TDD: `tests/Feature/Digest/AttributionTest.php` (+ two cases in `SampleDigestMailTest.php`) — `weatherkit` → HTML has `alt="Apple Weather"`, inlined data URI (not a hotlink), legal URL, and the text source line; `weatherapi` → none of them

- [x] **Task 3: Local live-render verification (AC: 3, 4)** — plan Task 10
  - [x] Locally ran `TRIPCAST_WEATHER_PROVIDER=weatherkit MAIL_MAILER=log php artisan digest:send` for Kennett Square (18) + Dewey Beach (3). Rendered highs in the log: **Kennett 89°F, Dewey 84°F** (see note) — realistic air temps, **not** the heat-index-inflated 105°F; attribution present in HTML + text. (Live forecast drifts from the planning-day 97/86°F snapshot; the substance — no inflation + mark rendered — holds.)
  - [x] Trip created after Story 11.2 via `CreateTrip` → `destination_timezone = America/New_York` (a real IANA zone captured at creation, CAP-9)

- [x] **Task 4: Cutover + docs (AC: 5)** — plan Task 10
  - [~] `.env.example` keeps the default `weatherapi` (explicit opt-in). **Production `TRIPCAST_WEATHER_PROVIDER=weatherkit` flip is the human go-live step** — an env-only change on Forge, awaiting Clayton's go-ahead (a push to `origin/main` auto-deploys). See Completion Notes.
  - [x] Updated `docs/deployment.md` env checklist (Related facts) + the `.env.example` go-live checklist: the four `APPLE_WEATHERKIT_*` keys + `TRIPCAST_WEATHER_PROVIDER` cutover

- [x] **Task 5: Verification gates (AC: 6)** — full suite green: `php artisan test` 600/600, pint clean, phpstan 0 errors, eslint + vue-tsc clean, `build:ssr` built; `weatherapi` render byte-stable (no attribution artifacts)

## Dev Notes

### Critical guardrails (read first)

- **Attribution is legally mandatory** wherever WeatherKit data shows — gate it on the provider flag, not on a feature toggle, so it can never be on with the data absent (or vice-versa).
- **Inline the logo.** Email clients strip remote images/CSS; a hotlinked `weatherkit.apple.com` logo will silently vanish in many inboxes. Embed base64 (or CID). Do not alter the mark or the "Apple Weather"/"Weather" wordmark (Apple trademarks).
- **Cutover is the point of no return for accuracy.** Only flip the flag after the local `MAIL_MAILER=log` check confirms 97°F/86°F + attribution. Every push to `origin/main` auto-deploys to prod [docs/deployment.md] — read it before pushing the cutover; the flag is an env change, not code.
- **Digest stays byte-stable except the footer.** The attribution is additive; forecast rows, countdown, and existing footer links are unchanged.

### Existing patterns to copy (file:line)

| What | Where |
| --- | --- |
| Digest footer partial + `@include` | resources/views/emails/partials/ (footer with unsubscribe/end-trip links) |
| Provider flag | config/tripcast.php (`forecast.provider`, from 11.1) |
| Digest render test construction | tests/Feature/Digest/* (how `DigestMail` is built + `->render()`) |
| Forced single-send command for manual verify | app/Console/Commands/* (`digest:send {trip} --date= --to=`) |
| Prod env-checklist + deploy process | docs/deployment.md |

### Architecture & stack constraints

- Laravel 13 / PHP 8.3, Blade email templates, Tailwind (email-safe inline styles). Pest 4.
- No migration, no new dependency, no schema change — a Blade partial + a config-gated include + an env flip.
- Attribution endpoint (`/attribution/{language}`) is outside `/api/v1/` and needs no JWT.

### What NOT to do

- Do not hotlink the Apple logo; do not alter the mark/wordmark.
- Do not render attribution unconditionally (gate on `provider === 'weatherkit'`).
- Do not flip the flag before the local render check passes.
- Do not restyle or reorder the existing digest footer/forecast — attribution is purely additive.

### Previous story intelligence

- Stories 11.1 (provider + flag) and 11.2 (timezone persistence) must be merged first; this story assumes the WeatherKit path renders correct highs and the stored zone exists.
- The digest footer already carries legal/unsubscribe content (Stories 2.5 / 9.x) — add the attribution beside it, matching its inline-style pattern.

### Testing standards

- Pest 4 render test asserting attribution presence/absence by provider flag. The manual `MAIL_MAILER=log` check (AC 3) is a human gate, not an automated test — record the observed highs in the story's completion notes.

### Project Structure Notes

- New: `resources/views/emails/partials/weather-attribution.blade.php`, `tests/Feature/Digest/AttributionTest.php`. Modified: the digest footer partial(s) (HTML + text), `docs/deployment.md`, production env (+ `.env.example` note). Assets: inlined Apple Weather logo (base64 in the partial).

## Dev Agent Record

### Completion Notes

Story 11.3 — Apple Weather attribution + the provider cutover — is dev-complete and ready for review. Epic 11's final story.

**What shipped**
- A provider-gated attribution partial (`weather-attribution.blade.php` + `-text` twin). `@if (config('tripcast.forecast.provider') === 'weatherkit')` is the single gate — the mark can never render without the data, or vice-versa (the license mandate). The Apple Weather mark is **inlined** as a `data:image/png;base64,…` URI (email clients strip remote images), linked to `https://developer.apple.com/weatherkit/data-source-attribution/`, `alt="Apple Weather"`, mark/wordmark unaltered.
- **Dark-mode-legible:** the digest renders in dark mode (its card goes `#FFFFFF → #16232F`), so both Apple marks ship — the black `blk` mark on the light card, the white `wht` mark swapped in under `@media (prefers-color-scheme: dark)` via `.tc-aw-light/.tc-aw-dark` (the mechanism the digest head already uses). All swap CSS is itself provider-gated, so the `weatherapi` render is byte-identical to before.
- Added to **both** the daily digest footer and the **public sample** footer. The sample tripcast renders the demo city's provider-fetched forecast (`SampleForecast → WeatherProvider::fetchForecast`), so under WeatherKit it is Apple-sourced and the mark is legally required there too. (Beyond the story's literal task list, but the compliance intent covers it.)

**AC3/AC4 live-render gate (human gate, recorded here)** — ran locally with real WeatherKit creds, `TRIPCAST_WEATHER_PROVIDER=weatherkit MAIL_MAILER=log`:
- Kennett Square high rendered **89°F**, Dewey Beach **84°F** — realistic air temperatures, **not** the heat-index-inflated 105°F WeatherAPI produced (the whole point of the epic). The exact 97/86°F in the AC was the planning-day snapshot; WeatherKit is a live forecast that drifts daily, so the numbers differ — the *substance* (no inflation) is what's verified.
- Attribution present in the logged HTML (`alt="Apple Weather"` + inlined data URI + legal URL) and the plain-text twin (`Weather data by Apple Weather — <url>`). The two inlined data URIs decode to valid 154×28 PNGs.
- A trip created after Story 11.2 via `CreateTrip` captured `destination_timezone = America/New_York` at creation (CAP-9). The seeded verification trip + user were deleted afterward (dev DB left clean).

**⚠️ Remaining go-live step (human — Clayton):** the production cutover is an **env-only** change, not code. In the Forge Environment editor set `TRIPCAST_WEATHER_PROVIDER=weatherkit` and the four `APPLE_WEATHERKIT_*` keys, and upload the `.p8` to the `APPLE_WEATHERKIT_PRIVATE_KEY` path (git-ignored — transfer out-of-band). Keep `WEATHERAPI_KEY` set for an instant flip-back. I did **not** perform the prod flip or push to `main` (a push auto-deploys to prod) — that's your call once this review passes. Steps are documented in `docs/deployment.md` and the `.env.example` go-live checklist.

### Debug Log

- Attribution endpoint `GET /attribution/en` returns `serviceName` + logo asset paths but **no** `legalPageUrl` — used Apple's documented data-source page instead.
- WeatherKitProvider returns raw (unrounded) °C/°F floats; the render layer rounds for display — confirmed via the live log (89°/84°). No provider change needed.
- Verified `weatherapi` byte-stability: a rendered digest under the default provider contains neither `tc-aw` nor `Apple Weather`.

## File List

- `resources/views/emails/partials/weather-attribution.blade.php` (new)
- `resources/views/emails/partials/weather-attribution-text.blade.php` (new)
- `resources/views/emails/digest.blade.php` (modified — footer include + gated dark-swap CSS)
- `resources/views/emails/digest-text.blade.php` (modified — text include)
- `resources/views/emails/sample-digest.blade.php` (modified — footer include + gated dark-swap CSS)
- `resources/views/emails/sample-digest-text.blade.php` (modified — text include)
- `tests/Feature/Digest/AttributionTest.php` (new)
- `tests/Feature/Sample/SampleDigestMailTest.php` (modified — two attribution cases)
- `app/Console/Commands/CheckWeatherKitKey.php` (new — `weatherkit:check` cutover preflight)
- `tests/Feature/Weather/CheckWeatherKitKeyTest.php` (new)
- `.env.example` (modified — go-live checklist: WeatherKit cutover)
- `docs/deployment.md` (modified — key-persistence gotcha + `weatherkit:check` preflight in Related facts)
- `_bmad-output/implementation-artifacts/sprint-status.yaml` (modified — status)

## Change Log

- 2026-07-04 — Story 11.3 implemented: Apple Weather attribution (inlined, provider-gated, dark-mode-legible) in the digest **and** sample footers + text twins; AC3/AC4 local live-render gate passed (89°/84°F, no heat-index inflation; zone captured at creation); deployment/`.env.example` cutover docs. All gates green (600/600 tests, pint, phpstan, eslint, vue-tsc, build:ssr). Production env flip handed off to Clayton (env-only, not pushed).
- 2026-07-04 — Added `weatherkit:check` cutover-preflight command (+ 5 tests): confirms the running app can find, read, and ES256-sign with the `.p8` the config resolves (absolute or `base_path`-relative), independent of the provider flag, with an opt-in `--live` real-fetch. Documented the key-persistence gotcha (git-ignored `.p8` does not survive a zero-downtime deploy inside the release tree — store it in shared `storage/` or an absolute path) and the preflight step in `docs/deployment.md`. Suite 605/605.
