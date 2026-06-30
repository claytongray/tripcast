---
baseline_commit: 1567b19
---

# Story 5.2: PromoProvider port + placeholder weather-keyed catalog

Status: review

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

As the builder,
I want a promo port backed by a stubbed, editable catalog,
so that the whole promo pipeline is buildable now and the real products drop in later by editing config.

## Acceptance Criteria

**AC1 — A `PromoProvider` port + adapter reading an editable config catalog with placeholder entries** *(FR-17, AD-18)*
- **Given** a `PromoProvider` interface with an `AffiliatePromoProvider` adapter reading a **config catalog** (vendor specifics — plain tagged Amazon URLs — only in the adapter/config)
- **When** the catalog is seeded
- **Then** it ships with **placeholder entries** — ~4–6 weather profiles (e.g. cold-wet, hot, snow, cold, mild) each mapped to one or more stub products `{slug, label, image, affiliate URL}`, plus a generic **"travel essentials"** fallback — editable later **with no code change**.

**AC2 — Snapshot → profile → one item via deterministic rotation keyed by send_date** *(FR-17, AD-18, AD-3)*
- **Given** a secured forecast snapshot
- **When** the adapter selects
- **Then** it maps the snapshot to a weather profile and picks **one** item via **deterministic rotation keyed by `send_date`** (a re-run picks the **same** item — no idempotency hazard); no profile match or empty catalog → the generic **"travel essentials"** fallback. The returned URL carries the Amazon associate **tag** (config), appended in the adapter only.

## Tasks / Subtasks

- [x] **Task 1 — Config: associate tag + weather-keyed catalog** (AC: 1)
  - [x] Add a `promo` block to `config/tripcast.php`: `amazon_tag` (`env('AMAZON_ASSOCIATE_TAG', 'tripcast0c-20')` — placeholder default; the real tag is set later in env), `timeout` (`max(1, (int) env('TRIPCAST_PROMO_TIMEOUT', 3))` — used by the Story 5.3 timebox), and `catalog` — an array keyed by weather-profile slug. Ship **placeholder entries** for `snow`, `hot`, `cold-wet`, `cold`, `mild`, and the fallback `travel-essentials`; each profile → a list of `['slug' => …, 'label' => …, 'image' => …, 'url' => 'https://www.amazon.com/dp/<ASIN>']` (base product URLs; the tag is appended by the adapter). Slugs are stable, unique attribution keys (consumed by 5.4 `promo_events`). Comment it as **placeholder — edit freely, no code change**.
- [x] **Task 2 — Value object + port** (AC: 1, 2)
  - [x] `app/Services/Promo/Promo.php` — readonly value object: `string $slug`, `string $label`, `string $imageUrl`, `string $url` (the tagged Amazon URL).
  - [x] `app/Services/Promo/PromoProvider.php` — interface `select(array $snapshot, string $sendDate): ?Promo` (AD-1/AD-18 port; vendor specifics never leak past this). Returns null only if even the fallback is empty.
- [x] **Task 3 — `AffiliatePromoProvider` adapter** (AC: 1, 2)
  - [x] `app/Services/Promo/AffiliatePromoProvider.php implements PromoProvider`. `select`:
    - **Map snapshot → profile** via a deterministic, documented heuristic over the snapshot days: a `snow` condition wins; else by aggregate (e.g. avg high °F + max precip%) → `hot` (avg high ≥ 80), `cold-wet` (max precip ≥ 50 and avg high < 60), `cold` (avg high < 45), else `mild`. No usable days → fall through to the fallback. Keep the thresholds as small named constants (placeholder, tunable).
    - **Pick one item** from the profile's list (or `travel-essentials` when the profile is absent/empty) via **deterministic rotation keyed by `send_date`**: `index = crc32($sendDate) % count` (stable for a given send_date). Empty catalog entirely → return null.
    - **Build the URL**: append the associate `tag` to the item's base `url` (`?tag=` / `&tag=` as appropriate). Vendor specifics (the tag, the Amazon host) appear **only here**.
  - [x] Bind in `AppServiceProvider`: `$this->app->bind(PromoProvider::class, AffiliatePromoProvider::class)` (mirrors the `Geocoder`/`WeatherProvider`/`Narrator` binds).
