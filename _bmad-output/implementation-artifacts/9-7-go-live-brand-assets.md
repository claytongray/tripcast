---
baseline_commit: 2bbd2f8
---

# Story 9.7: Go-live brand assets

Status: review

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

As a traveler bookmarking, tabbing, or saving tripcast to a home screen,
I want the site to carry the tripcast identity everywhere the browser shows it,
so that it looks like a real product, not a framework template.

## Acceptance Criteria

**AC1 — Brand asset set served from the public root** *(FR-28)*
- **Given** the public root
- **When** the app is served
- **Then** every Laravel starter asset is replaced by the brand set from `public/brand-assets/`: a multi-size `favicon.ico` (16/32/48, generated from the provided PNGs), a scalable `/favicon.svg` (sun+wave, with an embedded `prefers-color-scheme: dark` stroke tweak so it stays legible on dark tab bars), a 180×180 `apple-touch-icon.png`, `icon-192.png`/`icon-512.png`, `safari-pinned-tab.svg`, and `site.webmanifest`.

**AC2 — Head wiring** *(FR-28)*
- **Given** `app.blade.php`
- **When** any page renders
- **Then** the head links the SVG + ICO icons, apple-touch icon, manifest, and `mask-icon` (safari-pinned-tab), plus a `theme-color` meta pair — `#F6F9FC` light / `#0E1822` dark — matching the existing inline html backgrounds. og:image and the Story 9.2 link-preview tags are untouched.

**AC3 — Manifest correctness** *(FR-28)*
- **Given** the served `site.webmanifest`
- **When** it is fetched
- **Then** it is valid JSON, names the app lowercase "tripcast", and declares the 192/512 icons with purpose `"any"` — the mark isn't padded for the maskable safe zone; properly padded maskable variants are recorded in `deferred-work.md` as a missing designer asset.

**AC4 — Guard test** *(FR-28)*
- **And** a Pest feature test (following the Story 9.2 link-preview test pattern) asserts the head tags are present and the manifest is valid JSON with the expected icon paths.

## Tasks / Subtasks

- [x] **Task 1 — Generate `favicon.ico`** (AC: 1)
  - [x] Write a throwaway script in the session scratchpad (NOT the repo) that packs the three provided PNGs into a single multi-size ICO, then run it to produce `public/favicon.ico` (overwriting the Laravel default). Two equally fine routes:
    - Pure-Python struct packing (no deps): ICONDIR header + one ICONDIRENTRY per PNG + raw PNG blobs — PNG-compressed ICO entries are valid for all modern browsers and Windows Vista+. Sources: `public/brand-assets/favicon-16.png` (16×16), `favicon-32.png` (32×32), `favicon-48.png` (48×48) — dimensions verified.
    - Pillow 11.3.0 (verified installed): open `favicon-48.png`, `img.save('public/favicon.ico', sizes=[(16,16),(32,32),(48,48)])` — acceptable; it re-scales from one source, so prefer the struct route which preserves the designer's per-size hinted art (16px has thicker strokes by design).
  - [x] Verify: `file public/favicon.ico` reports an ICO with 3 icons; do NOT commit the generator script.
- [x] **Task 2 — Place the served assets at the public root** (AC: 1)
  - [x] `cp public/brand-assets/tripcast-favicon-32.svg public/favicon.svg` (overwrites the Laravel logo SVG), then edit it: add class hooks to the two stroke groups and an embedded `<style>` with `@media (prefers-color-scheme: dark)` that lightens the wave stroke `#2563A6` → `#7FB0DE` (the deep blue goes muddy on dark tab strips; amber `#E0993D` already reads fine on dark — leave it).
  - [x] `cp public/brand-assets/apple-touch-icon.png public/apple-touch-icon.png` (180×180 replaces the 166×166 Laravel one).
  - [x] `cp` `icon-192.png`, `icon-512.png`, `safari-pinned-tab.svg`, `site.webmanifest` from `public/brand-assets/` to `public/`.
  - [x] Edit `public/site.webmanifest`: change both icons' `"purpose": "any maskable"` → `"purpose": "any"` (the mark touches the edges; Android would crop the sun rays inside the maskable safe zone). Everything else in the provided manifest is already correct (lowercase names, theme `#2563A6`, background `#F6F9FC`).
  - [x] `public/brand-assets/` stays as the committed source folder (SVG variants + `og-image.html` source live there). This story's commit is the first to track it — include it.
