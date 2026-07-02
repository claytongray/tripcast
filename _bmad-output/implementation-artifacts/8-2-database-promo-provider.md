---
baseline_commit: aeb56e0
---

# Story 8.2: `DatabasePromoProvider` (port adapter + safe switchover)

Status: done

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

As the builder,
I want a database-backed provider behind the existing `PromoProvider` port,
so that the digest selects promos from the admin-managed catalog with the same determinism and fallback as the config adapter.

## Acceptance Criteria

**AC1 — `select()` precedence + byte-identical rotation** *(FR-26, AD-18, AD-3)*
- **Given** `DatabasePromoProvider implements PromoProvider`
- **When** `select(array $snapshot, string $sendDate)` runs
- **Then** it applies precedence **Featured → weather profile → Essentials**: the **Featured** pool is `is_active` items whose `[featured_from, featured_to]` window (open-ended when `featured_to` is null) covers `sendDate`; else the **weather-profile** pool (`is_active`, `weather_profile = profile`); else the **Essentials** pool (`is_active`, `weather_profile = 'travel-essentials'`). Each pool is ordered `(sort_order asc, slug asc)` and the item is `pool[crc32($sendDate) % pool->count()]` — **the same rotation math `AffiliatePromoProvider` uses** — returning the same `Promo` DTO, or `null` only when every pool is empty (and the config fallback also yields nothing).

**AC2 — Neutral/low-signal weather → Essentials** *(FR-26)*
- **Given** the weather profiler
- **When** it maps a snapshot
- **Then** it returns `null` (→ Essentials) when there are **< 2 usable forecast days** (checked **before** the snow short-circuit) **or** when the weather is neutral (`mild`); otherwise it returns one of `snow`/`hot`/`cold-wet`/`cold` via the existing thresholds. A `null` profile makes the weather-profile pool empty, so selection cascades to Essentials.

**AC3 — Hybrid merchant links + resilient `findBySlug`** *(FR-26, AD-18, AD-6)*
- **Given** a selected item becomes a `Promo`
- **When** the DTO is built
- **Then** an `amazon` item's `url` gets the associate tag appended (via a single-sourced `AmazonAffiliateTagger` reused by `AffiliatePromoProvider`) and an `other` item's `url` is used **verbatim**; and `findBySlug($slug)` resolves `withTrashed()` (no `is_active` filter) so an item that logged an impression and was later deactivated/soft-deleted still resolves for the click redirect.

**AC4 — Binding, env toggle, never-blank switchover** *(FR-26, AD-18)*
- **Given** the container binding
- **When** `config('tripcast.promo.provider')` (env `PROMO_PROVIDER`) selects the adapter
- **Then** `AppServiceProvider` binds `DatabasePromoProvider` by default and `AffiliatePromoProvider` when `= 'affiliate'` (a code-free rollback); to guarantee the slot is **never silently blank** before the catalog is seeded, `DatabasePromoProvider` **falls back to `AffiliatePromoProvider` (config catalog)** when `promo_items` is empty (both `select` and `findBySlug`); `promo_events` and the `PromoProvider` interface are unchanged, and all existing Promo/Digest tests stay green.

## Tasks / Subtasks

- [x] **Task 1 — Config + provider binding toggle** (AC: 4)
  - [x] In `config/tripcast.php` `promo` block, add `'provider' => env('PROMO_PROVIDER', 'database')`.
  - [x] In `app/Providers/AppServiceProvider.php` (currently `$this->app->bind(PromoProvider::class, AffiliatePromoProvider::class)` at line ~55), bind by config: `database` → `DatabasePromoProvider`, `affiliate` → `AffiliatePromoProvider` (default `database`). Keep it a simple `match`/conditional bind.
- [x] **Task 2 — Extract `AmazonAffiliateTagger`** (AC: 3)
  - [x] Create `app/Services/Promo/AmazonAffiliateTagger.php` with `tag(string $url): string` — the exact logic from `AffiliatePromoProvider::tagged()` (append `tag={amazon_tag}` with `?`/`&` separator, `urlencode`). Vendor specifics (host/tag) live only here.
  - [x] Refactor `AffiliatePromoProvider` to use it (replace the private `tagged()`), preserving byte-identical output — its dedicated test must stay green.
