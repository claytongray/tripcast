---
baseline_commit: 26251b5
---

# Story 7.6: Promo analytics

Status: review

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

As the builder,
I want sponsored-link performance,
so that I can see whether the affiliate slot is earning engagement.

## Acceptance Criteria

**AC1 — `/admin/promos` shows impressions/clicks/CTR by slug and by weather profile, from `promo_events`** *(FR-25, AD-18)*
- **Given** an admin opens `/admin/promos`
- **When** it renders over a date range
- **Then** it shows **impressions**, **clicks**, and **CTR** **by `promo_slug`** and **by weather profile**, computed from `promo_events`; **read-only** (catalog editing is Epic 8).

**AC2 — Windowed, phone-first, guarded** *(FR-25, Epic-7 cross-cutting)*
- Date range is the 7/30/90 window (default 30; invalid → default). Phone-first (tables scroll, cards stack); read-only; behind the `admin` Gate (guest → login, non-admin → 403).

## Tasks / Subtasks

- [x] **Task 1 — `PromoAnalytics` builder** (AC: 1)
  - [x] Create `app/Services/Metrics/PromoAnalytics.php`. `build(MetricsWindow $window): array`.
  - [x] **One grouped query** over the window: `PromoEvent::query()->whereBetween('send_date', [$window->start->toDateString(), $window->end->toDateString()])->groupBy('promo_slug', 'event')->selectRaw('promo_slug, event, count(*) as aggregate')->get()`. (`selectRaw` string is a literal — keep it literal.)
  - [x] Fold the rows into per-slug `{ impressions, clicks }` (event = `PromoEvent::EVENT_IMPRESSION` / `EVENT_CLICK`).
  - [x] Build a **slug → weather-profile** map by inverting `config('tripcast.promo.catalog')` (top-level keys are profiles: `snow`, `hot`, `cold-wet`, `cold`, `mild`, `travel-essentials`; each item has a `slug`). A slug present in events but **not** in the catalog maps to `'unknown'`.
  - [x] Aggregate per profile by summing its slugs' impressions/clicks.
  - [x] Return:
    - `totals`: `{ impressions, clicks, ctr }` (ctr = `impressions > 0 ? round(clicks/impressions*100, 1) : 0.0`).
    - `by_slug`: `list<{ slug, impressions, clicks, ctr }>` sorted by impressions desc, then slug.
    - `by_profile`: `list<{ profile, impressions, clicks, ctr }>` sorted by impressions desc.
  - [x] Bounded/grouped (one DB query + in-memory folds); read-only.
- [x] **Task 2 — Wire `AdminController@promos`** (AC: 1, 2)
  - [x] Replace the placeholder. Resolve the window from `?days=` like `overview()`/`emails()` (allowlist `{7,30,90}`, default `DEFAULT_WINDOW`). Build via `PromoAnalytics`. `Inertia::render('Admin/Promos', [...$analytics, 'window' => …, 'windows' => …])`. Read-only; thin.
- [x] **Task 3 — Build `Admin/Promos.vue`** (AC: 1, 2)
  - [x] Replace the placeholder. Typed props. Header: title + the 7/30/90 window selector (same pattern as `Overview.vue`/`Emails.vue`).
  - [x] **Overall cards** (stack on phone): impressions, clicks, CTR (`{n}%`).
  - [x] **By slug** table (semantic `<table>` in `overflow-x-auto`): slug, impressions, clicks, CTR. Empty state.
  - [x] **By weather profile** table: profile, impressions, clicks, CTR. Empty state.
  - [x] Single root; reuse `Admin/*` tokens; strictly read-only.
- [x] **Task 4 — TS contracts** (AC: 1)
  - [x] Payload types (inline in `Promos.vue` or `resources/js/types/promos.ts`): `PromoRow = { slug: string; impressions: number; clicks: number; ctr: number }`, `ProfileRow = { profile: string; impressions: number; clicks: number; ctr: number }`, `PromoAnalyticsPayload = { window, windows, totals: { impressions, clicks, ctr }, by_slug: PromoRow[], by_profile: ProfileRow[] }`.
