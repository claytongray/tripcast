---
baseline_commit: ac051fb094c3361b0e11c0beb60b6c38b9104d64
---

# Story 11.3: Apple Weather attribution + provider cutover

Status: ready-for-dev

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

- [ ] **Task 1: Fetch the attribution assets (AC: 1, 2)** — plan Task 9
  - [ ] `curl -s https://weatherkit.apple.com/attribution/en | jq '{legalPageUrl, logo, name}'`; download the light-mode logo and base64-encode it for inlining
  - [ ] Record the exact `legalPageUrl` for the partial + the test assertion

- [ ] **Task 2: Attribution partial, provider-gated (AC: 1, 2)** — plan Task 9
  - [ ] Create `resources/views/emails/partials/weather-attribution.blade.php`: `@if (config('tripcast.forecast.provider') === 'weatherkit')` → inlined `<img alt="Apple Weather" src="data:image/png;base64,...">` linked to the legal URL
  - [ ] Include it in the digest footer (locate the footer partial that already renders unsubscribe/end-trip links); add the text-digest line under the same gate
  - [ ] TDD: `tests/Feature/Digest/AttributionTest.php` — provider `weatherkit` → HTML contains `alt="Apple Weather"` + the legal URL; provider `weatherapi` → contains neither

- [ ] **Task 3: Local live-render verification (AC: 3, 4)** — plan Task 10
  - [ ] Locally set `TRIPCAST_WEATHER_PROVIDER=weatherkit` + `MAIL_MAILER=log`; force-send a digest for a seeded Kennett + Dewey trip via the existing forced-send command; confirm 97°F / 86°F + attribution in `storage/logs/laravel.log`
  - [ ] `php artisan tinker --execute 'echo App\Models\Trip::latest()->first()->destination_timezone;'` → a real IANA zone

- [ ] **Task 4: Cutover + docs (AC: 5)** — plan Task 10
  - [ ] Set `TRIPCAST_WEATHER_PROVIDER=weatherkit` in the production env per `docs/deployment.md`; keep `.env.example` default `weatherapi`
  - [ ] Update `docs/deployment.md` env checklist: the four `APPLE_WEATHERKIT_*` keys + `TRIPCAST_WEATHER_PROVIDER`

- [ ] **Task 5: Verification gates (AC: 6)** — full suite green on the `weatherkit` flag

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