- [x] **Task 3 — Head wiring in `app.blade.php`** (AC: 2)
  - [x] Keep the three existing icon links (lines 53–55; `favicon.ico` gets `sizes="48x48 32x32 16x16"` now that it's multi-size — or leave `sizes="any"`, either is valid; SVG link stays as-is so supporting browsers prefer it).
  - [x] Add: `<link rel="manifest" href="/site.webmanifest">`, `<link rel="mask-icon" href="/safari-pinned-tab.svg" color="#2563A6">`, and the theme-color pair:
    `<meta name="theme-color" media="(prefers-color-scheme: light)" content="#F6F9FC">` / `<meta name="theme-color" media="(prefers-color-scheme: dark)" content="#0E1822">` — these two hexes MUST match the existing inline `html` background styles at lines 37–51; they already do, don't invent new values.
  - [x] Do NOT touch the FR-24 meta block (lines 8–22): og:image stays `/og-image.png` at 2400×1260 — the user explicitly chose to keep the product-shot preview over the brand-lockup version in `brand-assets/og-image.png`.
- [x] **Task 4 — Record the missing designer asset** (AC: 3)
  - [x] Append to `_bmad-output/implementation-artifacts/deferred-work.md` under a `## Deferred from: Story 9.7 (2026-07-02)` heading: maskable icon variants (mark at ~60% scale on a solid `#F6F9FC` or `#2563A6` field, 192/512) are the one missing brand asset; when provided, add them to the manifest as separate `"purpose": "maskable"` entries. Note `brand-assets/twitter-card.png` is byte-identical to `brand-assets/og-image.png` (both unused by choice).
- [x] **Task 5 — Guard test** (AC: 4)
  - [x] New `tests/Feature/Landing/BrandAssetsTest.php` (Pest), following `LandingMetaTest.php`'s two-block pattern:
    - `get('/')` asserts head markup with `assertSee(..., false)`: `rel="manifest"`, `href="/site.webmanifest"`, `rel="mask-icon"`, `rel="apple-touch-icon"`, `href="/favicon.svg"`, `href="/favicon.ico"`, and both `theme-color` metas (match on `content="#F6F9FC"` and `content="#0E1822"`).
    - Committed-file assertions: `favicon.ico`, `favicon.svg`, `apple-touch-icon.png`, `icon-192.png`, `icon-512.png`, `safari-pinned-tab.svg`, `site.webmanifest` all exist under `public_path()`; `site.webmanifest` parses with `json_decode(..., flags: JSON_THROW_ON_ERROR)`, `name === 'tripcast'`, icons array has exactly the `/icon-192.png` + `/icon-512.png` entries, and NO icon carries a `maskable` purpose (guards the AC3 edit against a re-export of the raw asset folder).
  - [x] **Gates:** `php artisan test --compact --filter=BrandAssets` then full `php artisan test --compact`; `vendor/bin/pint --dirty --format agent`; `./vendor/bin/phpstan analyse`.

## Dev Notes

### Current state (recon-verified — read before touching)
- `public/favicon.ico` (32×32), `public/favicon.svg` (red Laravel logo, `#FF2D20`), `public/apple-touch-icon.png` (166×166) are all Laravel starter defaults from commit 8b64e4b. No manifest, mask-icon, or theme-color exists anywhere.
- `resources/views/app.blade.php` is the ONLY file referencing favicons (lines 53–55) — no Vue/TS/email references, so the head edit is the entire wiring surface.
- `public/brand-assets/` (untracked, added by the builder 2026-07-01) inventory: `apple-touch-icon.png` 180×180 ✓, `favicon-16/32/48.png` (correct dims ✓), `icon-192.png`/`icon-512.png`, `safari-pinned-tab.svg` (black-stroke template — correct for mask-icon, color comes from the `color` attr), `site.webmanifest`, per-size favicon SVGs (`tripcast-favicon-16.svg` has deliberately heavier strokes; `-32` is the general-purpose geometry), `og-image.html` (source), `og-image.png` + `twitter-card.png` (1200×630, byte-identical, both deliberately unused — see Task 3).
- `public/og-image.png` (2400×1260) is the Story 9.2 product-shot preview, committed and wired — **decision on record: it stays**.

### Constraints
- **Scope wall:** Vue components (`AppLogo.vue`, `AppLogoIcon.vue`, layouts, `Landing.vue`) are Story 9.8 — do not touch them here. No new npm/composer dependencies.
- SVG-favicon dark-mode media queries work in Chrome/Firefox; Safari ignores SVG favicons entirely and uses the ICO/touch icon — that's fine, don't chase it.
- `theme_color` in the manifest (`#2563A6`, brand blue for the installed-app chrome) intentionally differs from the page `theme-color` metas (`#F6F9FC`/`#0E1822`, the html wash) — both are correct as specified; don't "reconcile" them.
- Voice: lowercase "tripcast" everywhere (manifest already complies). [memory: copy-voice-welcome-surfaces]

### Testing standards
- Pest, feature test, no browser needed: `get('/')` + `assertSee(..., false)` for markup, `public_path()` file assertions for committed assets — exactly the shape of `tests/Feature/Landing/LandingMetaTest.php` (Story 9.2). Suite baseline: 358 passed as of 9.6.

### Project Structure Notes
- **New:** `tests/Feature/Landing/BrandAssetsTest.php`; `public/site.webmanifest`, `public/icon-192.png`, `public/icon-512.png`, `public/safari-pinned-tab.svg`; `public/brand-assets/` (first commit).
- **Modified:** `resources/views/app.blade.php` (head only), `public/favicon.ico`, `public/favicon.svg`, `public/apple-touch-icon.png` (all three overwritten with brand versions), `_bmad-output/implementation-artifacts/deferred-work.md`.

### Previous story intelligence (9.1–9.6)
- 9.2 established the head-tags + committed-assets test pattern this story copies (`LandingMetaTest.php`).
- 9.6's guard-test lesson applies: anchor assertions so they can't be satisfied accidentally (hence the explicit no-`maskable` assertion, not just "manifest parses").
- Suite gates used all sprint: `php artisan test --compact`, `vendor/bin/pint --dirty --format agent`, `./vendor/bin/phpstan analyse` — all three must be green before review.

### References
- [Source: _bmad-output/planning-artifacts/epics.md#Story-9.7, #FR-28]
- [Source: resources/views/app.blade.php:8–22 (FR-24 meta block — untouchable), :37–51 (background hexes), :53–55 (icon links)]
- [Source: tests/Feature/Landing/LandingMetaTest.php (test pattern)]
- [Source: public/brand-assets/* (asset inventory, dims verified 2026-07-02)]

## Dev Agent Record

### Agent Model Used

### Debug Log References

### Completion Notes List

### File List

### Agent Model Used (recorded)

claude-fable-5

### Completion Notes (recorded)

- **Red→green:** `BrandAssetsTest` failed on all three blocks against the Laravel starter assets, passed after Tasks 1–3. Full suite **361 passed** (1188 assertions; baseline 358); pint clean; phpstan 0 errors.
- **favicon.ico** generated by struct-packing the designer's three per-size PNGs (16/32/48, PNG-compressed ICO entries — preserves the 16px stroke hinting; `file` verifies 3 icons). Generator script lived in the session scratchpad, not committed.
- **favicon.svg** rebuilt from `tripcast-favicon-32.svg` with class hooks + embedded dark-mode media query (wave `#2563A6` → `#7FB0DE`; amber untouched).
- **site.webmanifest**: icon purpose `any maskable` → `any`; maskable variants recorded in deferred-work.md with a note on relaxing the guard test when they land.
- **Head wiring**: manifest + mask-icon + theme-color pair added; `favicon.ico` link upgraded to `sizes="48x48 32x32 16x16"`; FR-24 og/twitter block untouched (og:image stays the 2400×1260 product shot — user decision on record).
- Scope wall held: no Vue files touched (Story 9.8 owns those); no new dependencies.

### File List (recorded)

**New:**
- `tests/Feature/Landing/BrandAssetsTest.php`
- `public/site.webmanifest`, `public/icon-192.png`, `public/icon-512.png`, `public/safari-pinned-tab.svg`
- `public/brand-assets/` (17 source files, first commit)

**Modified:**
- `resources/views/app.blade.php` (head icon/manifest/theme-color wiring only)
- `public/favicon.ico`, `public/favicon.svg`, `public/apple-touch-icon.png` (Laravel defaults → brand versions)
- `_bmad-output/implementation-artifacts/deferred-work.md` (maskable-variant entry)

### Change Log

- 2026-07-02 — Story 9.7: brand asset set served from the public root (multi-size ICO, dark-mode-aware SVG favicon, 180px touch icon, manifest icons + webmanifest with maskable claim corrected, safari pinned tab), head wired with manifest/mask-icon/theme-color pair, guard test added. Full suite 361 passed; pint + phpstan clean.