- [x] **Task 5 — Tests** (AC: 1, 2)
  - [x] Create `tests/Feature/Admin/PromoAnalyticsTest.php` (Pest, `RefreshDatabase`, `travelTo`). Seed `promo_events` in the window across slugs in different profiles (e.g. `packable-sun-hat`→hot, `merino-base-layer`→cold, `universal-adapter`→travel-essentials) plus an **unknown** slug, with a mix of `impression`/`click`, and some rows **outside** the window. Assert as admin:
    - `component('Admin/Promos')`; `totals.{impressions,clicks,ctr}`; `by_slug` rows carry the right per-slug impressions/clicks/CTR and are sorted by impressions desc; `by_profile` groups correctly (hot/cold/travel-essentials/**unknown**) with summed counts; out-of-window rows excluded.
    - **Window param:** default 30; `?days=7` → `window` 7; invalid → 30.
    - **Authz:** guest → login; non-admin → 403.
  - [x] **Gates (all green):** `php artisan test --compact`, `vendor/bin/pint --dirty --format agent`, `./vendor/bin/phpstan analyse`, `npm run types:check`, `npm run lint:check`, `npm run build:ssr`.

## Dev Notes

### Scope boundary (read first)
- **Promos section only.** No catalog editing (that's Epic 8) — strictly read analytics from `promo_events`. Do not touch other sections. **Read-only**, **no migrations**. Reuse `MetricsService::resolveWindow` (7.2) + the window-selector pattern (7.3).

### Architecture (binding)
- **FR-25 / AD-18:** CTR from `promo_events` (`impression`/`click`), by slug and by weather profile; read-only. [Source: epics.md#Story-7.6]
- **Weather profile is NOT stored on `promo_events`** — it's derived from the catalog: `config('tripcast.promo.catalog')` is keyed by profile, each item has a `slug`. Invert that to map slug→profile; unmapped slugs → `'unknown'`. This mirrors how `AffiliatePromoProvider::findBySlug()` scans the same catalog. [Source: app/Services/Promo/AffiliatePromoProvider.php:56-72; config/tripcast.php:159]

### Data model (exact)
- **`promo_events`** — `send_date` (date), `event` (`PromoEvent::EVENT_IMPRESSION|EVENT_CLICK`), `promo_slug`, `user_id`, `trip_id`. Unique `[trip_id, send_date, promo_slug, event]`. No factory — seed via `DB::table('promo_events')->insert([...])` with `created_at`/`updated_at`. [Source: app/Models/PromoEvent.php; migration]
- **Catalog profiles** (current placeholder): `snow`, `hot`, `cold-wet`, `cold`, `mild`, `travel-essentials`; sample slugs: `packable-sun-hat`/`mineral-sunscreen` (hot), `merino-base-layer` (cold), `packing-cubes` (mild), `universal-adapter`/`travel-power-bank` (travel-essentials), etc. [Source: config/tripcast.php:159-181]
- **App tz UTC**; the window bounds `send_date` (a date column). [Source: config/app.php]

### Code intel (patterns to match)
- **Section builder** mirrors `OverviewMetrics`/`EmailHealthMetrics` (return an array payload). But CTR-by-slug/profile is a custom fold, not a `MetricsService` daily-series call — one grouped query, fold in PHP. Only `resolveWindow` is reused from `MetricsService`. [Source: app/Services/Metrics/OverviewMetrics.php; EmailHealthMetrics.php]
- **Controller/window** copies `AdminController@overview`/`@emails` (`DEFAULT_WINDOW`, allowlist fallback). [Source: app/Http/Controllers/AdminController.php]
- **Frontend:** reuse the window selector + card/table styling from `Overview.vue`/`Users.vue`; `Promos` route helper `import { promos } from '@/routes/admin'`. Activate `inertia-vue-development`. [Source: resources/js/pages/Admin/Overview.vue; Users.vue]
- **Catalog config type:** `config('tripcast.promo.catalog')` is `array<string, list<array<string,string>>>` (profile → items). Guard for missing/empty. [Source: app/Services/Promo/AffiliatePromoProvider.php:30]

### Testing standards
- Pest + `RefreshDatabase` + `travelTo`. Seed `promo_events` via `DB::table('promo_events')->insert([...])` (needs a `trip_id`+`user_id`; make a `Trip::factory()->for(User::factory())`), respecting the unique key (vary `promo_slug`/`event`/`send_date`). Compute expected CTRs by hand for the fixture. Whole-number CTRs (e.g. `50.0`) JSON-encode to int — assert those with a numeric closure (`fn ($v) => (float) $v === 50.0`). Assert `->component('Admin/Promos')->where('totals.clicks', n)->where('by_profile', …)`. Authz mirrors `AdminShellTest`. [Source: tests/Feature/Admin/OverviewTest.php; EmailHealthTest.php]

### Project Structure Notes
- **New:** `app/Services/Metrics/PromoAnalytics.php`, `tests/Feature/Admin/PromoAnalyticsTest.php` (+ optional `resources/js/types/promos.ts`).
- **Modified:** `app/Http/Controllers/AdminController.php` (`promos()` body), `resources/js/pages/Admin/Promos.vue` (placeholder → real).
- **Unchanged:** routes (`admin.promos` from 7.1), the promo catalog/provider, other sections, migrations. **No migrations.**

### Previous story intelligence (7.1–7.5)
- 7.2 gave `MetricsService::resolveWindow`; 7.3 the window selector; 7.5 the section-builder + controller-window pattern — all reused. This section adds a catalog-inversion fold (new), but reads the same `config('tripcast.promo.catalog')` the provider uses, so slug→profile stays consistent. Read-only. Regenerate Wayfinder on build. 7.1–7.5 may be uncommitted; 7.6 adds its own files + the `promos()` method. [Source: _bmad-output/implementation-artifacts/7-2-*.md … 7-5-*.md]

### References
- [Source: _bmad-output/planning-artifacts/epics.md#Story-7.6] (AC)
- [Source: app/Services/Promo/AffiliatePromoProvider.php; config/tripcast.php:159] (catalog → profile)
- [Source: app/Models/PromoEvent.php] (events)
- [Source: app/Services/Metrics/MetricsService.php] (resolveWindow)

## Dev Agent Record

### Agent Model Used

claude-opus-4-8 (1M context)

### Debug Log References

- **PHPStan dynamic alias:** `$row->aggregate` (from `selectRaw('… count(*) as aggregate')`) read via `getAttribute('aggregate')` — same pattern as 7.4.

### Completion Notes List

- **`PromoAnalytics` (AC1):** one grouped query over the window (`group by promo_slug, event`), then folds in PHP into per-slug and per-profile impressions/clicks/CTR + overall totals. Weather profile is derived by inverting `config('tripcast.promo.catalog')` (the same catalog the provider selects from), so slug→profile stays consistent; slugs not in the catalog bucket to `'unknown'`. `by_slug`/`by_profile` sorted by impressions desc (alphabetical tiebreak). Read-only, no DB writes.
- **Controller:** `promos()` mirrors the `overview()`/`emails()` 7/30/90 window handling (invalid → 30).
- **Page:** window selector, overall impressions/clicks/CTR cards, and two phone-scrollable tables (by product slug, by weather profile) with empty states. Read-only.
- **Scope held:** analytics only — no catalog editing (Epic 8), no migrations, no new deps, other sections untouched.
- **Verification:** full suite **356 passed / 1413 assertions** (3 new PromoAnalyticsTest — by-slug/by-profile grouping incl. the `unknown` bucket, out-of-window exclusion, window fallback, Gate guards). pint clean, phpstan 0 errors, types:check + lint:check clean, build:ssr built (Promos in client + SSR bundles).

### File List

**New:**
- `app/Services/Metrics/PromoAnalytics.php`
- `tests/Feature/Admin/PromoAnalyticsTest.php`

**Modified:**
- `app/Http/Controllers/AdminController.php` (`promos()` body + import)
- `resources/js/pages/Admin/Promos.vue` (placeholder → real)
- regenerated Wayfinder helpers (gitignored)

**Unchanged:** routes (`admin.promos` from 7.1), promo catalog/provider, other sections, migrations. No migrations.

### Change Log

- 2026-07-01 — Implemented Story 7.6: Promo analytics. `/admin/promos` shows impressions, clicks, and CTR overall, by `promo_slug`, and by weather profile (derived by inverting the promo catalog; unmapped slugs → `unknown`), computed from `promo_events` (AD-18) over a 7/30/90 window. Read-only — catalog editing stays in Epic 8. All gates green (356 tests).
