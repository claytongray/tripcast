---
baseline_commit: 0cbad9318a301d18fa4409182b509bdb46ea113c
---

# Story 1.1: Project foundation & passwordless magic-link authentication

Status: in-progress

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

As the builder,
I want the app scaffolded with passwordless magic-link auth replacing the starter kit's password auth,
so that every later story builds on a passwordless, SSR-ready foundation.

> **Greenfield note:** No application code exists yet and this is **not yet a git repository**. This story stands up the project. The dev agent should `git init` early so subsequent stories have history, and commit the scaffold once it builds.

## Acceptance Criteria

**AC1 — Project scaffold from the Laravel Vue starter kit** *(FR-3, Architecture Stack/Starter)*
- **Given** a clean repo
- **When** the project is initialized
- **Then** it starts from the Laravel **Vue starter kit** (Inertia 3 + Vue 3 + Tailwind 4 + shadcn-vue) with SSR/Vite configured, **MySQL 8 with a case-insensitive collation on `users.email`**, and **Redis** wired for both queue and cache.

**AC2 — Fortify removed; no passwords anywhere** *(FR-3, AD-6)*
- **Given** the starter kit ships Fortify password auth + Wayfinder typed routes
- **When** the scaffold is cleaned
- **Then** Fortify is **removed**, its dropped auth routes are cleaned so **Wayfinder's generated types still build**, and **no password field exists anywhere** in the app (no password column, no password inputs, no reset flow).

**AC3 — `users` table** *(AD-6, AD-12, AD-13, AD-19)*
- **Given** the database migrations
- **When** they run
- **Then** a `users` table exists with: `email` (**unique, case-insensitive collation**), `plan` (string, default `free`; values `free|ad_free`), `timezone` (string, default `America/New_York`), `is_admin` (bool, default false), `email_opted_out` (bool, default false). No password/remember-token-password columns.

**AC4 — `login_tokens` + RequestMagicLink action** *(FR-3, AD-6)*
- **Given** a `login_tokens` table (`user_id`, `token_hash`, `expires_at`, `consumed_at`)
- **When** a magic link is requested via a `RequestMagicLink` action
- **Then** it issues a **single-use, time-limited** link (hashed token stored, raw token only in the emailed URL), **invalidates prior unconsumed tokens** for that email, and request is **throttled per email**.

**AC5 — Clicking a valid link authenticates with a long-lived session** *(FR-3, FR-4, UX-DR10)*
- **Given** a seeded user with a valid unconsumed magic link
- **When** they click it
- **Then** the token is **consumed** (`consumed_at` set), a **long-lived cookie session** is established (refreshed on activity until explicit logout), and they land in the (placeholder) dashboard.

**AC6 — Expired/consumed link is calm, not a dead end** *(FR-3, UX-DR10)*
- **Given** an expired or already-consumed magic link
- **When** it is clicked
- **Then** a calm result page is shown with **one-tap resend** (no error stack, no dead end). The check-your-email interstitial shows "sent a link to {email}, expires in N min" with a resend affordance.

**AC7 — Design-token foundation + primitives** *(UX-DR1, UX-DR2, UX-DR14)*
- **Given** the UI foundation
- **When** the Tailwind 4 theme and base components are set up
- **Then** the **design-token system** (color light+dark pairs, typography scale, spacing, radius) is the single source of truth, with `<meta name="color-scheme" content="light dark">` and a `prefers-color-scheme: dark` mapping; the **Button** (accent fill / ghost secondary, max one primary per surface) and **Input** (surface-raised, hairline, visible 2px accent focus ring, inline validation in ink-secondary) primitives are implemented once for reuse.

**AC8 — Cross-cutting gates** *(UX-DR18, UX-DR19)*
- WCAG 2.2 AA on every surface (semantic landmarks, labeled controls, visible focus, ≥44px targets, contrast); responsive mobile-first single column. These are acceptance gates on the auth/interstitial/result screens built here.

## Tasks / Subtasks

