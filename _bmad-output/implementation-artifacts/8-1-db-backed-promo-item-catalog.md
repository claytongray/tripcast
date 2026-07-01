---
baseline_commit: 56a3fc2
---

# Story 8.1: DB-backed `PromoItem` catalog (foundation)

Status: review

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

As the builder,
I want the weather-keyed promo catalog in a `promo_items` table with a model, factory, and a config-seeded switchover,
So that later Epic 8 stories can serve and manage promos from the database without ever leaving the digest slot empty.

## Acceptance Criteria

**AC1 — `promo_items` migration with attribution-stable schema + selection indexes** *(FR-26, AD-18)*
- **Given** the `promo_items` migration
- **When** it runs
- **Then** it creates `promo_items` with `id`; `slug` (string, **unique** — the stable attribution key, a plain unique on the column so it spans soft-deleted rows); `label`, `image_url`, `url` (strings); `merchant` (string, default `amazon`); `weather_profile` (string); `is_active` (boolean, default `true`); `featured_from`, `featured_to` (nullable dates); `sort_order` (unsigned int, default `0`); `softDeletes()`; `timestamps()`; plus indexes `(is_active, weather_profile, sort_order)` (Story 8.2 profile rotation) and `(is_active, featured_from, featured_to)` (Story 8.2 Featured-window lookup). Filename `2026_07_01_000001_create_promo_items_table.php` sorts **after** the existing `2026_06_30_000001_create_promo_events_table.php`.

**AC2 — `PromoItem` model: constants, casts, scopes** *(FR-26, AD-18)*
- **Given** the `PromoItem` Eloquent model
- **When** it is used
- **Then** it uses `HasFactory<PromoItemFactory>` + `SoftDeletes`; declares `MERCHANT_AMAZON='amazon'`, `MERCHANT_OTHER='other'`, `MERCHANTS=[amazon, other]`, the six `PROFILE_*` keys and `PROFILES=[snow, hot, cold-wet, cold, mild, travel-essentials]` (the fixed taxonomy admins may **not** extend); `$fillable` covers only admin-managed columns (nothing user-supplied); `casts()` returns `is_active`→boolean, `featured_from`/`featured_to`→date, `sort_order`→integer; and it exposes `scopeActive`, `scopeForProfile(string $profile)`, and `scopeFeaturedOn(CarbonInterface|string $date)` where the Featured scope treats `featured_to IS NULL` as an **open-ended** pin.

**AC3 — `PromoItemFactory` with the states later stories need** *(FR-26)*
- **Given** `PromoItemFactory`
- **When** it builds items
- **Then** `definition()` returns a valid **active Amazon** item (unique slug, placeholder image, an `amazon.com/dp/...` url, a random `PROFILES` profile, `is_active=true`, null Featured window, `sort_order=0`), and it provides `inactive()`, `forProfile(string)`, `essentials()`, `other(string $url)`, `featured(?string $from = null, ?string $to = null)`, and `trashed()` states; any relative dates use the `America/New_York` send clock (AD-7), matching `TripFactory`.

**AC4 — `PromoItemSeeder`: idempotent, config-fidelity switchover source** *(FR-26, AD-18)*
- **Given** `PromoItemSeeder` and `config('tripcast.promo.catalog')`
- **When** it runs (and re-runs)
- **Then** each catalog item is upserted **keyed on `slug`** with `weather_profile` = its config profile key (including `mild` and `travel-essentials`), `merchant = amazon`, `is_active = true`, `image_url` = item `image`, `url` = item `url`, and `sort_order` = its 0-based index within that profile's list; the seeder is registered in `DatabaseSeeder::run()`; and running it **twice** leaves the row count and every column unchanged (idempotency).

**AC5 — Foundation only: no runtime behavior change** *(FR-26)*
- **Given** this is the foundation story
- **When** 8.1 ships
- **Then** the `PromoProvider` binding still resolves `AffiliatePromoProvider`, the digest still selects from `config('tripcast.promo.catalog')`, `promo_events` is untouched, and there is **no** provider swap, **no** CRUD, and **no** `PromoAnalytics` change (those are Stories 8.2/8.3/8.5). Adding the table changes no observable behavior.

## Tasks / Subtasks

