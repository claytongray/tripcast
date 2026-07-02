---
baseline_commit: bee5d16
---

# Story 9.6: Production go-live & deliverability

Status: in-progress (code slice done — blocked on the external runbook, Tasks 3–6)

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

As the builder,
I want tripcast running in production with authenticated, deliverable sending,
so that real users can sign up and receive their digests.

## Acceptance Criteria

**AC1 — App live: scheduler + worker + env checklist** *(FR-27)*
- **Given** the production deploy target
- **When** the app is live
- **Then** the scheduler fires the daily command on the send clock (AD-7), the queue worker runs on Redis, and the env checklist is complete (Google, WeatherAPI, MailerSend, postal address, heartbeat URL; Anthropic optional — the narrator ships deterministic).

**AC2 — Deliverability** *(FR-27, NFR-2)*
- **Given** the sending domain
- **When** authentication is checked
- **Then** SPF/DKIM/DMARC pass and a test digest lands in a Gmail inbox, not spam.

**AC3 — MailerSend List-Unsubscribe gate resolved** *(FR-27)*
- **Given** the plan gate (`#MS42235` — the daily `DigestMail` currently 422s on send)
- **When** the gate is resolved (plan upgrade vs. MailerSend built-in unsubscribe)
- **Then** digests send successfully with the RFC 8058 one-click header preserved (Gmail/Yahoo bulk-sender requirement — the header must not be dropped).

**AC4 — Heartbeat wired + production smoke** *(FR-27, AD-14)*
- **And** the heartbeat monitor is wired so a missed daily run alerts the builder, **and** one full production smoke passes: signup → confirm → a real trip receives a digest.

## Reality check (read first)

This is an **ops story**: the code side (heartbeat, scheduler at 09:00 ET, redis queue, production fail-fast guards, RFC 8058 headers) shipped in Epics 1–5 and was re-verified in recon. What remains is (a) a small **code-side slice** — completing the env checklist artifact that AC1 names, with a guard test — and (b) **external actions on the builder's accounts** (Forge, DNS, MailerSend plan, monitor) that no agent can or should perform. The dev run completes (a), then **HALTs and hands off (b) as the runbook below** — the story stays `in-progress` until the builder executes it; do NOT mark review on green tests alone.

## Tasks / Subtasks

### Code-side (dev agent executes now)