- [x] **Task 1 — Scaffold the project** (AC: 1)
  - [x] `git init`; create the app from the Laravel **Vue starter kit** (Inertia 3 + Vue 3 + Tailwind 4 + shadcn-vue)
  - [x] Configure Inertia **SSR** via `@inertiajs/vite`; confirm `npm run build` produces the SSR bundle and dev server runs
  - [x] Configure **MySQL 8** connection; set a **case-insensitive collation for `users.email`** (e.g. `utf8mb4_0900_ai_ci`) — AD-3/AD-10 depend on it
  - [x] Configure **Redis** for `queue` and `cache`
  - [x] Commit the working scaffold
- [x] **Task 2 — Remove Fortify, keep types building** (AC: 2)
  - [x] Remove Fortify and password-auth wiring; delete password/reset routes, controllers, views, and the password column path
  - [x] Re-generate / fix **Wayfinder** typed routes so the build is clean with the dropped routes gone
  - [x] Verify no password input or reset flow remains anywhere
- [x] **Task 3 — `users` migration + model** (AC: 3)
  - [x] Migration: `email` (unique, CI collation), `plan` default `free`, `timezone` default `America/New_York`, `is_admin` default false, `email_opted_out` default false; no password columns
  - [x] `User` model (singular, snake_case table) with appropriate casts/fillable
- [x] **Task 4 — Magic-link auth** (AC: 4, 5, 6)
  - [x] Migration + `LoginToken` model: `user_id`, `token_hash`, `expires_at`, `consumed_at`
  - [x] `RequestMagicLink` action in `app/Actions/`: create-or-match user by CI email, generate token, **invalidate prior unconsumed tokens for that user**, store hash, email the link; **throttle per email**
  - [x] Auth controller (`app/Http/Controllers/Auth/`) + routes: request-link, consume-link (GET), logout
  - [x] Consume: validate unexpired + unconsumed by hash → set `consumed_at`, log the user in with a **long-lived cookie session**, redirect to dashboard
  - [x] Expired/consumed → calm result page with one-tap resend; check-your-email interstitial with resend
  - [x] Schedule pruning of expired/consumed `login_tokens` (per conventions)
- [x] **Task 5 — UI foundation** (AC: 7, 8)
  - [x] Tailwind 4 theme = the DESIGN.md tokens (color light+dark pairs, type scale, spacing, radius); `color-scheme` meta + `prefers-color-scheme: dark` mapping
  - [x] Button + Input primitives per DESIGN.md (shadcn-vue base, extended to tokens)
  - [x] Build the auth screens (request-link form, check-email interstitial, expired/used result) on the primitives; meet the a11y + responsive gates
  - [x] Minimal authenticated **placeholder dashboard** as the post-login landing (full dashboard is Epic 3)
- [x] **Task 6 — Tests**
  - [x] Feature tests: request link issues a single-use token + invalidates prior; valid link logs in + consumes; expired/consumed rejected with resend; throttle enforced; no password routes resolve
  - [x] Migration test: `users.email` collation is case-insensitive (e.g. `Foo@x.com` matches `foo@x.com`)

## Dev Notes

