# Version-Currency Review — Tripcast v1 Architecture Spine

- **Reviewed:** `ARCHITECTURE-SPINE.md` (Stack table + version claims)
- **Date:** 2026-06-28
- **Method:** Every committed technology choice verified against the live web (vendor docs, Packagist/GitHub composer.json, official release notes). Nothing below is asserted from training data alone.

## Verdict

**PASS with one fit caveat.** Every version in the Stack table is current and accurate as of 2026-06-28, and every named product/package exists as described. The one substantive issue is not a version error but a mischaracterization of effort: the official Vue starter kit ships **Laravel Fortify password auth**, not magic-link — so "reduce its password auth to magic-link only" understates a rip-and-replace, and the only first-party magic-link option (WorkOS "Magic Auth") conflicts with AD-6's self-hosted, password-free design.

## Findings

| # | Severity | Finding | Fix |
| --- | --- | --- | --- |
| 1 | **High** | Starter kit auth is Fortify (password + 2FA + email verification), not magic-link. Spine says "reduce its password auth to magic-link only" — but Fortify has no magic-link feature; AD-6's single-use `login_tokens` flow is fully custom. The only first-party magic-link (WorkOS AuthKit "Magic Auth") is hosted and password-capable, contradicting AD-6 ("no passwords anywhere"). | Reword the Starter note: plan to **remove Fortify and build custom magic-link** (per AD-6). Also note Wayfinder type-safe routing: removing auth feature routes without removing their frontend references **breaks the build**. |
| 2 | **Low** | Tailwind pinned as "starter default (4.x)". The Vue starter-kit doc says only "Tailwind"; the React kit doc explicitly says "Tailwind 4". 4.x is correct but inferred for the Vue kit. | Confirm Tailwind 4.x in the generated `package.json` at scaffold time and pin it explicitly in the lockfile. |
| 3 | **Low** | Version pins are major-only (`Laravel 13.x`, `Inertia 3.x`, `Vue 3.x`, `Tailwind 4.x`). Fine for a seed, but the spine itself says "the code owns this once it exists." | At first `composer install` / `npm install`, capture exact resolved versions in `composer.lock` / `package-lock.json` as the real source of truth. |
| 4 | **Info** | MailerSend driver: a stale third-party snapshot claimed Laravel 13 is unsupported. **Verified false** — the package's `composer.json` requires `illuminate/support: ^10.0 \|\| ^11.0 \|\| ^12.0 \|\| ^13.0`. Laravel 13 is explicitly supported. | No action. Use latest stable (`mailersend/laravel-driver` v3.x, released Feb 2026). |

## Per-item verification

### PHP 8.3+ / Laravel 13.x — CONFIRMED current
- Laravel 13 released **2026-03-17** (Laracon EU 2026); it is the current major. Minimum PHP is **8.3**, matching the spine.
- Laravel 12 drops to security-only (bug fixes end 2026-08-13). Choosing 13 is correct and forward-looking.
- Source: laravel.com/docs/13.x/releases, endoflife.date/laravel, impacttechlab.com/laravel-13-release.

### Inertia.js 3.x (SSR via @inertiajs/vite, Node 22+) — CONFIRMED current & accurate
- Inertia **v3.0.0 is stable** (post-beta). Major changes: Axios removed for a built-in XHR client, ESM-only output, and the **new `@inertiajs/vite` plugin** that handles page resolution + SSR config automatically — exactly as the spine states.
- SSR now runs in Vite dev mode without a separate Node process; a separate Node SSR server is only needed for **production** (`build:ssr` / `composer dev:ssr`).
- **SSR server requires Node.js 22+** — matches the spine's "Node (SSR runtime) 22+".
- Source: inertiajs.com/docs/v3 upgrade guide, laravel-news.com/inertia-3-0-0, laravel.com/docs/13.x/starter-kits#inertia-ssr.

### Vue 3 (Composition API) + Tailwind 4.x — CONFIRMED current & compatible
- Vue 3 Composition API is current and is what the official Vue starter kit uses.
- Tailwind CSS v4 (Oxide/Rust engine, first-party `@tailwindcss/vite`) is fully compatible with Vue 3 + Vite. Browser target: Safari 16.4 / Chrome 111 / Firefox 128 — acceptable for a consumer web app.
- shadcn-vue supports Tailwind v4. Source: tailwindcss.com/blog/tailwindcss-v4, shadcn-vue.com.

### mailersend/laravel-driver — CONFIRMED exists & supports Laravel 13
- Official package (`mailersend/mailersend-laravel-driver`), latest **v3.0.0 (2026-02-05)**. `composer.json` requires `illuminate/support: ^10.0 || ^11.0 || ^12.0 || ^13.0` and `php: >=8.2` — compatible with the spine's PHP 8.3+ and Laravel 13.
- It registers a custom mail transport, so AD-1's "mail via Laravel's Mailer" abstraction holds: app code uses the standard `Mailer`, the driver is config-only.
- Source: github.com/mailersend/mailersend-laravel-driver (composer.json), packagist.org/packages/mailersend/laravel-driver.

### Official Vue starter kit (Inertia 3 + Vue 3 + Tailwind + shadcn-vue) — EXISTS exactly as described, ONE auth caveat
- `laravel/vue-starter-kit` is real and documented at laravel.com/docs/13.x/starter-kits: **"built with Inertia 3, Vue 3 Composition API, Tailwind, and shadcn-vue"**, TypeScript, Vite/SSR pre-configured, Pages structure under `resources/js/pages` — matches the spine.
- **Caveat (Finding #1):** default auth is **Laravel Fortify** (login/register/password reset/email verification/2FA) — password-based. There is **no magic-link feature** in the default kit. The first-party WorkOS AuthKit variant offers email "Magic Auth," but it is hosted and still password-capable, which contradicts AD-6 (single-use `login_tokens`, no passwords). Net: the spine's "reduce password auth to magic-link only" is really "remove Fortify, build custom magic-link." Also note the kit uses **Wayfinder** type-safe routing — dropping auth routes without cleaning their frontend references fails the build.

### Redis queue — GOOD FIT
- Standard Laravel queue + cache driver; correct for AD-2's dispatch seam and single-Forge-box topology. No concern.

### WeatherAPI.com Starter plan — GOOD FIT, generous headroom
- Starter is **$7/mo (~$75/yr)**, **3M calls/month**, **7-day daily + hourly forecast**, alerts, history back 7 days, commercial use allowed. The spine needs one 7-day forecast fetch per active trip per morning — Starter's 7-day window and call volume are far more than a personal beta requires. Source: weatherapi.com/pricing.aspx.

### Google Maps Geocoding API — ACTIVE, pay-per-use, fine for AD-8's geocode-once
- Active and maintained as of June 2026. Pay-as-you-go: **~10,000 free events/month**, then ~**$5 per 1,000** (Essentials SKU). Note Google replaced the old $200 monthly credit with per-SKU free tiers on **2025-03-01** — budget against the new model, not the legacy credit.
- AD-8's "geocode once at trip creation" keeps volume minimal; cost risk is negligible at beta scale. Source: developers.google.com/maps/documentation/geocoding/usage-and-billing.

## Bottom line
No out-of-date, non-existent, or wrongly-versioned technology in the Stack table. Tighten the **Starter-kit auth wording** (Finding #1, High), confirm and explicitly pin **Tailwind 4.x** (Finding #2), and capture exact lockfile versions once scaffolded (Finding #3).