- [x] **Task 3 — `WeatherProfiler` (nullable profile)** (AC: 2)
  - [x] Create `app/Services/Promo/WeatherProfiler.php` with `profile(array $snapshot): ?string`. Port `AffiliatePromoProvider::profileFor` logic but: (a) count **usable** days (days with a numeric `highF`); if **< 2**, return `null` **before** the snow check; (b) `snow` → `'snow'`; (c) the `hot`/`cold-wet`/`cold` thresholds unchanged; (d) the `default` (neutral) case returns **`null`** instead of `'mild'`. Keep the placeholder threshold constants here.
  - [x] Leave `AffiliatePromoProvider::profileFor` **unchanged** (it is the frozen rollback adapter; a small threshold duplication is acceptable and keeps `AffiliatePromoProviderTest` byte-stable). Note this tradeoff in Dev Notes.
- [x] **Task 4 — `DatabasePromoProvider`** (AC: 1, 2, 3, 4)
  - [x] Create `app/Services/Promo/DatabasePromoProvider.php implements PromoProvider`. Constructor-inject `AmazonAffiliateTagger`, `WeatherProfiler`, and `AffiliatePromoProvider` (the config fallback).
  - [x] `select(array $snapshot, string $sendDate): ?Promo`: if `PromoItem::query()->exists()` is false → delegate to the injected `AffiliatePromoProvider::select(...)` (never-blank pre-seed). Otherwise build the three pools in precedence order (Featured via `scopeFeaturedOn($sendDate)`+active; profile via `scopeActive()->scopeForProfile($profile)` where `$profile = $this->profiler->profile($snapshot)` — a `null` profile yields an empty pool; Essentials via `scopeActive()->scopeForProfile('travel-essentials')`), each `->orderBy('sort_order')->orderBy('slug')->get()`. Pick the first **non-empty** pool, then `$pool[crc32($sendDate) % $pool->count()]`, and map to `Promo` (Task 5). Return `null` if all pools empty.
  - [x] `findBySlug(string $slug): ?Promo`: `PromoItem::withTrashed()->where('slug', $slug)->first()` → map to `Promo`; if not found (incl. empty table) → delegate to `AffiliatePromoProvider::findBySlug($slug)`.
  - [x] Bounded, indexed queries (the 8.1 indexes cover Featured + profile lookups). Read-only.
- [x] **Task 5 — `PromoItem` → `Promo` mapping** (AC: 3)
  - [x] A private `toPromo(PromoItem $item): Promo` building `new Promo(slug, label, imageUrl: $item->image_url, url: $item->merchant === PromoItem::MERCHANT_AMAZON ? $this->tagger->tag($item->url) : $item->url)`.
- [x] **Task 6 — Tests** (AC: 1, 2, 3, 4)
  - [x] Create `tests/Feature/Promo/DatabasePromoProviderTest.php` (Pest, `RefreshDatabase`). Seed `promo_items` via `PromoItem::factory()`. Cover:
    - **Precedence:** a Featured item covering `sendDate` wins over a matching profile item; with no Featured, the profile pool wins over Essentials; with an empty profile pool, Essentials is used.
    - **Rotation determinism:** for a fixed `sendDate`, `select()` returns the SAME slug across calls and equals `pool[crc32(sendDate) % count]` for a pool ordered `(sort_order, slug)`; different `sendDate`s can differ.
    - **Neutral/low-signal → Essentials:** a `mild`/`default` snapshot and a `< 2 usable days` snapshot both select from `travel-essentials`.
    - **Merchant tagging:** an `amazon` item's URL carries `tag=`; an `other` item's URL is verbatim.
    - **Featured window:** open-ended (`featured_to = null`) covers all future dates; a closed window excludes dates outside it.
    - **`findBySlug` withTrashed:** a soft-deleted item still resolves for the redirect.
    - **Empty-table fallback:** with `promo_items` empty, `select`/`findBySlug` delegate to config (a known config slug resolves).
  - [x] Add a focused `WeatherProfiler` unit/feature test (snow, hot, cold-wet, cold, `< 2 days` → null, neutral → null).
  - [x] **Confirm the existing Promo/Digest suites stay green unchanged** (`AffiliatePromoProviderTest`, `PromoRedirectTest`, `SendTripDigestTest`, `DigestMailTest`) — the empty-table config fallback preserves their behavior under the default `database` binding, so they should need **no edits**. If any fails, prefer seeding a minimal catalog in that test over changing provider logic.
  - [x] **Gates:** `php artisan test --compact`, `vendor/bin/pint --dirty --format agent`, `./vendor/bin/phpstan analyse`, `npm run types:check`, `npm run lint:check`, `npm run build:ssr` (no FE change expected, but keep the suite honest).

## Dev Notes

### Scope boundary (read first)
- **Provider swap only.** This story makes the DB catalog live behind the port. No CRUD (8.3), no analytics repoint (8.5). It **does** flip the default binding to `DatabasePromoProvider`, but the empty-table fallback to `AffiliatePromoProvider` means behavior is unchanged until the catalog is seeded. Read-only over `promo_items` (no writes). No migration.