- [x] **Task 1 — Complete the env checklist artifact** (AC: 1)
  - [x] `.env.example`: add the two **critical missing** production vars — `MAILERSEND_API_KEY=` (missing entirely; the driver reads it from the vendor package config — annotate with a comment naming the tripcast MailerSend account, per retro action A4, so the cruisin4parts key mixup can't recur) and `TRIPCAST_HEARTBEAT_URL=` (+ optional `TRIPCAST_HEARTBEAT_TIMEOUT=5`) with a comment (dead-man's-switch GET; `{url}` on healthy, `{url}/fail` on unhealthy — AD-14). Also add commented-out optional `TRIPCAST_UNSUBSCRIBE_MAILTO=` (defaults to MAIL_FROM_ADDRESS).
  - [x] Add a `# ---- Production go-live checklist (Story 9.6 / FR-27) ----` comment block at the bottom of `.env.example` naming the values that MUST be real in production: `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://tripcast.fyi`, `MAIL_MAILER=mailersend`, `MAIL_FROM_ADDRESS=hello@tripcast.fyi`, `QUEUE_CONNECTION=redis`, `CACHE_STORE=redis`, `REDIS_CLIENT` (predis, or phpredis if the extension is installed on the server — the dev default `predis` is a deliberate choice, don't assume the server matches), `GOOGLE_GEOCODING_KEY` (Geocoding + Places APIs — 9.4), `WEATHERAPI_KEY` (plan must serve days=8 — verified 2026-07-01, 9.5), `MAILERSEND_API_KEY`, `TRIPCAST_POSTAL_ADDRESS`, `TRIPCAST_HEARTBEAT_URL`; `ANTHROPIC_API_KEY` optional (deterministic narrator ships live).
- [x] **Task 2 — Guard test: the checklist can't rot** (AC: 1)
  - [x] `tests/Feature/Ops/EnvExampleChecklistTest.php` (new, Pest): parse `base_path('.env.example')` and assert every production-required key is present as a **real assignment at line start** — `expect($contents)->toMatch('/^'.$key.'=/m')`, NOT `str_contains` (the Task 1 comment block names the same keys and commented-out optionals like `# TRIPCAST_UNSUBSCRIBE_MAILTO=` must not satisfy the guard, or "the checklist can't rot" guards nothing). Concrete key list: `APP_URL`, `DB_CONNECTION`, `DB_HOST`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`, `QUEUE_CONNECTION`, `REDIS_CLIENT`, `REDIS_HOST`, `REDIS_PORT`, `MAIL_MAILER`, `MAIL_FROM_ADDRESS`, `GOOGLE_GEOCODING_KEY`, `WEATHERAPI_KEY`, `MAILERSEND_API_KEY`, `TRIPCAST_POSTAL_ADDRESS`, `TRIPCAST_HEARTBEAT_URL`. Also assert the production fail-fast guards: `$this->app['env'] = 'production'` (flips `isProduction()`; the three ports are non-singleton closure binds so they re-resolve) + null key configs → `app(Geocoder::class)` / `app(PlaceAutocomplete::class)` / `app(WeatherProvider::class)` each throw — **pin the exception messages** (`toThrow(RuntimeException::class, 'GOOGLE_GEOCODING_KEY is not set')` etc.) so an unrelated RuntimeException can't satisfy them. (There is deliberately no guard assertion for `MAILERSEND_API_KEY` — the driver has no boot-time guard; it fails at send time.)
  - [x] **Gates:** `php artisan test --compact`, `vendor/bin/pint --dirty --format agent`, `./vendor/bin/phpstan analyse`.

### External runbook (builder executes — the dev agent HALTs here and hands off)

- [ ] **Task 3 — Forge deploy** (AC: 1): provision/confirm the single Forge server (Nginx + PHP 8.3 + MySQL 8 + Redis); site for `tripcast.fyi`; deploy script per ARCHITECTURE-SPINE: `composer install --no-dev` (`--no-dev` added deliberately over the spine's plain `composer install`), `npm ci && npm run build` (includes SSR), `php artisan migrate --force`, restart SSR daemon + queue worker; daemons: `php artisan queue:work redis` (supervised) + Inertia SSR; per-minute cron `php artisan schedule:run`; scheduled DB backups; production `.env` filled from the Task 1 checklist (`php artisan config:cache` after).
- [ ] **Task 4 — Sending-domain authentication** (AC: 2): in MailerSend, confirm the `tripcast.fyi` sending domain is verified and add its SPF + DKIM records to DNS; add a DMARC record (start `p=none` with `rua=` reporting, tighten later); verify alignment (mail-tester.com or Gmail "show original" — SPF pass, DKIM pass, DMARC pass); send a test to a Gmail inbox and confirm it lands in Primary/Updates, not spam. **Sequencing note:** the daily `DigestMail` will 422 until Task 5 clears the gate — use `SampleDigestMail` or any transactional send (magic link) for the SPF/DKIM/inbox check now, and re-confirm inbox placement with a real `DigestMail` after Task 5.
- [ ] **Task 5 — Resolve the MailerSend List-Unsubscribe gate `#MS42235`** (AC: 3): **DECIDED 2026-07-02 — no plan upgrade; the custom header is dropped by Story 9.9** (MailerSend support confirmed custom headers need Professional/Enterprise, but they manage a List-Unsubscribe header on every plan; tripcast is far below the Gmail/Yahoo 5,000/day bulk-sender threshold, so the custom header was an optimization, not a requirement — retro action **A2 superseded**). Remaining work here: after Story 9.9 lands, send a real `DigestMail` (`php artisan digests:preview --email=…` or the production path) and confirm **accepted, no 422**, then re-confirm inbox placement per Task 4's sequencing note. The 9.6 AC3 wording in epics.md was amended to match.
- [ ] **Task 6 — Heartbeat monitor + production smoke** (AC: 4): create a check on a dead-man's-switch service (e.g. healthchecks.io — expected daily ping, grace period past 09:00 ET, alert to the builder's email; the `/fail` URL arm signals unhealthy runs), set `TRIPCAST_HEARTBEAT_URL` in production; then run the full smoke: real signup at tripcast.fyi → magic-link confirm → create a real near-term trip (departure within 7 days) → next 09:00 ET run delivers the digest to a real inbox → heartbeat received on the monitor → `digests:run` log line shows healthy. Mark this story review/done only after the smoke passes.

