---
baseline_commit: 2bbd2f8
---

# Story 9.8: In-app brand mark & logo draw-in

Status: ready-for-dev

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

As a visitor landing on tripcast,
I want the sun-and-wave mark to greet me with a subtle draw-in,
so that the product feels crafted and finished from the first moment.

## Acceptance Criteria

**AC1 — Starter-kit branding fully replaced** *(FR-29, UX-DR16)*
- **Given** a new reusable `BrandMark.vue` (sun+wave SVG, brand amber/blue strokes, dark-mode aware)
- **When** the app renders
- **Then** no starter-kit branding remains: the Laravel SVG in `AppLogoIcon.vue` and the "Laravel Starter Kit" text in `AppLogo.vue` are gone, the auth layout shows the mark, the AppLayout header shows the mark beside the lowercase "tripcast" wordmark, and the landing hero h1 becomes a mark + wordmark lockup.

**AC2 — Draw-in animation, once per hard load** *(FR-29)*
- **Given** a hard page load
- **When** the mark first mounts
- **Then** it draws itself in with pure CSS stroke animation — wave left→right (~500ms), sun circle tracing (~400ms, overlapping), rays staggering in (~300ms), under 1s total, with space reserved so there is no layout shift — exactly once per load (module-scope flag): Inertia navigations never replay it.

**AC3 — Reduced motion** *(FR-29, UX-DR18)*
- **Given** `prefers-reduced-motion`
- **When** the mark renders
- **Then** it appears fully drawn, no animation.

## Tasks / Subtasks

- [ ] **Task 1 — `BrandMark.vue` component** (AC: 1)
  - [ ] New `resources/js/components/BrandMark.vue`. Geometry from `public/brand-assets/tripcast-favicon-32.svg` (`viewBox="0 0 64 64"`, `fill="none" stroke-linecap="round" stroke-linejoin="round"`, stroke-width 3.4): sun circle `cx=32 cy=32 r=11`, five ray strokes, wave `M8 49 q8 -5 16 0 t16 0 t16 0`.
  - [ ] Colors: wave stroke = `currentColor` with the component root classed `text-brand` (token `--color-brand`: `#2563a6` light / `#6ba7e0` dark — dark mode is free, do NOT hardcode blue); sun + rays stroke = literal `#E0993D` (no token exists; it reads fine on both washes — same call as the favicon).
  - [ ] Size via class from the parent (`size-*`), SVG `class="h-full w-full"` — fixed box, no layout shift. Accessibility: decorative by default (`aria-hidden="true"`), it always sits beside the visible "tripcast" wordmark.
  - [ ] Props: `{ animate?: boolean }` (default `false`) — only the landing hero + layout headers pass `animate`; other future uses render static.
- [ ] **Task 2 — Draw-in animation** (AC: 2, 3)
  - [ ] Pure CSS in the SFC `<style>` (scoped): wave + circle draw via `stroke-dasharray`/`stroke-dashoffset` keyframes — put `pathLength="1"` on the wave path and circle so dash math is `1 → 0` regardless of true length (the multi-subpath rays do NOT get dash-drawn — animate them with a staggered opacity/scale pop instead, which is what "rays stagger in" means). Timeline: wave 0–500ms ease-out; circle 150–550ms; rays 500–800ms staggered ~60ms apart via `animation-delay`. All under 1s.
  - [ ] Keyframes animate FROM hidden TO the natural (drawn) state, so the element's static/final state is fully drawn — this is what makes reduced-motion and the no-animate path free: `@media (prefers-reduced-motion: reduce) { animation: none }` on all animated parts satisfies AC3 with no JS branch.
  - [ ] **Once per hard load — SSR-safe (read carefully, this is the trap):** the app builds an SSR bundle (`npm run build:ssr`, Inertia SSR daemon). Module-scope state on the server persists ACROSS requests, so a naive `let hasPlayed = false` at module scope would flip once on the server and suppress the animation for every later visitor. Guard all flag reads/writes to the client (`typeof window !== 'undefined'` or `!import.meta.env.SSR`); during SSR always render the animate class (a hard load is exactly when it should play). Client-side, read+set the flag synchronously in component setup, and compute the class once (not reactively) so hydration matches the server HTML. Inertia page navigations that remount a layout re-run setup, find the flag set, and render static — that's the AC2 "never replays" path.
- [ ] **Task 3 — Swap the surfaces** (AC: 1)
  - [ ] `resources/js/layouts/auth/AuthSimpleLayout.vue`: replace the `AppLogoIcon` import + usage (lines 3, 28–31) with `BrandMark` (keep the `size-9` box and the `sr-only` title).
  - [ ] `resources/js/layouts/AppLayout.vue`: inside the existing home `<Link>` (lines 17–22), add the mark before the "tripcast" text — mark + wordmark on one baseline (`inline-flex items-center gap-2`, mark ~`size-5`). Wordmark text stays exactly `tripcast` (lowercase — locked voice).
  - [ ] `resources/js/pages/Landing.vue`: hero `<h1 class="text-display text-ink">tripcast</h1>` (line 128) becomes the lockup — mark (~`size-10 md:size-12`) beside the word, centered (`flex items-center justify-center gap-3`), `tripcast` text unchanged, still inside the `h1`. Pass `animate` here (and in the two layouts — the module flag arbitrates which one actually plays).
  - [ ] Delete `resources/js/components/AppLogo.vue` (unused — nothing imports it; recon-verified) and `resources/js/components/AppLogoIcon.vue` (its only consumer is the AuthSimpleLayout line replaced above). After deletion: `grep -ri "AppLogo" resources/js` must return nothing.