- [x] **Task 1 — Migration `create_promo_items_table`** (AC: 1)
  - [x] `php artisan make:migration create_promo_items_table` then rename the file to `2026_07_01_000001_create_promo_items_table.php` so it sorts after `promo_events`.
  - [x] Anonymous-class migration (match `create_trips_table` style): `id`; `slug` string; `label`, `image_url`, `url` strings; `merchant` string default `'amazon'` (`// amazon|other`); `weather_profile` string (`// snow|hot|cold-wet|cold|mild|travel-essentials`); `is_active` boolean default `true`; `featured_from`/`featured_to` nullable dates; `sort_order` unsignedInteger default `0`; `softDeletes()`; `timestamps()`.
  - [x] Indexes: `$table->unique('slug')` (attribution key — a plain column unique, NOT composite with `deleted_at`, so soft-deleted slugs stay reserved); `$table->index(['is_active', 'weather_profile', 'sort_order'])`; `$table->index(['is_active', 'featured_from', 'featured_to'])`.
  - [x] `down()`: `Schema::dropIfExists('promo_items')`.
  - [x] After creating the migration, run `php artisan migrate` on the **dev DB** (RefreshDatabase hides pending migrations from the test suite — project-context gotcha).
- [x] **Task 2 — `PromoItem` model** (AC: 2)
  - [x] `php artisan make:model PromoItem` (do NOT let it create a migration — already done in Task 1).
  - [x] `use HasFactory<PromoItemFactory>` (`/** @use HasFactory<PromoItemFactory> */`) + `use SoftDeletes`.
  - [x] `@property` PHPDoc block (id, slug, label, image_url, url, merchant, weather_profile, is_active, `Carbon|null` featured_from/featured_to, sort_order, timestamps, deleted_at) so provider/CRUD reads are typed, not `mixed`.
  - [x] Constants: `MERCHANT_AMAZON`, `MERCHANT_OTHER`, `MERCHANTS`; `PROFILE_SNOW`/`PROFILE_HOT`/`PROFILE_COLD_WET`/`PROFILE_COLD`/`PROFILE_MILD`/`PROFILE_ESSENTIALS`; and `public const PROFILES` (the six-key list).
  - [x] `$fillable = ['slug','label','image_url','url','merchant','weather_profile','is_active','featured_from','featured_to','sort_order']`.
  - [x] `casts()`: `is_active`→`boolean`, `featured_from`/`featured_to`→`date`, `sort_order`→`integer`.
  - [x] Scopes (LoginToken `scopeXxx(Builder $query): void` style, `Builder<PromoItem>` PHPDoc): `scopeActive` (`where('is_active', true)`); `scopeForProfile($profile)` (`where('weather_profile', $profile)`); `scopeFeaturedOn($date)` — `whereNotNull('featured_from')->whereDate('featured_from','<=',$date)->where(fn (Builder $q) => $q->whereNull('featured_to')->orWhereDate('featured_to','>=',$date))` (open-ended pin support).
  - [x] Optional (for Story 8.5) `promoEvents(): HasMany` → `hasMany(PromoEvent::class, 'promo_slug', 'slug')` (non-standard key pair; used `withTrashed` later). Include only if it doesn't trip PHPStan; otherwise defer to 8.5.
- [x] **Task 3 — `PromoItemFactory`** (AC: 3)
  - [x] `php artisan make:factory PromoItemFactory` (or created with the model). `@extends Factory<PromoItem>`.
  - [x] `definition()`: `slug` = `$this->faker->unique()->slug(3)`; `label` = title-cased words; `image_url` = `https://placehold.co/120x120?text=Item`; `url` = `https://www.amazon.com/dp/B000...`; `merchant` = `PromoItem::MERCHANT_AMAZON`; `weather_profile` = `$this->faker->randomElement(PromoItem::PROFILES)`; `is_active` = `true`; `featured_from`/`featured_to` = `null`; `sort_order` = `0`.
  - [x] States: `inactive()`; `forProfile(string $profile)`; `essentials()` (`PROFILE_ESSENTIALS`); `other(string $url)` (`MERCHANT_OTHER` + url); `featured(?string $from = null, ?string $to = null)` (default `$from` = `Carbon::now('America/New_York')->toDateString()`, `$to` nullable = open-ended); `trashed()` (soft-deleted). Relative dates on the NY clock (AD-7).