- [x] **Task 4 — Tests** (AC: 1, 2)
  - [x] `tests/Feature/Promo/AffiliatePromoProviderTest.php`: a snowy snapshot → a `snow`-profile item; a hot snapshot → `hot`; a cold+wet snapshot → `cold-wet`; a mild snapshot → `mild`. **Deterministic rotation:** the same `send_date` always returns the same `slug`; (where a profile has ≥2 items) two different send_dates can select different items. **Fallback:** a profile with no catalog entry (override config so a mapped profile is empty) → a `travel-essentials` item. **Empty catalog** (override config catalog to `[]`) → `select` returns null. **Tag:** the returned `url` contains the configured `tag`. Build snapshots with the `{days, limited}` shape (reuse the existing `fday`/snapshot helpers' style).
  - [x] **Gates:** `./vendor/bin/pest`, `vendor/bin/pint --dirty --format agent`, `./vendor/bin/phpstan analyse` (frontend untouched).

## Dev Notes

### Scope boundary (read first)
- **Only** the port, adapter, catalog config, and selection logic. The **digest slot + disclosure render** is Story 5.3; **click attribution / `promo_events`** is Story 5.4. No mail/job changes here. No real product curation — placeholders only. [Source: epics.md#Story-5.2; ARCHITECTURE-SPINE.md#AD-18]

### Architecture (binding)
- **AD-18 — monetization via a Promo port, render-slot only:** "the promo unit is reached through a new **`PromoProvider` port** bound to a concrete adapter in a ServiceProvider, exactly like AD-1/AD-17 — vendor/HTTP appears **only in the adapter** (v1 adapter = a **weather-keyed Amazon affiliate config**: a curated map of weather-profile → product set; affiliate links are plain tagged URLs, no SDK)… Selection maps the secured snapshot to a weather profile and picks **one** item via **deterministic rotation keyed by `send_date`** (a re-render picks the same item — no idempotency hazard), with a generic 'travel essentials' fallback." Selection's *placement* (after snapshot, before render, off-path, timeboxed) is Story 5.3 — here it's a pure function. [Source: ARCHITECTURE-SPINE.md#AD-18, line 154]
- **Idempotency convention:** the rotation must be a pure function of `send_date` so a re-render selects the same item — never a hazard to the AD-3 claim. [Source: ARCHITECTURE-SPINE.md#AD-18, #Idempotency-keys line 170]

### Code intel (exact patterns to reuse)
- **Snapshot shape** (`email_logs.weather_snapshot`): `{days: list<{date, conditionText, precipChance, highC, highF, lowC, lowF}>, limited: bool}` — `precipChance` int% (or null), highs float in both units (or null), `conditionText` string (or null). [Source: app/Services/Weather/ForecastDay.php]
- **Port + ServiceProvider bind** mirrors `Geocoder`/`WeatherProvider`/`Narrator` (interface → concrete; AD-1/AD-17). Bind `PromoProvider` → `AffiliatePromoProvider`. [Source: app/Providers/AppServiceProvider.php]
- **Config catalog idiom**: a plain nested array in `config/tripcast.php` (like the existing blocks); read with `config('tripcast.promo.catalog')`. Floor numeric env reads with `max(1, …)`. [Source: config/tripcast.php]
- **Deterministic keying convention** already used for the digest send key `(trip_id, send_date)` — keep the rotation a pure function of `send_date`. [Source: ARCHITECTURE-SPINE.md#AD-3]

### Testing standards
- Pest, `RefreshDatabase` (not strictly needed — the adapter is pure — but keep the suite consistent). Drive the catalog with `config(['tripcast.promo.catalog' => [...]])` to test fallback/empty deterministically without depending on the shipped placeholders. Build snapshots inline. Assert `Promo` fields (`slug`, `url` contains the tag). `crc32` rotation is stable across runs (no clock/random). [Source: tests/Feature/Narration/* style]

### Project Structure Notes
- **New:** `app/Services/Promo/{Promo,PromoProvider,AffiliatePromoProvider}.php`, `tests/Feature/Promo/AffiliatePromoProviderTest.php`.
- **Modified:** `config/tripcast.php` (`promo` block), `app/Providers/AppServiceProvider.php` (bind).
- **Unchanged:** `SendTripDigest`, `DigestMail`, migrations (no `promo_events` yet — that's 5.4).

### Previous story intelligence (5.1 + Epic 4)
- 5.1's `User::shouldShowPromo()` is the gate; this story is the **selector**; 5.3 joins them at the render slot (the same after-snapshot/before-render seam narration uses in 4.2). Keep the adapter a **pure function** (no I/O — affiliate links are static tagged URLs, "no SDK") so the 5.3 timebox is trivially satisfied. [Source: ARCHITECTURE-SPINE.md#AD-18; app/Jobs/SendTripDigest.php]

### References
- [Source: _bmad-output/planning-artifacts/epics.md#Story-5.2]
- [Source: _bmad-output/planning-artifacts/architecture/architecture-tripcast-2026-06-28/ARCHITECTURE-SPINE.md#AD-18, #AD-1]
- [Source: _bmad-output/specs/spec-tripcast/SPEC.md#FR-17]
- [Source: app/Services/Weather/ForecastDay.php; app/Providers/AppServiceProvider.php; config/tripcast.php]

## Dev Agent Record

### Agent Model Used

claude-opus-4-8 (1M context)

### Debug Log References

- Fallback bug: an *empty* mapped profile (`[]`) doesn't trigger `?? fallback` (only a missing key does). Fixed `select` to fall back to `travel-essentials` when the mapped profile is missing **or** empty, then null only when the fallback is also empty.

### Completion Notes List

- **Config:** added a `promo` block — `amazon_tag` (placeholder env default), `timeout` (for the 5.3 timebox), and a placeholder `catalog` of `snow`/`hot`/`cold-wet`/`cold`/`mild` profiles + a `travel-essentials` fallback, each item `{slug, label, image, url}`. Marked editable with no code change.
- **Port + VO:** `PromoProvider` interface + `Promo` readonly value object (slug/label/imageUrl/url).
- **Adapter:** `AffiliatePromoProvider` (pure, no I/O) — maps the snapshot to a profile (snow wins; else by avg high °F + max precip% → hot/cold-wet/cold/mild; no usable days → fallback), picks one item via `crc32(send_date) % count` (deterministic — a re-render selects the same item), falls back to travel-essentials on missing/empty profile, returns null on an empty catalog, and appends the associate tag to the base URL (vendor specifics isolated here). Bound `PromoProvider` → `AffiliatePromoProvider`.
- **Tests:** profile mapping (snow/hot/cold-wet/mild), deterministic-by-send_date, fallback on empty profile, null on empty catalog, tag appended. 8 tests.
- **Verification:** full suite **228 passed** (792 assertions); pint clean, phpstan 0 errors. Frontend untouched.

### File List

**New:** `app/Services/Promo/{Promo,PromoProvider,AffiliatePromoProvider}.php`, `tests/Feature/Promo/AffiliatePromoProviderTest.php`
**Modified:** `config/tripcast.php` (`promo` block), `app/Providers/AppServiceProvider.php` (bind)

### Change Log

- 2026-06-30 — Implemented Story 5.2: a `PromoProvider` port + `AffiliatePromoProvider` adapter over an editable, placeholder weather-keyed catalog. Pure selection — maps the secured snapshot to a profile and rotates deterministically by send_date (generic fallback, tagged Amazon URLs), ready for the digest slot (5.3) and click attribution (5.4) to consume.