- [ ] **Task 4 — Rot-guard test + gates** (AC: 1)
  - [ ] New `tests/Feature/Landing/BrandMarkTest.php` (Pest): recursively scan `resources/js` and assert (a) no file contains `Laravel Starter Kit`, (b) `AppLogo.vue`/`AppLogoIcon.vue` no longer exist, (c) `resources/js/components/BrandMark.vue` exists. File-system assertions in the LandingMetaTest committed-assets style — the animation itself is browser-verified, not unit-tested.
  - [ ] **Gates:** `php artisan test --compact`; `npm run lint:check`; `npm run format:check` (run `npm run format` first); `npm run types:check`; `npm run build` (catches SFC compile errors; SSR bundle: `npm run build:ssr` must also pass because of the Task 2 SSR guard).
  - [ ] Browser verification (manual or via available browser tooling): hard load `/` → draw-in plays once; navigate away and back (Inertia) → no replay; hard reload → plays again; OS reduced-motion on → static; dark mode → wave is the light blue token. Ask the builder to run `npm run dev`/`composer run dev` if the change isn't visible.

## Dev Notes

### Current state (recon-verified)
- `AppLogo.vue` = Laravel mark + "Laravel Starter Kit" text, **imported by nothing** — delete, don't rework. `AppLogoIcon.vue` = the Laravel SVG path, imported ONLY by `AuthSimpleLayout.vue`.
- `AppLayout.vue` header link is text-only "tripcast"; Landing hero h1 is text-only. No mark exists anywhere in-app yet.
- Design tokens live in `resources/css/app.css`: `--color-brand` → `--brand-accent` (`#2563a6` light / `#6ba7e0` dark, plus the `prefers-color-scheme` fallback block). No amber/sun token — don't add one for a single consumer.
- Dark/light is class-driven (`.dark`/`.light` on `<html>`, set pre-paint in `app.blade.php`) with a `prefers-color-scheme` fallback — `text-brand` + `currentColor` rides all of it for free.

### Constraints
- **Scope wall:** favicon/manifest/head assets are Story 9.7 — this story touches NO blade files and NO `public/` assets. No new npm dependencies (no motion libraries — the whole point is a few CSS keyframes).
- Single root element per Vue SFC (project convention).
- Calm-visual voice: the draw-in is subtle polish, not a splash screen — no overlays, no delays to content, nothing blocks interaction while it plays.
- If 9.7 hasn't merged when this runs, that's fine — no shared files, no ordering dependency.

### Testing standards
- Pest feature test for the rot-guard (file-system assertions, `LandingMetaTest.php` shape). No JS unit-test framework exists (no vitest/jest — recon-verified); frontend correctness gates are eslint + prettier + vue-tsc + the vite builds, per `package.json` scripts. Suite baseline 358 passed as of 9.6.

### Project Structure Notes
- **New:** `resources/js/components/BrandMark.vue`, `tests/Feature/Landing/BrandMarkTest.php`.
- **Modified:** `resources/js/layouts/AppLayout.vue`, `resources/js/layouts/auth/AuthSimpleLayout.vue`, `resources/js/pages/Landing.vue`.
- **Deleted:** `resources/js/components/AppLogo.vue`, `resources/js/components/AppLogoIcon.vue`.

### Previous story intelligence (9.1–9.7)
- 9.7 (if already run) committed `public/brand-assets/` — the SVG geometry source referenced in Task 1 — and established `tests/Feature/Landing/BrandAssetsTest.php`; keep the two guard tests separate (assets vs. components).
- Sprint-wide gates discipline from 9.6: green on all gates before flipping to review; report exact counts.
- 9.3 touched `Landing.vue` form fields; hero markup (lines 120–133) has been stable — the h1 edit won't collide.

### References
- [Source: _bmad-output/planning-artifacts/epics.md#Story-9.8, #FR-29]
- [Source: public/brand-assets/tripcast-favicon-32.svg (mark geometry); og-image.html lockup (mark-beside-word reference rendering)]
- [Source: resources/js/layouts/AppLayout.vue:17–22; resources/js/layouts/auth/AuthSimpleLayout.vue:3,28–31; resources/js/pages/Landing.vue:128]
- [Source: resources/css/app.css:74,93–94,136–137 (brand tokens); package.json scripts (gates)]
- [memory: copy-voice-welcome-surfaces — lowercase "tripcast" as product noun]

## Dev Agent Record

### Agent Model Used

### Debug Log References

### Completion Notes List

### File List