### Architecture & stack (binding)
- **Paradigm:** Laravel layered + provider ports + pipes-and-filters send pipeline. Presentation (Inertia Pages / Blade emails) → thin Controllers → Actions (use-cases) → Eloquent Models. [Source: ARCHITECTURE-SPINE.md#Design-Paradigm]
- **Stack pins:** PHP 8.3+, Laravel 13.x, Inertia 3 (SSR via `@inertiajs/vite`), Vue 3 (Composition API), Node 22+, Tailwind 4, MySQL 8 (CI `users.email`), Redis (queue+cache), MailerSend driver. Capture exact resolved versions from `composer.lock`/`package-lock.json` once scaffolded. [Source: ARCHITECTURE-SPINE.md#Stack]
- **Starter:** Laravel **Vue starter kit**; it ships **Fortify + Wayfinder** — v1 **removes Fortify and builds custom magic-link auth (AD-6)** (a replacement, not a trim) and cleans dropped auth routes so Wayfinder types still build. [Source: ARCHITECTURE-SPINE.md#Stack (Starter)]

### AD-6 — auth rules (binding) [Source: ARCHITECTURE-SPINE.md#AD-6]
- Magic-link **login** uses a dedicated **single-use `login_tokens`** table (hashed token, expiry, `consumed_at`); clicking consumes it; requesting a new link **invalidates prior unconsumed** tokens for that user.
- **Login uses a stored single-use token, not a bare signed URL** — AD-6 supersedes the glossary's loose "Magic Link = a signed URL" phrasing.
- **Long-lived cookie sessions** refreshed on activity until explicit logout. **No passwords anywhere.** **Throttle login per email.**
- Email *action* links (end-trip/unsubscribe/feedback) and the promo redirect are **later stories** (2.5/2.6/5.4) — not in scope here, but build the auth controller so signed-route patterns can extend cleanly.
- Convention: expired/consumed `login_tokens` pruned on a schedule. [Source: ARCHITECTURE-SPINE.md#Consistency-Conventions (Auth surfaces)]

### Data model created here [Source: ARCHITECTURE-SPINE.md#Structural-Seed]
- `USER { email (unique, CI), plan "free|ad_free default free" (AD-19), timezone "default America/New_York, unused for sends v1" (AD-7), is_admin (AD-12), email_opted_out (AD-13) }`
- `LOGIN_TOKEN { user_id, token_hash, expires_at, consumed_at }`
- Create **only these two tables** in this story. `trips`, `email_logs`, `feedback`, `promo_events` belong to later stories — do **not** create them now.
- `plan`, `timezone`, `is_admin`, `email_opted_out` are created now but only fully exercised later (Epics 2/3/5); seed sensible defaults.

### SPEC capabilities & constraints [Source: specs/spec-tripcast/SPEC.md]
- **FR-3:** signed, time-limited, single-use link; valid → dashboard; expired/consumed → rejected + offer fresh; no password field anywhere.
- **FR-4:** returning user with a valid session not re-prompted; logout requires a new magic link.
- **Constraint:** "Magic-link only, no passwords anywhere… requesting a new link invalidates prior unconsumed ones." (AD-6)

### UX [Source: ux-designs/ux-tripcast-2026-06-28/DESIGN.md + EXPERIENCE.md]
- **UX-DR1/DR2/DR14:** design-token foundation (light+dark pairs, type scale 30/38/600 display · 22/30/600 title · 17/26/500 subtitle · 16/26/400 body · 13/20/400 meta; spacing 4/8/12/16/24/32/48/64; radius sm8/md14/lg20/full); ship `color-scheme` meta + `prefers-color-scheme: dark`; Button (accent fill / ghost, one primary per surface) + Input (surface-raised, hairline, 2px accent focus ring, inline validation in ink-secondary) primitives. [Source: DESIGN.md#Components, #Colors, #Typography]
- **UX-DR10:** email = one accent button; web = centered `surface-raised` "check your inbox — sent a link to {email}, expires in N min" interstitial with resend, and a calm expired/used result page with one-tap resend (no dead end). [Source: EXPERIENCE.md Component Patterns / State Patterns / Flow]
- **Microcopy** lives in EXPERIENCE.md Voice & Tone; reuse the locked strings for the interstitial/result.

### Testing standards
- Laravel feature tests (Pest or PHPUnit per starter kit default). Cover the AC behaviors above; assert the CI-collation match and the no-password-routes invariant explicitly.

### Project Structure Notes
- Targets the architecture source tree: `app/Actions/RequestMagicLink`, `app/Http/Controllers/Auth/`, `app/Models/{User,LoginToken}`, `routes/web.php` (magic-link routes), `app/Providers/` (bindings), `resources/js/Pages/Auth/*` (Inertia), Tailwind theme = DESIGN tokens. [Source: ARCHITECTURE-SPINE.md#Structural-Seed (source tree)]
- No conflicts: greenfield. The dashboard built here is a **placeholder** landing only; the real dashboard is Epic 3 (Story 3.1).

### References
- [Source: _bmad-output/planning-artifacts/epics.md#Story-1.1]
- [Source: _bmad-output/planning-artifacts/architecture/architecture-tripcast-2026-06-28/ARCHITECTURE-SPINE.md#AD-6, #Stack, #Structural-Seed, #Consistency-Conventions]
- [Source: _bmad-output/specs/spec-tripcast/SPEC.md#FR-3, #FR-4, #Constraints]
- [Source: _bmad-output/planning-artifacts/ux-designs/ux-tripcast-2026-06-28/DESIGN.md#Components, EXPERIENCE.md#UX-DR10]

## Dev Agent Record

### Agent Model Used

Amelia (Senior Software Engineer) — claude-opus-4-8[1m]

### Debug Log References

- `npm run build:ssr` — green (client + SSR bundle `bootstrap/ssr/app.js`).
- `npm run types:check` (vue-tsc) — clean; Wayfinder generated types build (AC2).
- `./vendor/bin/pest` — 24 passed, 93 assertions (MySQL `tripcast_test`).
- HTTP smoke (`php artisan serve`): `/`→200, `/login`→200 (Inertia), guest `/dashboard`→302→`/login`.
- DB: `users.email` collation verified `utf8mb4_0900_ai_ci`; `ADMIN@…` matches `admin@…`.

### Resolved versions (captured from composer.lock / package.json)

PHP 8.3.12 · Laravel 13.17.0 · Inertia (laravel) 3.1.0 · @inertiajs/vue3 ^3.0.0 · Vue ^3.5.13 ·
Node 22.12.0 · Tailwind ^4.1.1 · Vite ^8.0.0 · Wayfinder 0.1.20 · predis 3.5.1 · Pest 4.7.4 ·
MySQL 9.0.1 (engine ≥8 collation `utf8mb4_0900_ai_ci`) · Redis 7.2.6.

### Completion Notes List

- **Stack note:** the local engine is **MySQL 9.0.1** (≥8), which supplies the required
  `utf8mb4_0900_ai_ci` collation; the `email` column sets it explicitly so CI matching holds
  regardless of DB default.
- **Redis client:** phpredis extension is absent locally, so Redis (queue+cache) is wired via the
  `predis/predis` composer package (`REDIS_CLIENT=predis`).
- **Fortify removal scope:** removed Fortify password auth **plus** the kit's 2FA, passkeys, and the
  whole settings surface, and pruned the heavy sidebar chrome — replaced with a minimal token-based
  AppLayout + placeholder dashboard. Wayfinder types regenerate clean from the trimmed routes.
- **User shape:** email-only account (no `name`, `password`, `remember_token`, or `email_verified_at`)
  per AD-6 / the magic-link-only flow.
- **Sessions:** long-lived (`SESSION_LIFETIME=43200`, `SESSION_EXPIRE_ON_CLOSE=false`), refreshed on
  activity; `Auth::login()` with no remember-me cookie.
- **Mail:** `MagicLinkMail` sent **synchronously** (link must be immediate) with a plain-text twin;
  local driver is `log`, tests use `Mail::fake()`. MailerSend driver wiring is deferred (no AC here).
- **Dark mode:** `.dark`/`.light` class toggle (useAppearance) **plus** a `prefers-color-scheme: dark`
  CSS fallback and `<meta name="color-scheme">` for SSR/no-JS — explicit `.light` beats the media query.
- **Deferred (not in scope):** real landing hero + trip form (Story 1.2), real dashboard (Story 3.1),
  signed email-action routes (2.5/2.6/5.4), MailerSend prod driver.

### File List

**Created**
- `config/tripcast.php` — magic-link TTL + per-email throttle config
- `database/migrations/2026_06_29_000001_create_login_tokens_table.php`
- `app/Models/LoginToken.php` — single-use, hashed, prunable
- `app/Actions/RequestMagicLink.php`
- `app/Mail/MagicLinkMail.php`
- `resources/views/emails/magic-link.blade.php` · `resources/views/emails/magic-link-text.blade.php`
- `app/Http/Controllers/Auth/MagicLinkController.php`
- `routes/auth.php`
- `resources/js/pages/auth/RequestLink.vue` · `CheckEmail.vue` · `MagicLinkResult.vue`
- `tests/Feature/Auth/MagicLinkTest.php` · `tests/Feature/Auth/NoPasswordAuthTest.php` · `tests/Feature/UserEmailCollationTest.php`

**Modified**
- `.env` / `.env.example` — app name, CI collation, Redis queue+cache, long session
- `database/migrations/0001_01_01_000000_create_users_table.php` — email-only schema (CI collation), drop `password_reset_tokens`
- `app/Models/User.php` — email-only model (plan/timezone/is_admin/email_opted_out, casts, loginTokens())
- `database/factories/UserFactory.php` · `database/seeders/DatabaseSeeder.php`
- `bootstrap/providers.php` · `app/Providers/AppServiceProvider.php` — drop Fortify/password defaults
- `routes/web.php` · `routes/console.php` (prune schedule)
- `config/auth.php` — remove password reset broker
- `resources/css/app.css` — DESIGN token theme (palette, type scale, radius)
- `resources/views/app.blade.php` — color-scheme meta + light/dark class + prefers-color-scheme bg
- `resources/js/app.ts` — layout map (drop settings); `vite.config.ts` — Inter font
- `resources/js/composables/useAppearance.ts` — concrete `.dark`/`.light` resolution
- `resources/js/components/ui/input/Input.vue` · `resources/js/components/InputError.vue` — token styling
- `resources/js/layouts/AppLayout.vue` · `resources/js/layouts/auth/AuthSimpleLayout.vue`
- `resources/js/pages/Welcome.vue` · `resources/js/pages/Dashboard.vue`
- `resources/js/types/auth.ts` — User type
- `phpunit.xml` — MySQL `tripcast_test`; `tests/Pest.php` — RefreshDatabase
- `composer.json` (remove fortify, add predis) · `package.json`/lockfiles

**Deleted**
- Fortify/passkey/2FA: `app/Providers/FortifyServiceProvider.php`, `config/fortify.php`,
  `app/Actions/Fortify/*`, `app/Concerns/PasswordValidationRules.php`, passkey + 2FA migrations
- Settings surface: `app/Http/Controllers/Settings/*`, `app/Http/Requests/Settings/*`, `routes/settings.php`
- Frontend: `resources/js/pages/auth/*` (kit), `resources/js/pages/settings/*`, settings/sidebar chrome
  components + layouts, `composables/useTwoFactorAuth.ts`
- Tests: kit auth/settings/dashboard tests

### Change Log

| Date | Change |
| --- | --- |
| 2026-06-29 | Story 1.1 implemented: scaffold (AC1), Fortify removed (AC2), users table + model (AC3), magic-link auth (AC4–6), design-token UI foundation + auth screens (AC7–8), tests (24 passing). Status → review. |

### Review Findings

> Code review 2026-06-29 — backend subset (Group A: `app/`, migrations, config, routes, email views, Feature/Auth tests). Frontend (Group B) pending a follow-up run. Layers: Blind Hunter, Edge Case Hunter, Acceptance Auditor.

**Decision needed (resolved 2026-06-29)**

- Resolved → Patch: GET-based single-use consume — chose to add a **confirmation-POST interstitial** (GET renders a "Sign in" page; POST consumes + logs in). See patch list.
- Resolved → Patch: Long-lived session — chose to **commit a long-lived default** (`SESSION_LIFETIME=43200`, 30 days). See patch list.

**Patch**

- [x] [Review][Patch] Convert consume to a confirmation-POST interstitial — GET `auth/magic/{token}` renders a "Sign in" page without consuming; a CSRF-protected POST consumes the token and logs in. Closes prefetch-consumption + login-CSRF [routes/auth.php:14, app/Http/Controllers/Auth/MagicLinkController.php:69-87] (resolved decision)
- [x] [Review][Patch] Commit long-lived session default — set `SESSION_LIFETIME=43200` (30 days) in committed config/`.env.example` so fresh checkouts satisfy AC5 [config/session.php:35] (resolved decision)
- [x] [Review][Patch] No global/IP throttle on `POST /login` — only per-email; an attacker can rotate addresses to email-bomb arbitrary recipients and create unbounded `users` rows [app/Http/Controllers/Auth/MagicLinkController.php:33-49] (blind)
- [x] [Review][Patch] Magic-link mail sent synchronously — queue it; sync send is a request-thread DoS amplifier and a send failure leaves an orphan token + burned throttle + a 500 [app/Actions/RequestMagicLink.php:45] (blind+edge)
- [x] [Review][Patch] Non-atomic token consumption (TOCTOU) — read-then-write lets concurrent requests both authenticate; use a conditional `UPDATE … WHERE consumed_at IS NULL` (check affected rows) or row lock in a transaction [app/Http/Controllers/Auth/MagicLinkController.php:75-83] (blind+edge)
- [x] [Review][Patch] `handle()` not atomic — `firstOrCreate` can hit the email unique index (unhandled 500) and the delete-then-create sequence races under concurrency; wrap in a transaction [app/Actions/RequestMagicLink.php:30-41] (edge)
- [x] [Review][Patch] `is_admin` (and `plan`) mass-assignable — privilege fields in `#[Fillable]`; a latent privilege-escalation footgun for any future `create()`/`update()`. Remove/guard them [app/Models/User.php:23] (blind)
- [x] [Review][Patch] Email not normalized in app code — `handle()` only `trim()`s; case-insensitive matching relies solely on the MySQL-only collation (breaks on the `sqlite` default driver) and diverges from the lowercased throttle key. Lowercase before `firstOrCreate` [app/Actions/RequestMagicLink.php:26-30] (blind+edge)
- [x] [Review][Patch] Leftover Fortify reference — `tests/TestCase.php` imports `Laravel\Fortify\Features` (package removed); latent fatal if `skipUnlessFortifyHas()` is ever called, and contradicts AC2. Remove the import + unused helper [tests/TestCase.php:6,10-15] (auditor)
- [x] [Review][Patch] Dead `ProfileValidationRules` trait — unused starter-kit remnant validating a non-existent `name` column; delete it (AC2/AC3 cleanliness) [app/Concerns/ProfileValidationRules.php] (auditor+blind)
- [x] [Review][Patch] Unguarded magic-link config — `ttl_minutes`/`decay_minutes`/`max_attempts` accept 0 or negative (never-usable link, disabled throttle, total lockout) and the throttle message can read "0 minutes". Floor the values [config/tripcast.php:19-25, app/Http/Controllers/Auth/MagicLinkController.php:107-114] (edge)
- [x] [Review][Patch] Null-safe gap on consume result — `$record?->user->email` guards the token but not the relation; use `$record?->user?->email` [app/Http/Controllers/Auth/MagicLinkController.php:77] (blind)

**Deferred**

- [x] [Review][Defer] Attacker can invalidate a victim's pending link by re-requesting [app/Actions/RequestMagicLink.php:33] — deferred, inherent to AC4's invalidate-prior-tokens rule and bounded by the per-email throttle
- [x] [Review][Defer] Frontend chrome remnant — shared Inertia `sidebarOpen` and related kit cruft [app/Http/Middleware/HandleInertiaRequests.php] — deferred to the Group B (frontend) review pass

**Resolution (2026-06-29)**

All 12 patches applied and verified: `php artisan test` 25 passed / 106 assertions, Pint clean, **PHPStan 0 errors** (was failing with 3 errors at the story HEAD — the "builds clean" note did not hold for PHPStan). New file: `resources/js/pages/auth/MagicLinkConfirm.vue` (the GET confirm screen). Two pre-existing PHPStan errors in story-1.1 code were surfaced and fixed alongside the patches:

- `app/Actions/RequestMagicLink.php` — `handle()` return annotation said `Illuminate\Support\Carbon`, but `Date::use(CarbonImmutable::class)` makes `now()` return `CarbonImmutable`; annotation corrected.
- `app/Models/LoginToken.php` — `prunable()` `@return Builder<LoginToken>` widened to `Builder<static>` (template-covariance).

Status set to `in-progress`: backend (Group A) review is complete and clean; the frontend (Group B) review pass — `resources/js` auth screens, design tokens (AC7), and a11y gates (AC8) — is still outstanding.
