---
baseline_commit: 035780a
---

# Story 8.4: Weather mapping, Featured override & Essentials fallback

Status: review

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

As the builder,
I want to pin date-ranged Featured items and curate the Essentials pool,
so that I can override the weather mapping for campaigns and cover neutral/early conditions.

## Scope note (read first — most of this story already shipped)

The bulk of 8.4's behavior was delivered by 8.2 (the `DatabasePromoProvider` precedence chain + `WeatherProfiler` `mild`→Essentials) and 8.3 (the CRUD form that lets admins set `weather_profile`, `featured_from`/`featured_to`, `sort_order`, and `is_active`, with `mild` omitted from new-item options). This story closes the remaining gaps:

1. **Re-bucket the one unreachable seeded item.** The config catalog's `mild` bucket holds a single **placeholder** item (`packing-cubes`, a `placehold.co`/`B000PLACEHOLDER8` stub). The DB provider never queries the `mild` profile (`WeatherProfiler` routes neutral weather to Essentials), so once the seeder mirrors it, that row is **unreachable**. Fix: the **seeder** maps the config `mild` bucket to the `travel-essentials` weather_profile on write, so the demo/fallback catalog has no stranded item. *(Decision 2026-07-01: placeholders stay as a demo/dev fallback; production gets real products via the 8.3 CRUD, seeded placeholders deleted or never seeded — so a **seeder fix alone** suffices; no data migration.)*
2. **Precedence edge regression tests** — lock in AC1 behaviors not yet explicitly covered.
3. **Documentation** — the one-time selection shift + determinism-under-mutation notes, folded into the existing `project-context.md` (no new doc file, per CLAUDE.md).

**Not in scope:** no `config/tripcast.php` change; `AffiliatePromoProvider` stays frozen (the rollback adapter keeps reading `config['mild']`); no data migration; no provider/model/UI changes.

## Acceptance Criteria

**AC1 — Selection precedence (regression-locked)** *(FR-26, AD-18)*
- **Given** the selection precedence
- **When** a send is composed for `sendDate`
- **Then** an active **Featured** pin (`featured_from <= sendDate <= featured_to`, `featured_to` null = open-ended) wins over the weather-profile pool, which wins over the **Essentials** pool (`travel-essentials`); an empty matched-profile pool falls through to Essentials, and only a **fully empty Essentials pool** (with a non-empty table, so no config fallback) yields `null`. Inactive items are excluded from **every** pool incl. Featured.

**AC2 — Fixed taxonomy + `mild` re-bucket so no seeded item is unreachable** *(FR-26)*
- **Given** the fixed taxonomy (admins manage items, not profiles)
- **When** the catalog is seeded
- **Then** the six weather keys stay immutable (`PromoItem::PROFILES`), `WeatherProfiler` never emits `mild`, the 8.3 CRUD does not offer `mild` for *new* items, and the seeder **re-buckets the config `mild` item into `travel-essentials`** so it is reachable (no seeded item is stranded). The one-time selection shift — `mild`-weather and `<2`-usable-day sends move from their prior config slug to a `travel-essentials` slug, and the Essentials pool grows so its `crc32 % count` rotation shifts — is documented in `project-context.md`. `mild` remains a valid legacy key on the model.

**AC3 — Determinism under a mutable catalog** *(FR-26, AD-18)*
- **Given** determinism under a mutable catalog
- **When** an admin edits Featured windows, `is_active`, `sort_order`, or soft-deletes between a send and a re-render
- **Then** it is documented that pool membership is **immutable only for a fixed catalog state** — the rotation tiebreaker is the stable unique `slug` (never `id`, which is not reseed-stable), `sort_order` is admin-controllable in the 8.3 form, and a retroactive edit that shifts an already-logged `send_date`'s selection is an **accepted/known** tradeoff (the digest already sent; `promo_events` already logged the shown slug). Recorded in `project-context.md`.

## Tasks / Subtasks