- [x] **Task 4 — `PromoItemSeeder` (config-fidelity, idempotent)** (AC: 4)
  - [x] `php artisan make:seeder PromoItemSeeder`. `run(): void`.
  - [x] `/** @var array<string, list<array<string, string>>> $catalog */ $catalog = config('tripcast.promo.catalog', []);` then, inside `DB::transaction(...)`, nested `foreach ($catalog as $profile => $items)` / `foreach (array_values($items) as $index => $item)`.
  - [x] `PromoItem::query()->updateOrCreate(['slug' => $item['slug']], ['weather_profile' => $profile, 'label' => $item['label'], 'image_url' => $item['image'], 'url' => $item['url'], 'merchant' => PromoItem::MERCHANT_AMAZON, 'is_active' => true, 'sort_order' => $index])`.
  - [x] Register `$this->call(PromoItemSeeder::class)` in `DatabaseSeeder::run()`.
- [x] **Task 5 — Tests** (AC: 1–5)
  - [x] `php artisan make:test --pest PromoItemSeederTest`. Pest + `RefreshDatabase` (MySQL `tripcast_test`).
  - [x] Assert: seeded row count == total config items; each config profile present with the right `weather_profile` (incl. `mild`, `travel-essentials`); every row `merchant='amazon'` + `is_active=true`; slugs unique; `sort_order` matches config order per profile; **re-run keeps count + columns stable** (idempotency).
  - [x] Model/factory coverage (same or a second test): `scopeActive` excludes inactive; `scopeForProfile` filters; `scopeFeaturedOn` matches a bounded pin AND an **open-ended** (`featured_to = null`) pin covering the date, and excludes a lapsed pin; `casts` return bool/`Carbon`/int; `slug` unique constraint holds across a soft-deleted row (re-inserting a trashed slug raises a QueryException).
  - [x] **Gates (all green):** `php artisan test --compact`, `vendor/bin/pint --dirty --format agent`, `./vendor/bin/phpstan analyse`, `npm run types:check`, `npm run lint:check`, `npm run build:ssr`.

## Dev Notes

### Scope boundary (read first)
- **Foundation only.** This story delivers the `promo_items` **migration**, the `PromoItem` **model**, the `PromoItemFactory`, and the config-loading `PromoItemSeeder` (+ tests). It does **NOT** swap the `PromoProvider` binding, build any CRUD, or touch `PromoAnalytics` — those are Stories **8.2 / 8.3 / 8.5**. Adding the table changes **no runtime behavior**: the digest still selects from `config('tripcast.promo.catalog')` via the still-bound `AffiliatePromoProvider`, and `promo_events` is untouched (AC5). **No provider swap, no controllers, no Vue.**
- **Why the table can co-exist silently:** `AppServiceProvider` still binds `PromoProvider → AffiliatePromoProvider` (leave `app/Providers/AppServiceProvider.php:55` alone). The seeder mirrors the config catalog into the DB so 8.2 can flip the binding later with zero attribution discontinuity, but until 8.2 flips it, nothing reads `promo_items`.

### Critique resolutions folded into this story
- **Column vocabulary reconciled (was HIGH):** the epics.md 8.1 skeleton spelled columns `profile_slug`/`base_url`/`active`. This story ships the codebase-idiomatic **`weather_profile` / `url` / `is_active`** (plus `merchant`, `featured_from`, `featured_to`, `sort_order`) — matching `users.is_admin` (boolean convention) and `trips.status` (string-with-constants). **All** downstream Epic 8 stories reference these exact names; the Epic 8 header pins this vocabulary.
- **Seeding fidelity for `mild` (was HIGH, deferred to 8.2/8.4):** 8.1 seeds each item under its **exact config profile key** — `packing-cubes` → `weather_profile='mild'` — because 8.1 changes no runtime behavior (config provider still bound) and Story 7.6's `PromoAnalytics` still inverts the config catalog, so `mild` stays consistent end-to-end. The known "`mild` becomes non-selectable once `DatabasePromoProvider` routes neutral weather to Essentials" concern is a **selection** decision resolved in **8.2/8.4** (re-bucket the seeded `mild` item into Essentials at switchover so no item is unreachable) — **out of scope here**.
- **Open-ended Featured windows (was MEDIUM):** the migration makes both `featured_from` and `featured_to` nullable, and `scopeFeaturedOn` explicitly honors `featured_to IS NULL` as open-ended. This is the single agreed semantics that 8.2's provider clause (`whereNull('featured_to')->orWhereDate(...)`) and 8.3's FormRequest (`featured_to` nullable) must match.
- **Determinism tiebreaker (was MEDIUM):** the `(is_active, weather_profile, sort_order)` index backs the 8.2 rotation ordered `(sort_order asc, slug asc)`; the tiebreaker is the **unique `slug`**, never `id` (not reseed-stable). The seeder sets `sort_order` = config array index so 8.2 reproduces `AffiliatePromoProvider`'s per-`(profile, send_date)` pick exactly for `snow/hot/cold-wet/cold`.
- **Soft-delete + attribution (was MEDIUM/LOW):** `slug` is a **plain column unique** (spans soft-deleted rows) so a retired item's slug stays reserved and `promo_events.promo_slug` keeps resolving. `destroy()`/`withTrashed` semantics live in 8.2/8.3; 8.1 only guarantees the schema supports them.