## Dev Notes

### What already exists (verified by recon — do not rebuild)
- **Heartbeat (AD-14):** `SendDailyDigests::emitHeartbeat()` GETs `config('tripcast.heartbeat.url')` (`{url}` healthy / `{url}/fail` unhealthy — unhealthy ⟺ due>0 && dispatched==0 or select failure); unset URL = silent no-op; ping failures log a warning, never break the run; run outcome is the structured `digests:run` log line (due/dispatched/healthy/duration_ms/error). [Source: app/Console/Commands/SendDailyDigests.php:29–113; config/tripcast.php:224–227]
- **Scheduler:** `digests:send` `dailyAt('09:00')->timezone('America/New_York')` + daily `model:prune` for LoginToken. [Source: routes/console.php:13–21]
- **Queue:** redis via `QUEUE_CONNECTION=redis` (no Horizon — plain supervised `queue:work`); failed jobs → `failed_jobs` table. [Source: config/queue.php:16, 67–74, 123–127; composer.json]
- **RFC 8058 headers:** `DigestMail::headers()` already emits `List-Unsubscribe` (signed HTTPS one-click + mailto) and `List-Unsubscribe-Post` — **the code side of AC3 is done**; the 422 is purely the MailerSend account plan. [Source: app/Mail/DigestMail.php:62–70]
- **Production fail-fast guards:** missing `GOOGLE_GEOCODING_KEY` / `WEATHERAPI_KEY` throw RuntimeException in production (never fake data); `DB::prohibitDestructiveCommands` active; Preview* commands refuse to run. [Source: app/Providers/AppServiceProvider.php:38–92, 114–116]
- **Live-vendor checks already done this sprint:** WeatherAPI key serves `days=8` (8 entries, verified 2026-07-01 in Story 9.5); Google key serves Places autocomplete + Details live (verified in Story 9.4).

### The two known go-live blockers (both external)
1. **MailerSend `#MS42235`** — `DigestMail` 422s on send until Story 9.9 removes the custom headers (Task 5; the "must not be dropped" stance was reversed 2026-07-02 — the Gmail/Yahoo mandate only binds bulk senders at 5,000+ msgs/day, and MailerSend manages its own List-Unsubscribe header on every plan).
2. **Launch precondition from outside this epic:** Epic 8's real promo catalog OR the promo slot suppressed — placeholder ASINs must never reach a real inbox. Surface this in the handoff (suppression = set every catalog profile empty or gate `shouldShowPromo`; decision is the builder's). [Source: epics.md line 50, sprint-status BUILD ORDER note]

### Env facts for Task 1 (recon-verified)
- `MAILERSEND_API_KEY` is read by `vendor/mailersend/laravel-driver/config/mailersend-driver.php` — nothing in `config/services.php`; it is genuinely absent from `.env.example` today. `TRIPCAST_HEARTBEAT_URL`/`_TIMEOUT` likewise absent. `TRIPCAST_UNSUBSCRIBE_MAILTO` defaults to `MAIL_FROM_ADDRESS` (`config/tripcast.php:209`).
- Retro action **A4** (annotate the MailerSend key with the account name) applies to `.env.example`'s comment — the builder's local `.env` annotation is their own copy step.

### Testing standards
- The guard test is plain file parsing + container assertions — no HTTP, no mocks of externals. For the production-guard assertions use `$this->app['env'] = 'production'` (or `app()->detectEnvironment`) with the key configs set to null, expect RuntimeException on `app(Geocoder::class)` etc.; restore env after. Everything external is deliberately untestable here — that's what the smoke in Task 6 is for.

