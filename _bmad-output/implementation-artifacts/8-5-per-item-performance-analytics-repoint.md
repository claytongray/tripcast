---
baseline_commit: 6c0b5bb
---

# Story 8.5: Per-item performance & analytics repoint

Status: done

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

As the builder,
I want each catalog item's impressions/clicks/CTR in the catalog UI,
so that I can see which sponsored products earn engagement and retire the ones that don't.

## Acceptance Criteria

**AC1 — Repoint `slugToProfileMap()` from config to `PromoItem`** *(FR-26, AD-18, FR-25)*
- **Given** the Story 7.6 `PromoAnalytics` currently inverts `config('tripcast.promo.catalog')` for slug→profile
- **When** the catalog is DB-backed
- **Then** `slugToProfileMap()` is repointed at `PromoItem` (`withTrashed()`, keyed `slug => weather_profile`) so admin-added and edited items bucket correctly, and historical events take the item's **current** profile; `config('tripcast.promo.catalog')` remains only a **fallback** for slugs not present in `promo_items` (so config-seeded slugs still resolve during the bake period rather than falling to `unknown`) — its full retirement stays gated behind this repoint plus a bake period.

**AC2 — Per-item performance in the catalog list** *(FR-25, AD-18, AD-12)*
- **Given** the catalog list (`Admin/Catalog/Index`)
- **When** it renders over a 7/30/90 window (default 30; invalid → 30)
- **Then** each item row surfaces its **impressions, clicks, and CTR** for that window (reusing the Story 7.6 `promo_events` fold, joined `promo_events.promo_slug → promo_items.slug`), items with no events read `0 / 0 / 0.0%`, a `WindowSwitcher` drives the window, and it stays read-only, phone-first, behind the single `admin` Gate.

## Tasks / Subtasks

- [x] **Task 1 — Repoint `slugToProfileMap()` (DB-first, config fallback)** (AC: 1)
  - [x] In `app/Services/Metrics/PromoAnalytics.php`, change `slugToProfileMap()` to build from `PromoItem::withTrashed()->pluck('weather_profile', 'slug')->all()` first, then merge the existing `config('tripcast.promo.catalog')` inversion as a **fallback only** for slugs absent from the DB map (DB wins; config never overrides a DB value). Keep the `UNKNOWN_PROFILE` default for slugs in neither.
  - [x] Update the class docblock: profile now comes from `PromoItem` (`withTrashed`), config is the bake-period fallback.
- [x] **Task 2 — Expose a reusable per-slug fold on `PromoAnalytics`** (AC: 2)
  - [x] Refactor `build()` so the single grouped `promo_events` query folds once into a per-slug `[slug => ['impressions','clicks']]` map, and `by_profile` is rolled up from that per-slug map via `slugToProfileMap()` (profile is a per-slug property — removes the duplicate fold). **`by_slug`/`by_profile`/`totals` output must stay byte-identical** (the 7.6 `PromoAnalyticsTest` stays green).
  - [x] Add `public function perSlug(MetricsWindow $window): array` returning `[slug => ['impressions'=>int,'clicks'=>int,'ctr'=>float]]` (reusing the same fold + `ctr()`), for the catalog controller.
- [x] **Task 3 — Catalog index shows per-item stats over a window** (AC: 2)
  - [x] In `app/Http/Controllers/PromoItemController.php`, `index(Request $request, MetricsService $metrics, PromoAnalytics $analytics)`: resolve the window with the AdminController pattern — `$days = (int) $request->query('days', (string) self::DEFAULT_WINDOW); if (! in_array($days, MetricsService::ALLOWED_WINDOWS, true)) { $days = self::DEFAULT_WINDOW; } $window = $metrics->resolveWindow($days);` (add a `private const DEFAULT_WINDOW = 30;`).
  - [x] `$stats = $analytics->perSlug($window);` then attach to each projected item: `impressions`/`clicks`/`ctr` from `$stats[$item->slug] ?? [0,0,0.0]`.
  - [x] Pass `'window' => $window->days` and `'windows' => MetricsService::ALLOWED_WINDOWS` alongside `items`/`profiles`/`merchants`.
  - [x] Keep the item list itself non-trashed and ordered `(weather_profile, sort_order, slug)` as before.