### Architecture (binding)
- **AD-18 — PromoProvider port, deterministic `send_date` rotation, `slug` = attribution key:** the promo is reached through `App\Services\Promo\PromoProvider` (`select`/`findBySlug`) returning the `App\Services\Promo\Promo` DTO (`slug`, `label`, `imageUrl`, `url`). `promo_events.promo_slug` is written at send time and must keep resolving to the same item — hence the unique, soft-delete-spanning `slug`. [Source: app/Services/Promo/PromoProvider.php; app/Services/Promo/Promo.php; app/Models/PromoEvent.php; epics.md#AD-18]
- **FR-26:** the static weather-keyed catalog becomes DB-backed (`PromoItem`) with a fixed taxonomy (`snow/hot/cold-wet/cold/mild/travel-essentials`), a Featured override, and an Essentials fallback. 8.1 is the table + seed foundation. [Source: epics.md#FR-26; epics.md#Epic-8]
- **AD-12 (context, not exercised here):** the later CRUD (8.3) stays behind the single `admin` Gate. 8.1 ships no routes/UI.

### Exact schema (migration)
```
Schema::create('promo_items', function (Blueprint $table) {
    $table->id();
    $table->string('slug');                          // stable attribution key
    $table->string('label');
    $table->string('image_url');
    $table->string('url');                           // base URL; provider (8.2) appends Amazon tag
    $table->string('merchant')->default('amazon');   // amazon|other (Story 8.2 link handling)
    $table->string('weather_profile');               // snow|hot|cold-wet|cold|mild|travel-essentials
    $table->boolean('is_active')->default(true);     // reversible admin toggle (Story 8.3)
    $table->date('featured_from')->nullable();
    $table->date('featured_to')->nullable();         // NULL = open-ended pin
    $table->unsignedInteger('sort_order')->default(0);
    $table->softDeletes();
    $table->timestamps();

    $table->unique('slug');                                     // promo_events.promo_slug joins (8.5)
    $table->index(['is_active', 'weather_profile', 'sort_order']); // 8.2 profile rotation
    $table->index(['is_active', 'featured_from', 'featured_to']);   // 8.2 Featured-window lookup
});
```
- `slug` unique is a **plain column** unique (not `unique(['slug','deleted_at'])`) so a soft-deleted slug stays reserved (attribution stability, AD-18). Surfacing a friendly "slug in use by a retired item / restore it" message is Story 8.3's concern; here it manifests as a DB `1062` in a test.

### Seeding-from-config mapping (exact)
`config('tripcast.promo.catalog')` is `array<string, list<array<string,string>>>` — profile key → list of `{ slug, label, image, url }`. Map per item:

| config source | `promo_items` column |
| --- | --- |
| profile key (`snow`…`travel-essentials`) | `weather_profile` |
| `item['slug']` | `slug` (upsert key) |
| `item['label']` | `label` |
| `item['image']` | `image_url` |
| `item['url']` | `url` |
| — (literal) | `merchant = 'amazon'` |
| — (literal) | `is_active = true` |
| 0-based `array_values` index within the profile | `sort_order` |

Current catalog (10 items) for the count assertion: `snow` (2), `hot` (2), `cold-wet` (2), `cold` (1), `mild` (1: `packing-cubes`), `travel-essentials` (2). `merchant`/`is_active` are literals — no PHPStan literal-string concern; type the `$catalog` read with the array-shape PHPDoc above to avoid `mixed`. [Source: config/tripcast.php:151-186]

### Model conventions (match siblings)
- Mirror `Trip`: `/** @use HasFactory<PromoItemFactory> */ use HasFactory;` + `use SoftDeletes;`, a `@property` block, string-status-with-constants (`MERCHANT_*`/`PROFILE_*` like `STATUS_*`), `protected $fillable` (list), and `protected function casts(): array`. [Source: app/Models/Trip.php:33-60]
- Scopes follow `LoginToken`'s classic `public function scopeXxx(Builder $query): void` with `@param Builder<PromoItem> $query` — **not** the `#[Scope]` attribute. [Source: app/Models/LoginToken.php:62-72]
- PHP rules (CLAUDE.md): curly braces always; constructor promotion where a constructor exists (none needed here); explicit return types; PHPDoc over inline comments; `TitleCase` enum-like constant keys.

### Factory conventions
- `@extends Factory<PromoItem>`, `definition(): array` returns `array<string, mixed>`; each state is a `->state(fn (array $attributes) => [...])` returning `static`, documented with a one-line PHPDoc — mirror `TripFactory`. Use `Illuminate\Support\Carbon::now('America/New_York')` for any relative Featured dates so pinned-time tests stay stable (AD-7). [Source: database/factories/TripFactory.php]

### Testing standards
- **Pest + `RefreshDatabase`** against MySQL `tripcast_test` (project-context). Build rows with `PromoItem::factory()`; seed via `$this->seed(PromoItemSeeder::class)` or `(new PromoItemSeeder)->run()`.
- **Idempotency:** call the seeder twice; assert `PromoItem::count()` is unchanged and a spot-checked row's columns are identical (updateOrCreate must not duplicate or drift).
- **Scopes:** `scopeFeaturedOn` needs three cases — bounded pin covering the date (match), **open-ended** pin (`featured_to = null`, `featured_from <=` date) (match), lapsed pin (no match). Pin time with `$this->travelTo('2026-07-01 12:00:00')` for any relative dates.
- **Unique-slug-across-trashed:** soft-delete a factory item, then attempt to insert a second row with the same slug and assert a `QueryException` (the plain unique blocks reuse — the intended attribution guard).
- **No Inertia/HTTP assertions** in this story (no routes/UI). No `promo_events`/digest assertions (behavior unchanged — AC5). Whole-number-float and Collection-in-closure gotchas don't apply to these DB-shape assertions but keep them in mind for 8.2/8.5. [Source: _bmad-output/planning-artifacts/project-context.md]

### Project Structure Notes
- **New:** `database/migrations/2026_07_01_000001_create_promo_items_table.php`; `app/Models/PromoItem.php`; `database/factories/PromoItemFactory.php`; `database/seeders/PromoItemSeeder.php`; `tests/Feature/PromoItemSeederTest.php` (+ optional model/scope test).
- **Modified:** `database/seeders/DatabaseSeeder.php` (register `PromoItemSeeder`).
- **Unchanged (do NOT touch in 8.1):** `app/Providers/AppServiceProvider.php` (binding stays `AffiliatePromoProvider`), `config/tripcast.php` (no `provider` key yet — that's 8.2), `app/Services/Promo/*`, `app/Services/Metrics/PromoAnalytics.php`, `promo_events`, all `/admin` routes/pages. **No Wayfinder regen needed** (no routes added).
- **Dev DB reminder:** run `php artisan migrate` after pulling — RefreshDatabase makes the green suite hide the pending migration (this pattern 500'd `/admin/overview` in Epic 7).

### Previous story intelligence (Epic 7)
- Epic 7 established the single admin Gate, the `Admin/` page folder, `AdminLayout`, and `PromoAnalytics` (7.6) which inverts `config('tripcast.promo.catalog')` for slug→profile. 8.1 leaves that inversion path working by seeding with config fidelity; repointing `PromoAnalytics` at `promo_items` is **Story 8.5**, gated behind a bake period so the config catalog can't be retired prematurely. [Source: _bmad-output/implementation-artifacts/7-6-promo-analytics.md; app/Services/Metrics/PromoAnalytics.php]
- Green gates ≠ correct: reason about switchover parity and attribution stability, not just passing tests (project-context).

### References
- [Source: _bmad-output/planning-artifacts/epics.md#Epic-8, #Story-8.1, #FR-26] (goal, ACs, taxonomy, precedence)
- [Source: _bmad-output/planning-artifacts/epics.md#AD-18, #AD-19, #AD-12] (port, entitlement, admin Gate)
- [Source: config/tripcast.php:151-186] (seed source: promo catalog)
- [Source: app/Services/Promo/AffiliatePromoProvider.php; PromoProvider.php; Promo.php] (v1 adapter + port + DTO the DB path mirrors)
- [Source: app/Models/PromoEvent.php] (`promo_slug` attribution join target)
- [Source: app/Models/Trip.php; app/Models/LoginToken.php; database/factories/TripFactory.php; database/migrations/2026_06_29_000002_create_trips_table.php] (model/factory/migration/scope conventions)
- [Source: _bmad-output/planning-artifacts/project-context.md] (gates, PHPStan/test gotchas, dev-DB migrate)

## Dev Agent Record

### Agent Model Used

claude-opus-4-8 (1M context) — implemented via the `epic-8-catalog-kickoff` ultracode workflow (design → critique → synthesize → implement → verify), then gate-verified in the main session.

### Debug Log References

- Workflow critique caught a HIGH cross-facet **column-name divergence** (provider/seeding used `active`/`base_url`/`profile_slug` vs schema's `is_active`/`url`/`weather_profile`); reconciled to the codebase-idiomatic names before implementation — now binding across all Epic 8 stories.
- Minor cleanups during implement: removed a redundant `array_values` on a list-typed config in the seeder/test (PHPStan), fixed import ordering in the two new test files (Pint).

### Completion Notes List

- **Migration:** `promo_items` with `slug` (unique, spanning soft-deletes — reserves retired slugs, keeps `promo_events` attribution stable), `label`, `image_url`, `url`, `merchant` (default `amazon`), `weather_profile`, `is_active`, `featured_from`/`featured_to` (nullable; NULL `featured_to` = open-ended pin), `sort_order`, soft-deletes, timestamps. Indexes `(is_active, weather_profile, sort_order)` for 8.2 profile rotation and `(is_active, featured_from, featured_to)` for the Featured-window lookup.
- **Model:** `MERCHANT_*`/`PROFILE_*` constants + `MERCHANTS`/`PROFILES` arrays, admin-only `$fillable`, `is_active`/date/int casts (CarbonImmutable), `SoftDeletes`, scopes `active`/`forProfile`/`featuredOn` (open-ended-pin aware), and a `promoEvents` HasMany on the `slug`↔`promo_slug` key pair.
- **Factory + seeder:** `PromoItemFactory` (inactive/forProfile/essentials/other/featured/trashed states); `PromoItemSeeder` loads from `config('tripcast.promo.catalog')` with config fidelity (merchant `amazon`, `weather_profile` = config key incl. `travel-essentials`, `sort_order` = config array index so 8.2 reproduces the current provider's per-(profile,send_date) pick), idempotent via `updateOrCreate`.
- **Scope held (foundation only):** NO `DatabasePromoProvider`, NO binding swap, NO CRUD, NO analytics change. `AffiliatePromoProvider` stays bound and `config` remains the live source — **zero runtime behavior change**.
- **Verification:** two adversarial verifiers returned `solid`; independently re-ran gates in-session — **full suite 376 passed / 1593 assertions** (+14 PromoItem tests), Pint clean, PHPStan 0 errors.

### Carry-forward to 8.2 / 8.4 / 8.5 (from workflow critique + verification)

- **8.2 determinism:** the stable per-`send_date` pick must order `(sort_order asc, slug asc)` and index by `crc32(send_date) % count` — do NOT rely on `id`. Fall back to the config catalog when `promo_items` is empty so the digest slot is never blank pre-seed; pin `PROMO_PROVIDER` for legacy Promo/Digest tests or seed a minimal catalog.
- **8.4 mild routing (OPEN — needs product call):** FR-26 routes neutral (`mild`) weather to Essentials, so a `mild`-profile item is unreachable once `DatabasePromoProvider` is live. Decide: drop `mild` from the CRUD new-item options + re-bucket the seeded mild item into Essentials at switchover, OR keep `mild` fully selectable.
- **8.5 attribution:** join `promo_events.promo_slug → promo_items.slug` with `withTrashed()` so retired items keep their history.

### File List

**New:**
- `database/migrations/2026_07_01_000001_create_promo_items_table.php`
- `app/Models/PromoItem.php`
- `database/factories/PromoItemFactory.php`
- `database/seeders/PromoItemSeeder.php`
- `tests/Feature/PromoItemTest.php`
- `tests/Feature/PromoItemSeederTest.php`

**Modified:**
- `database/seeders/DatabaseSeeder.php` (register `PromoItemSeeder`)

**Unchanged:** promo provider/catalog/config, `promo_events`, all runtime behavior. Migration adds the table only.

### Change Log

- 2026-07-01 — Implemented Story 8.1: DB-backed `PromoItem` catalog (foundation). `promo_items` table + model + factory + config-fidelity seeder + tests; the schema/rotation ordering set up 8.2's `DatabasePromoProvider` and the 8.5 join. Foundation only — no provider swap, CRUD, or analytics change; zero runtime behavior change. Built via the Epic 8 ultracode workflow; all gates green (376 tests).