### Project Structure Notes
- **New:** `tests/Feature/Ops/EnvExampleChecklistTest.php`.
- **Modified:** `.env.example` only. **No PHP/Vue changes** — any temptation to "add a preflight command" or touch `DigestMail` is scope creep; resist it.

### Previous story intelligence (9.1–9.5)
- `.env.example` gained `TRIPCAST_POSTAL_ADDRESS` (9.1) and the restricted-key comment on `GOOGLE_GEOCODING_KEY` (9.4) — extend, don't reorganize.
- Open retro actions folded into this story (sprint-status keys `epic-1-retro-A2-mailersend-plan-upgrade`, `epic-1-retro-A4-env-key-annotation`): **A4** is satisfied by Task 1's `.env.example` annotation — the dev run flips it to `done` (the builder's local `.env` copy inherits the comment); **A2** stays `open` until the builder completes Task 5.
- The sample/digest/legal surfaces are all launch-ready from 9.1–9.5; this story is the last mile only.

### References
- [Source: _bmad-output/planning-artifacts/epics.md#Story-9.6 (lines 727–748), #FR-27 (line 48), launch note (line 50)]
- [Source: _bmad-output/planning-artifacts/architecture/architecture-tripcast-2026-06-28/ARCHITECTURE-SPINE.md deployment/operations section (lines ~264–290); AD-14]
- [Source: app/Console/Commands/SendDailyDigests.php; routes/console.php; config/tripcast.php; app/Mail/DigestMail.php:62–70; app/Providers/AppServiceProvider.php]
- [Source: _bmad-output/implementation-artifacts/epic-1-retro-2026-07-01.md (A2/A4); sprint-status.yaml action_items]

## Dev Agent Record

### Agent Model Used

claude-fable-5

### Debug Log References

- None. Red→green: the checklist guard failed on exactly the two genuinely missing vars (`MAILERSEND_API_KEY`, `TRIPCAST_HEARTBEAT_URL`), passed after Task 1.

### Completion Notes List

- **Code slice (Tasks 1–2) complete:** `.env.example` gained `MAILERSEND_API_KEY` (annotated with the tripcast-account warning — retro **A4 done**), `TRIPCAST_HEARTBEAT_URL` (+ commented `_TIMEOUT` and `TRIPCAST_UNSUBSCRIBE_MAILTO`), and the production go-live checklist block. New `EnvExampleChecklistTest`: 17 line-anchored key assertions + 3 production fail-fast guard assertions with pinned exception messages. Full suite **358 passed** (1167 assertions); pint clean; phpstan 0 errors.
- **HALTED per the story's contract:** Tasks 3–6 are external actions on the builder's accounts (Forge provisioning + daemons + cron, SPF/DKIM/DMARC DNS, the MailerSend `#MS42235` plan decision — retro **A2 stays open**, heartbeat monitor + the production smoke). The story stays `in-progress`; mark review/done only after the Task 6 smoke passes.
- **Handoff reminders:** launch precondition from outside this epic — Epic 8's real promo catalog OR the promo slot suppressed (placeholder ASINs must never reach a real inbox); Task 4's inbox check must use a transactional send until Task 5 clears the DigestMail 422.

### File List

**New:**
- `tests/Feature/Ops/EnvExampleChecklistTest.php`

**Modified:**
- `.env.example` (MAILERSEND_API_KEY, TRIPCAST_HEARTBEAT_URL/_TIMEOUT, TRIPCAST_UNSUBSCRIBE_MAILTO, go-live checklist block)

### Change Log

- 2026-07-01 — Story 9.6 code slice: completed the production env checklist artifact (+2 critical missing vars, annotated per retro A4) with a rot-proof guard test (17 key assertions + 3 production fail-fast guards). Full suite 358 passed. External runbook (Forge, DNS auth, MailerSend #MS42235 gate, heartbeat monitor, production smoke) handed off to the builder — story remains in-progress until the smoke passes.