### Architecture (binding)
- **AD-18 — `PromoProvider` port, deterministic `send_date` rotation.** The interface (`select`, `findBySlug`) is frozen; `DatabasePromoProvider` is a second adapter. The rotation `pool[crc32($sendDate) % count]` must match `AffiliatePromoProvider` exactly (a re-render picks the same item — no idempotency hazard with `promo_events`). [Source: app/Services/Promo/PromoProvider.php; AffiliatePromoProvider.php:44-46]
- **FR-26 / mild→Essentials (binding decision):** neutral (`mild`) and low-signal (<2 days) weather route to Essentials. See the Epic 8 header note in `epics.md`. That's why `WeatherProfiler` returns `?string` (null → Essentials) rather than `'mild'`.
- **AD-6 — click redirect** must keep resolving after retirement → `findBySlug` uses `withTrashed()` and no `is_active` filter.

### Code intel (exact)
- **Current binding:** `app/Providers/AppServiceProvider.php:55` — `$this->app->bind(PromoProvider::class, AffiliatePromoProvider::class);`. Replace with a config-driven bind. [Source: AppServiceProvider.php]
- **`AffiliatePromoProvider`** (the reference): `select()` maps `profileFor($snapshot)` → `config('tripcast.promo.catalog')[$profile]` (fallback `travel-essentials`), picks `items[crc32($sendDate) % count($items)]`, builds `Promo(slug, label, imageUrl: item['image'], url: tagged(item['url']))`. `profileFor` returns a string, `default => 'mild'`, snow short-circuits, `FALLBACK_PROFILE='travel-essentials'`, thresholds `HOT_HIGH=80/COLD_HIGH=45/WET_PRECIP=50/WET_HIGH=60`. `tagged()` appends `tag={amazon_tag}`. [Source: AffiliatePromoProvider.php]
- **`Promo` DTO:** `new Promo(slug, label, imageUrl, url)` (constructor-promoted readonly). Note the field is `imageUrl` (camel) mapped from `promo_items.image_url`. [Source: app/Services/Promo/Promo.php]
- **`PromoItem` (8.1):** `scopeActive`, `scopeForProfile($profile)`, `scopeFeaturedOn($date)` (open-ended aware), `MERCHANT_AMAZON`/`PROFILE_*` constants, `image_url`/`url`/`merchant`/`weather_profile`/`sort_order`/`is_active`/`featured_from`/`featured_to`, SoftDeletes. Ordering for rotation: `->orderBy('sort_order')->orderBy('slug')`. [Source: app/Models/PromoItem.php]
- **`config/tripcast.php` promo block:** `amazon_tag`, `catalog`; add `provider`. [Source: config/tripcast.php:151]

### Determinism note (critical)
- The 8.1 seeder set `sort_order = config array index`, so for the `snow`/`hot`/`cold-wet`/`cold` pools the DB order `(sort_order, slug)` reproduces the config array order → `crc32($sendDate) % count` picks the **same** item as `AffiliatePromoProvider` for those profiles. `mild`/early now route to Essentials by design (the one intended switchover shift — document it in Completion Notes).