- [x] **Task 1 — Seeder re-buckets the config `mild` bucket into Essentials** (AC: 2)
  - [x] In `database/seeders/PromoItemSeeder.php`, when the config profile key is `mild` (`PromoItem::PROFILE_MILD`), write `weather_profile = PromoItem::PROFILE_ESSENTIALS` instead. Keep everything else (upsert on `slug`, `merchant`, `is_active`, `image_url`/`url`, `sort_order = $index`) unchanged. A one-line `$weatherProfile = $profile === PromoItem::PROFILE_MILD ? PromoItem::PROFILE_ESSENTIALS : $profile;` mapping.
  - [x] Keep `sort_order = $index` (the within-bucket index) — the tiebreaker is `(sort_order, slug)`, so the collision with the existing essentials items resolves deterministically by slug. (Growing the Essentials pool 2→3 shifts its rotation; that is the intended one-time switchover shift — document it, don't fight it.)
  - [x] Leave `config/tripcast.php` and `AffiliatePromoProvider` untouched (the rollback adapter stays byte-stable, still reading `config['mild']`).
- [x] **Task 2 — Update `PromoItemSeederTest` for the re-bucket** (AC: 2)
  - [x] `tests/Feature/PromoItemSeederTest.php`:
    - "maps each item to its config profile…" — compute the expected profile as `$profile === PromoItem::PROFILE_MILD ? PromoItem::PROFILE_ESSENTIALS : $profile` before asserting `weather_profile` (sort_order stays `$index`).
    - Replace "preserves the mild and travel-essentials profiles" with a test asserting the re-bucket: **no** row has `weather_profile = mild` after seeding, and the `travel-essentials` pool count is **3** (`universal-adapter`, `travel-power-bank`, `packing-cubes`).
    - Total-count test (10 rows) and the idempotency test stay green (packing-cubes now consistently lands in essentials across re-runs).
- [x] **Task 3 — Precedence edge regression tests** (AC: 1)
  - [x] Add to `tests/Feature/Promo/DatabasePromoProviderTest.php`:
    - **All pools empty (non-empty table) → null:** seed a single **inactive** item (table non-empty so no config fallback), select with a neutral snapshot → `select(...)` returns `null`.
    - **Inactive Featured pin excluded:** an inactive item whose window covers `sendDate` + an active Essentials item → Essentials is selected, not the inactive pin.
    - **Inactive profile item excluded:** an inactive item on the matched weather profile + an active Essentials item, with a snapshot mapping to that profile → falls through to Essentials.
  - [x] These assert already-correct behavior (`select()` uses `->active()` on every pool and returns `null` from the final `pick` when Essentials is empty) — they are regression guards, not a behavior change.
- [x] **Task 4 — Documentation (existing files only)** (AC: 2, 3)
  - [x] Add a short **"Epic 8 catalog switchover & determinism"** subsection to `_bmad-output/planning-artifacts/project-context.md` capturing: (a) the one-time selection shift (`mild`/`<2`-day sends → `travel-essentials`; Essentials pool grew 2→3 so its rotation index changed once); (b) determinism is per fixed-catalog-state, tiebreaker is stable `slug` never `id`; (c) retroactive Featured/`sort_order`/`is_active`/soft-delete edits can shift a future send's pick but already-sent dates are settled in `promo_events` — accepted/known. No new file.
- [x] **Task 5 — Gates**
  - [x] `php artisan test --compact` (full suite green), `vendor/bin/pint --dirty --format agent`, `./vendor/bin/phpstan analyse`. (No FE change this story, but run `npm run lint:check`/`types:check` if any JS is touched — it should not be.)

## Dev Notes

### Why the re-bucket is seeder-only (no migration)
- The config catalog is **placeholder scaffolding** (all `placehold.co` images + `B000PLACEHOLDER*` URLs). Per the 2026-07-01 decision, placeholders remain a **demo/dev fallback**; production gets real products through the 8.3 `/admin/promo-items` CRUD, with any seeded placeholders deleted or simply never seeded. So a fixed seeder produces correct data on every fresh seed, and no environment relies on stale seeded `mild` rows — a data migration would be dead weight. The dev DB currently has **0 `promo_items` rows**. [Source: config/tripcast.php promo.catalog; user decision 2026-07-01]

### Code intel (exact)
- **Seeder:** `database/seeders/PromoItemSeeder.php` — `updateOrCreate(['slug' => …], [... 'weather_profile' => $profile, 'sort_order' => $index ...])`. The only change is mapping `$profile` `mild`→`travel-essentials` on write. [Source: PromoItemSeeder.php]
- **Provider (already correct):** `DatabasePromoProvider::select` builds each pool with `->active()` (so inactive rows, incl. Featured pins, are excluded) and returns `null` from the final Essentials `pick()` when empty; the empty-**table** config fallback only triggers when `! PromoItem::query()->exists()`. So "non-empty table, all pools empty → null" holds. [Source: app/Services/Promo/DatabasePromoProvider.php:24-49]
- **Profiler:** `WeatherProfiler::profile` returns `null` for neutral (`mild`) and `<2` usable days (before the snow check), else snow/hot/cold-wet/cold. A `null` profile → empty profile pool → Essentials. [Source: app/Services/Promo/WeatherProfiler.php]
- **`AffiliatePromoProvider` (frozen):** keeps `profileFor` `default => 'mild'` and reads `config['mild']`. **Do not touch** — it is the code-free rollback and its test asserts byte-stable behavior. [Source: AffiliatePromoProvider.php:130]
- **Seeder test coupling:** `tests/Feature/PromoItemSeederTest.php` currently asserts the `mild` profile is preserved and essentials count is 2 — both invert with this story (Task 2). [Source: PromoItemSeederTest.php:31-54]

### Testing standards
- Pest + `RefreshDatabase` (MySQL `tripcast_test`). Seed via `$this->seed(PromoItemSeeder::class)` or `PromoItem::factory()` states. For the empty-pool→null test, remember the config fallback fires only on a genuinely empty table — seed at least one (inactive) row so `exists()` is true. [Source: project-context.md]

### Project Structure Notes
- **Modified:** `database/seeders/PromoItemSeeder.php`, `tests/Feature/PromoItemSeederTest.php`, `tests/Feature/Promo/DatabasePromoProviderTest.php`, `_bmad-output/planning-artifacts/project-context.md`.
- **Unchanged:** `config/tripcast.php`, `AffiliatePromoProvider`, `DatabasePromoProvider`, `WeatherProfiler`, `PromoItem` model/factory, the migration, all UI, `promo_events`. **No new files, no migration.**

### Previous story intelligence (8.2 / 8.3)
- 8.2 established `mild`→Essentials in `WeatherProfiler` and the never-blank empty-table fallback; its file list noted the seeder was left unchanged — that deferred re-bucket is what this story completes.
- 8.3 already omits `mild` from new-item options and keeps legacy `mild` rows editable; `sort_order` is an editable field in the form (satisfies AC3's "admin-controllable sort_order"). [Source: 8-2-*.md; 8-3-*.md]

### References
- [Source: _bmad-output/planning-artifacts/epics.md#Story-8.4 + Epic 8 "`mild` → Essentials" header]
- [Source: app/Services/Promo/DatabasePromoProvider.php; WeatherProfiler.php; AffiliatePromoProvider.php]
- [Source: database/seeders/PromoItemSeeder.php; tests/Feature/PromoItemSeederTest.php; tests/Feature/Promo/DatabasePromoProviderTest.php]
- [Source: _bmad-output/planning-artifacts/project-context.md]

## Dev Agent Record

### Agent Model Used

claude-opus-4-8 (1M context)

### Debug Log References

- None. TDD ran clean: the two `PromoItemSeederTest` cases went red on the pre-change seeder (`mild` still preserved), the three new `DatabasePromoProviderTest` edge cases passed immediately (the provider already `->active()`-filters every pool and returns `null` from the final Essentials `pick`), and the seeder one-line map turned the seeder tests green.

### Completion Notes List

- **Seeder re-bucket (the one functional change):** `PromoItemSeeder` now maps the config `mild` bucket to the `travel-essentials` weather_profile on write (`$weatherProfile = $profile === PROFILE_MILD ? PROFILE_ESSENTIALS : $profile`), keeping `sort_order = $index`. So the seeded `packing-cubes` placeholder lands in Essentials (reachable) instead of the never-queried `mild` profile. `config/tripcast.php` and the frozen `AffiliatePromoProvider` (rollback adapter) are untouched.
- **Decision honored:** placeholders stay as a demo/dev fallback; prod gets real products via the 8.3 CRUD (seeded placeholders deleted or never seeded) — so a seeder fix alone suffices, **no data migration** (dev DB has 0 rows; any fresh seed is now correct).
- **AC1 regression guards:** added three `DatabasePromoProviderTest` cases — (a) non-empty table with all pools empty → `null` (config fallback only fires on a truly empty table); (b) an inactive Featured pin is excluded, falling through to Essentials; (c) an inactive weather-profile item is excluded, falling through to Essentials. These lock in already-correct behavior.
- **AC2/AC3 docs:** added an "Epic 8 catalog switchover & determinism" section to `project-context.md` (the one-time selection shift, placeholders-are-demo-fallback, determinism-per-fixed-catalog-state with `slug` tiebreaker, and retroactive-edits-are-accepted). Also updated the Epic 7 "read-only admin" note to record that Epic 8 added the first mutating admin surface under the same single Gate. No new doc file (per CLAUDE.md).
- **Scope held:** no `config` change, no migration, no provider/model/UI change. Much of 8.4's stated behavior was already delivered by 8.2 (precedence + `WeatherProfiler`) and 8.3 (CRUD sets `weather_profile`/Featured window/`sort_order`; `mild` omitted for new items).
- **Verification:** full suite **418 passed / 1739 assertions** (+3 provider edge tests; seeder count unchanged — 1 replaced, 1 amended). `pint` clean, `phpstan` 0 errors. No FE change → lint/types N/A.

### File List

**Modified:**
- `database/seeders/PromoItemSeeder.php` (re-bucket config `mild` → `travel-essentials`)
- `tests/Feature/PromoItemSeederTest.php` (assert the re-bucket instead of `mild` preservation)
- `tests/Feature/Promo/DatabasePromoProviderTest.php` (+3 precedence edge regression tests)
- `_bmad-output/planning-artifacts/project-context.md` (Epic 8 switchover/determinism notes; Epic 7 admin note updated)

**Unchanged:** `config/tripcast.php`, `AffiliatePromoProvider`, `DatabasePromoProvider`, `WeatherProfiler`, `PromoItem` model/factory, the migration, all UI, `promo_events`. No new files, no migration.

### Change Log

- 2026-07-01 — Implemented Story 8.4: seeder now re-buckets the neutral/legacy `mild` config item into `travel-essentials` so no seeded item is unreachable under `DatabasePromoProvider`; added precedence edge regression tests (all-pools-empty → null, inactive Featured/profile excluded); documented the one-time switchover shift + determinism-under-mutation in `project-context.md`. Seeder-fix-only (no migration) per the placeholders-as-demo-fallback decision. All gates green (418 tests).