- [x] **Task 4 — Index.vue: window switcher + stat columns** (AC: 2)
  - [x] In `resources/js/pages/Admin/Catalog/Index.vue`: extend `PromoItemRow` with `impressions: number; clicks: number; ctr: number`; add `window: number; windows: number[]` props.
  - [x] Add `WindowSwitcher` (`@/components/admin/WindowSwitcher.vue`) in the header, `:href-for="(days) => index({ query: { days } }).url"` using the `index` route already imported from `@/routes/admin/promo-items`.
  - [x] Add Impressions / Clicks / CTR columns to the table (CTR in `text-brand` with `%`, matching `Admin/Promos`); widen `min-w`.
- [x] **Task 5 — Tests** (AC: 1, 2)
  - [x] `tests/Feature/Admin/PromoAnalyticsTest.php` — add cases proving the repoint: (a) a promo_item whose `weather_profile` differs from the config profile for the same slug → events bucket under the **DB** profile (current wins); (b) an admin-added DB-only slug (not in config) → buckets under its DB profile, not `unknown`; (c) a soft-deleted item's slug still maps (withTrashed). The existing suite (empty `promo_items` → config fallback) must stay green.
  - [x] New `tests/Feature/Admin/CatalogPerformanceTest.php` — admin sees per-item impressions/clicks/CTR on `Admin/Catalog/Index`: an item with seeded `promo_events` shows the right numbers; an item with none shows `0/0/0.0`; `days=7` respected and invalid `days` → 30; still Gate-guarded (guest→login, non-admin→403). Use a local `promo_events` insert helper (don't collide with `PromoAnalyticsTest::seedPromo`).
  - [x] **Gates:** `php artisan test --compact`, `vendor/bin/pint --dirty --format agent`, `./vendor/bin/phpstan analyse`, `npm run types:check`, `npm run lint:check`, `npm run build:ssr` (run `php artisan wayfinder:generate` first — no new routes this story, but keeps types honest).

## Dev Notes

### Scope boundary
- This is the last Epic 8 story. It (a) repoints the 7.6 analytics slug→profile source to the DB catalog with a config fallback, and (b) adds per-item performance to the 8.3 catalog list. It does **not** retire `config('tripcast.promo.catalog')` (explicitly gated behind this repoint + a bake period), does not change `promo_events`, the provider, or the CRUD write paths.

### Architecture (binding)
- **FR-25 / AD-18 — analytics from `promo_events`.** Weather profile is not stored on the event; it is derived by slug→profile lookup. Repointing that lookup to `PromoItem` (withTrashed) means admin-managed items bucket correctly and a retired item's historical events still resolve. Config stays a fallback so nothing regresses to `unknown` mid-bake. [Source: app/Services/Metrics/PromoAnalytics.php]
- **AD-12 — single Gate.** The catalog index already sits inside the admin group; adding stats is read-only, no new gate. [Source: routes/web.php admin group]
- **Determinism note (8.4):** historical events take the item's *current* profile — a profile edit re-buckets past events in analytics. This is the accepted mutable-catalog tradeoff already recorded in `project-context.md`.

### Code intel (exact)
- **`PromoAnalytics::build()`** returns `totals` + `by_slug` (sorted impressions desc, ties alpha) + `by_profile`; `slugToProfileMap()` currently inverts config; `ctr()` = `round(clicks/impressions*100, 1)`, 0.0 when no impressions. Preserve all of this. [Source: PromoAnalytics.php]
- **Window pattern:** `MetricsService::ALLOWED_WINDOWS = [7,30,90]`, `resolveWindow(int): MetricsWindow` (throws on unsupported — so validate first), `MetricsWindow->days`. Mirror `AdminController::promos` exactly (default 30, invalid→30). [Source: MetricsService.php:27-47; AdminController.php:125-140]
- **`PromoEvent`:** `EVENT_IMPRESSION`/`EVENT_CLICK`, `promo_slug`, `send_date`; analytics windows on `send_date` via `whereBetween($window->start/end->toDateString())`. [Source: PromoAnalytics.php:26-30; PromoEvent model]
- **Catalog index (8.3):** `PromoItemController::index` renders `Admin/Catalog/Index` with `items` (via private `toArray()`), `profiles`, `merchants`. Extend, don't rewrite. [Source: app/Http/Controllers/PromoItemController.php]
- **`Admin/Catalog/Index.vue` (8.3):** table with label/slug/profile/merchant/featured/sort/status/actions + a retire Dialog. Add stat columns + `WindowSwitcher`. The `index` route helper is already imported from `@/routes/admin/promo-items` and accepts `{ query: { days } }`. [Source: resources/js/pages/Admin/Catalog/Index.vue]
- **`WindowSwitcher`** props: `window`, `windows`, `hrefFor(days)=>string`. Same usage as `Admin/Promos`. [Source: resources/js/components/admin/WindowSwitcher.vue; Admin/Promos.vue]

### Testing standards / gotchas
- **Fluent `assertInertia` closures receive Collections** — use `collect(...)`. **Whole-number floats serialize to int** — assert `ctr`/`0.0` with `fn ($v) => (float) $v === 25.0`. [Source: project-context.md]
- The existing `PromoAnalyticsTest` seeds **no** `promo_items`, so it exercises the config fallback — it must stay green after the repoint. New repoint tests seed `PromoItem::factory()` rows to prove DB-wins.
- Pest loads all test files globally — the new perf test must not redefine `seedPromo`/`seedPromoFixture` (already global in `PromoAnalyticsTest`); use a distinct helper name.

### Project Structure Notes
- **New:** `tests/Feature/Admin/CatalogPerformanceTest.php`.
- **Modified:** `app/Services/Metrics/PromoAnalytics.php` (repoint + `perSlug()` + fold refactor), `app/Http/Controllers/PromoItemController.php` (window + per-item stats), `resources/js/pages/Admin/Catalog/Index.vue` (columns + switcher), `tests/Feature/Admin/PromoAnalyticsTest.php` (repoint cases).
- **Unchanged:** `config/tripcast.php` (fallback retained, not retired), `promo_events`, the provider/model/CRUD writes, `Admin/Catalog/Form.vue`.

### Previous story intelligence (8.3 / 8.4)
- 8.3 built the catalog list + `toArray()` projection; 8.4 recorded the mutable-catalog determinism tradeoff in `project-context.md` (relevant to "historical events take current profile"). The `promo_events.promo_slug → promo_items.slug` join uses `withTrashed` because a retired item's slug still owns its historical events (8.2 click path). [Source: 8-3-*.md; 8-4-*.md]

### References
- [Source: _bmad-output/planning-artifacts/epics.md#Story-8.5]
- [Source: app/Services/Metrics/PromoAnalytics.php; MetricsService.php; MetricsWindow.php]
- [Source: app/Http/Controllers/PromoItemController.php; resources/js/pages/Admin/Catalog/Index.vue; components/admin/WindowSwitcher.vue]
- [Source: tests/Feature/Admin/PromoAnalyticsTest.php; _bmad-output/planning-artifacts/project-context.md]

## Dev Agent Record

### Agent Model Used

claude-opus-4-8 (1M context)

### Debug Log References

- The `build()` refactor (fold once per slug, roll up to profile via the slug→profile map) kept `PromoAnalyticsTest` byte-stable on the first run — `by_slug`/`by_profile`/`totals` unchanged. The existing suite seeds no `promo_items`, so it exercises the config fallback and confirms no regression.

### Completion Notes List

- **AC1 repoint (DB-first, config fallback):** `PromoAnalytics::slugToProfileMap()` now builds from `PromoItem::withTrashed()` (`slug => weather_profile`) merged **over** the config inversion (`array_merge($configMap, $dbMap)` → DB wins). Admin-added and edited items bucket by their current DB profile; retired items still resolve (`withTrashed`); config-seeded slugs not yet in the DB still resolve via the fallback (no `unknown` regression mid-bake). Config catalog is **not** retired — still gated behind the bake period.
- **Fold refactor + `perSlug()`:** the single grouped `promo_events` query now folds once into a per-slug map; `by_profile` rolls up from it via the slug→profile map (removed the duplicate fold). Added `PromoAnalytics::perSlug(MetricsWindow): array` (`slug => {impressions, clicks, ctr}`) reused by the catalog controller. `build()` output is unchanged.
- **AC2 per-item performance:** `PromoItemController::index` resolves a 7/30/90 window (default 30, invalid→30, mirroring `AdminController::promos`), calls `perSlug($window)`, and attaches `impressions`/`clicks`/`ctr` to each projected item (0/0/0.0 when a slug has no events). `Admin/Catalog/Index.vue` gained a `WindowSwitcher` and Impr./Clicks/CTR columns (CTR in `text-brand`), still read-only, phone-first, behind the single Gate.
- **Tests:** +3 `PromoAnalyticsTest` repoint cases (DB profile overrides config for a shared slug; admin-added DB-only slug buckets correctly not `unknown`; soft-deleted slug still maps) and a new `CatalogPerformanceTest` (per-item stats correct + zeroes for no-events; `days=7` respected; invalid→30; Gate guards guest→login / non-admin→403).
- **Scope held:** no `config/tripcast.php` change (fallback retained), no `promo_events`/provider/model/CRUD-write changes, no migration.
- **Verification:** full suite **425 passed / 1796 assertions** (+7). `pint` clean, `phpstan` 0 errors, `types:check` + `lint:check` clean, `build:ssr` OK.

### File List

**New:**
- `tests/Feature/Admin/CatalogPerformanceTest.php`

**Modified:**
- `app/Services/Metrics/PromoAnalytics.php` (DB-first slug→profile repoint + config fallback; fold refactor; `perSlug()`)
- `app/Http/Controllers/PromoItemController.php` (window resolution + per-item stats on `index`)
- `resources/js/pages/Admin/Catalog/Index.vue` (WindowSwitcher + Impr./Clicks/CTR columns; row type + props)
- `tests/Feature/Admin/PromoAnalyticsTest.php` (+3 repoint cases)

**Unchanged:** `config/tripcast.php` (fallback retained, not retired), `promo_events`, `DatabasePromoProvider`/`AffiliatePromoProvider`, `PromoItem` model/factory, the migration, `Admin/Catalog/Form.vue`, `Admin/Promos.vue`. No new routes/migration.

### Change Log

- 2026-07-01 — Implemented Story 8.5: repointed `PromoAnalytics` slug→profile lookup to the DB `PromoItem` catalog (`withTrashed`) with config as a bake-period fallback; extracted a reusable per-slug fold + `perSlug()`; added per-item impressions/clicks/CTR (7/30/90 window) to the catalog list with a `WindowSwitcher`. All gates green (425 tests). Closes Epic 8's story backlog.

## Review Findings (code review 2026-07-01)

- [x] [Review][Defer] CTR reads 0.0% with nonzero clicks near a window edge — when an impression falls just outside the 7/30/90 window but a click on the same slug falls inside it, the row shows `impressions=0, clicks≥1, ctr=0.0%`. Inherited windowed-analytics semantics from the Story 7.6 fold (not introduced by 8.5). [app/Services/Metrics/PromoAnalytics.php:95-106] — deferred, pre-existing