### Testing standards
- Pest + `RefreshDatabase` (MySQL `tripcast_test`). Seed via `PromoItem::factory()` states (`forProfile`, `essentials`, `other`, `featured`, `trashed`, `inactive`). Assert rotation by computing `crc32($sendDate) % count` yourself against a known-ordered pool. Do **not** edit the legacy Promo/Digest tests unless one genuinely fails; the empty-table fallback should keep them green. [Source: tests/Feature/Promo/*, tests/Feature/Digest/*; _bmad-output/planning-artifacts/project-context.md]

### Project Structure Notes
- **New:** `app/Services/Promo/DatabasePromoProvider.php`, `app/Services/Promo/AmazonAffiliateTagger.php`, `app/Services/Promo/WeatherProfiler.php`, `tests/Feature/Promo/DatabasePromoProviderTest.php` (+ a `WeatherProfiler` test).
- **Modified:** `config/tripcast.php` (`promo.provider`), `app/Providers/AppServiceProvider.php` (config-driven bind), `app/Services/Promo/AffiliatePromoProvider.php` (use `AmazonAffiliateTagger`).
- **Unchanged:** `promo_events`, the `PromoProvider` interface, the `Promo` DTO, `PromoAnalytics` (7.6 — that's 8.5), `PromoItem` (8.1). **No migration.**

### Previous story intelligence (8.1)
- 8.1 shipped `promo_items` + model + config-fidelity seeder (`sort_order` = config index) and the two selection indexes. Carry-forward honored here: determinism tiebreaker `(sort_order, slug)` never `id`; empty-table config fallback; `withTrashed` for `findBySlug`; `mild`→Essentials. [Source: _bmad-output/implementation-artifacts/8-1-db-backed-promo-item-catalog.md]

### References
- [Source: _bmad-output/planning-artifacts/epics.md#Story-8.2 + Epic 8 header binding notes]
- [Source: app/Services/Promo/AffiliatePromoProvider.php; PromoProvider.php; Promo.php]
- [Source: app/Models/PromoItem.php; app/Providers/AppServiceProvider.php; config/tripcast.php]

## Dev Agent Record

### Agent Model Used

claude-opus-4-8 (1M context)

### Debug Log References

- The `AffiliatePromoProvider` constructor gained an `AmazonAffiliateTagger` dependency, so `AffiliatePromoProviderTest`'s `new AffiliatePromoProvider` broke — switched its `provider()` helper to `app(AffiliatePromoProvider::class)` (container injects the tagger).
- Pest loads all test files globally, so the helper `snap()` collided with `NarrationDifferTest::snap()` at full-suite run — renamed the promo helpers to `promoSnap`/`promoHotDays`/`promoMildDays` and `profSnap`/`profDay`.

### Completion Notes List

- **Config-driven binding:** `config('tripcast.promo.provider')` (env `PROMO_PROVIDER`, default `database`) selects the adapter in `AppServiceProvider`; `affiliate` is a code-free rollback.
- **`DatabasePromoProvider`:** precedence Featured → weather-profile → Essentials, each pool ordered `(sort_order, slug)` and picked `pool[crc32(sendDate) % count]` — the same rotation math as `AffiliatePromoProvider`, so seeded `snow`/`hot`/`cold-wet`/`cold` pools reproduce the config adapter's per-day pick. `mild`/early(<2 days) route to Essentials by design (the one intended switchover shift). `findBySlug` uses `withTrashed()`.
- **Never-blank switchover:** injects `AffiliatePromoProvider` as a fallback — when `promo_items` is empty (pre-seed), both `select` and `findBySlug` delegate to config. This kept **all** legacy Promo/Digest tests green **unchanged** (they run under the default `database` binding with an empty table → config behavior), which is cleaner than seeding each one.
- **Shared extraction:** `AmazonAffiliateTagger` single-sources the tag logic (used by both providers); `WeatherProfiler` is the new nullable-profile mapper (`AffiliatePromoProvider::profileFor` left frozen as the rollback — minor threshold duplication accepted).
- **Carry-forward honored from 8.1:** determinism tiebreaker `(sort_order, slug)` never `id`; empty-table fallback; `withTrashed` findBySlug; `mild`→Essentials.
- **Verification:** full suite **388 passed / 1617 assertions** (+27: 10 DatabasePromoProvider + 3 WeatherProfiler + legacy still green). pint clean, phpstan 0 errors, types:check + lint:check clean. No migration; read-only over `promo_items`.

### File List

**New:**
- `app/Services/Promo/DatabasePromoProvider.php`
- `app/Services/Promo/AmazonAffiliateTagger.php`
- `app/Services/Promo/WeatherProfiler.php`
- `tests/Feature/Promo/DatabasePromoProviderTest.php`
- `tests/Feature/Promo/WeatherProfilerTest.php`

**Modified:**
- `config/tripcast.php` (`promo.provider`)
- `app/Providers/AppServiceProvider.php` (config-driven bind + `DatabasePromoProvider` import)
- `app/Services/Promo/AffiliatePromoProvider.php` (inject `AmazonAffiliateTagger`, drop private `tagged()`)
- `tests/Feature/Promo/AffiliatePromoProviderTest.php` (resolve via container)

**Unchanged:** `promo_events`, the `PromoProvider` interface, the `Promo` DTO, `PromoItem` (8.1), `PromoAnalytics` (that's 8.5). No migration.

### Change Log

- 2026-07-01 — Implemented Story 8.2: `DatabasePromoProvider`. The DB catalog now serves the digest behind the `PromoProvider` port (default binding), with precedence Featured → weather profile → Essentials and the same deterministic `send_date` rotation; hybrid merchant tagging; `withTrashed` click resolution; and an empty-table fallback to the config adapter so the slot is never blank pre-seed. `mild`/early weather routes to Essentials (FR-26). Extracted `AmazonAffiliateTagger` + `WeatherProfiler`. All gates green (388 tests).
