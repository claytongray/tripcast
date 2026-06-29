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

- [ ] **Task 1 — Scaffold the project** (AC: 1)
  - [ ] `git init`; create the app from the Laravel **Vue starter kit** (Inertia 3 + Vue 3 + Tailwind 4 + shadcn-vue)
  - [ ] Configure Inertia **SSR** via `@inertiajs/vite`; confirm `npm run build` produces the SSR bundle and dev server runs
  - [ ] Configure **MySQL 8** connection; set a **case-insensitive collation for `users.email`** (e.g. `utf8mb4_0900_ai_ci`) — AD-3/AD-10 depend on it
  - [ ] Configure **Redis** for `queue` and `cache`
  - [ ] Commit the working scaffold
- [ ] **Task 2 — Remove Fortify, keep types building** (AC: 2)
  - [ ] Remove Fortify and password-auth wiring; delete password/reset routes, controllers, views, and the password column path
  - [ ] Re-generate / fix **Wayfinder** typed routes so the build is clean with the dropped routes gone
  - [ ] Verify no password input or reset flow remains anywhere
- [ ] **Task 3 — `users` migration + model** (AC: 3)
  - [ ] Migration: `email` (unique, CI collation), `plan` default `free`, `timezone` default `America/New_York`, `is_admin` default false, `email_opted_out` default false; no password columns
  - [ ] `User` model (singular, snake_case table) with appropriate casts/fillable
- [ ] **Task 4 — Magic-link auth** (AC: 4, 5, 6)
  - [ ] Migration + `LoginToken` model: `user_id`, `token_hash`, `expires_at`, `consumed_at`
  - [ ] `RequestMagicLink` action in `app/Actions/`: create-or-match user by CI email, generate token, **invalidate prior unconsumed tokens for that user**, store hash, email the signed link; **throttle per email**
  - [ ] Auth controller (`app/Http/Controllers/Auth/`) + routes: request-link, consume-link (GET), logout
  - [ ] Consume: validate unexpired + unconsumed by hash → set `consumed_at`, log the user in with a **long-lived cookie session**, redirect to dashboard
  - [ ] Expired/consumed → calm result page with one-tap resend; check-your-email interstitial with resend
  - [ ] Schedule pruning of expired/consumed `login_tokens` (per conventions)
- [ ] **Task 5 — UI foundation** (AC: 7, 8)
  - [ ] Tailwind 4 theme = the DESIGN.md tokens (color light+dark pairs, type scale, spacing, radius); `color-scheme` meta + `prefers-color-scheme: dark` mapping
  - [ ] Button + Input primitives per DESIGN.md (shadcn-vue base, extended to tokens)
  - [ ] Build the auth screens (request-link form, check-email interstitial, expired/used result) on the primitives; meet the a11y + responsive gates
  - [ ] Minimal authenticated **placeholder dashboard** as the post-login landing (full dashboard is Epic 3)
- [ ] **Task 6 — Tests**
  - [ ] Feature tests: request link issues a single-use token + invalidates prior; valid link logs in + consumes; expired/consumed rejected with resend; throttle enforced; no password routes resolve
  - [ ] Migration test: `users.email` collation is case-insensitive (e.g. `Foo@x.com` matches `foo@x.com`)

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

### Debug Log References

### Completion Notes List

### File List
