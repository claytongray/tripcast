# Project Context — AI rules & conventions

Auto-loaded by BMad `create-story`/`dev-story` (via `persistent_facts: **/project-context.md`).
Carry these as facts when planning or implementing. Distilled from Epic 7's retrospective.

## Verification gates (run before marking any story done)

`php artisan test --compact` · `vendor/bin/pint --dirty --format agent` · `./vendor/bin/phpstan analyse` · `npm run types:check` · `npm run lint:check` · `npm run build:ssr`

- **Green gates ≠ correct.** Passing tests + phpstan caught none of Epic 7's 5 review bugs (a metric that counted the wrong rows, an unregistered chart plugin, a chart fed the wrong series). Reason about behavior and cross-section consistency, not just gate output.
- **Tests ≠ environment.** `RefreshDatabase` rebuilds the test DB, so a green suite can hide a pending migration on the dev DB (this 500'd `/admin/overview`). **Run `php artisan migrate` on the dev DB when pulling work that adds tables.**

## PHPStan (larastan) gotchas — recurred across Epic 7

- **Dynamic aggregate attributes** from `withCount`/`withMax`/`withExists`/`selectRaw('... as alias')` are NOT declared model properties. Read them with `$model->getAttribute('alias')` (legitimately `mixed`), not `$model->alias`. Narrow before use (e.g. `is_string($x) ? ... : null`).
- **Raw SQL wants `literal-string`.** `selectRaw`/`groupByRaw` reject interpolated column names. Either keep the string a pure literal, or constrain the column param to a **union of string literals** in `@param` (e.g. `@param 'send_date' $col`) — PHPStan preserves `literal-string` through interpolation of literal-typed vars, and it's provably injection-safe.
- **`->map()->all()` / `->values()->all()` infers `array<int,…>`, not `list<…>`.** If a method's return type is `list<…>`, build the array with a `foreach` (which infers a genuine list).

## Test-writing gotchas

- **Whole-number floats serialize to int.** PHP `json_encode(50.0)` → `50`, which decodes as `int`, so a strict Inertia `->where('x', 50.0)` fails. Assert with a numeric closure: `->where('x', fn ($v) => (float) $v === 50.0)`.
- **Fluent `assertInertia` closures receive Collections, not arrays.** Use `collect($d)->sum()`, not `array_sum($d)`.
- **Pin time** with `$this->travelTo('YYYY-MM-DD HH:MM:SS')` for anything window/date-based. Note the app send clock is **America/New_York** (AD-7) while `config('app.timezone')` is **UTC** — cadence/projection use NY, metric bucketing uses UTC.

## Admin panel conventions (Epic 7)

- **Single Gate for everything.** All `/admin/*` sit in one `Route::middleware(['auth','can:admin'])->prefix('admin')` group (AD-12) — no second gate/policy. Epic 7 sections are read-only; Epic 8 added the first *mutating* surface (`promo-items` CRUD), registered **inside the same group** so all six verbs incl. writes inherit the one Gate (a `FormRequest::authorize()` re-checks `is_admin` as defense-in-depth). Non-admins get 403 on writes too, not just GET.
- **Section pattern:** a `App\Services\Metrics\*` builder returns an array payload; the controller resolves the 7/30/90 window (invalid → default 30) and renders `Admin/*`. Reuse `MetricsService` (windows, zero-filled daily series, tile deltas), `KpiTile`/`TrendChart`, and `WindowSwitcher`.
- **Cross-section consistency:** when two sections compute the same metric (e.g. send success rate = `sent/(sent+failed)`), they must use the same formula — assert it (see `tests/Feature/Admin/CrossSectionConsistencyTest.php`).
- **Reuse authorities, don't fork them:** cadence via `CadencePredicate` (AD-11), send health via `email_logs` (AD-9), promo profile via the promo catalog. Extend the authority (add a method) rather than reimplement its logic.

## Epic 8 catalog switchover & determinism (FR-26, AD-18)

- **`mild` is neutral/legacy.** `WeatherProfiler` never emits `mild` — neutral weather and `<2`-usable-day (early/low-signal) sends both route to the **Essentials** pool (`travel-essentials`). The CRUD (8.3) omits `mild` from *new*-item options; `mild` stays a valid model key only for legacy rows. The `PromoItemSeeder` re-buckets the config `mild` item (`packing-cubes`) into `travel-essentials` (8.4) so no seeded item is unreachable.
- **One-time selection shift (accepted).** Switching from the config `AffiliatePromoProvider` to `DatabasePromoProvider`: `mild`/early sends move from their old config slug to a `travel-essentials` slug, and the Essentials pool grew 2→3 items, so its `crc32(send_date) % count` rotation index shifted once. This is the intended, documented switchover shift.
- **Placeholders are demo/fallback only.** `config('tripcast.promo.catalog')` is all stub data (`placehold.co` / `B000PLACEHOLDER*`). It stays as a dev/demo fallback (and the empty-`promo_items`-table safety net). Production adds real products via the `/admin/promo-items` CRUD; seeded placeholders are deleted or never seeded. Retiring `config.catalog` is gated behind Story 8.5's analytics repoint + a bake period.
- **Determinism is per fixed-catalog-state.** The rotation tiebreaker is the stable unique `slug`, never `id` (not reseed-stable). `sort_order` is admin-editable (8.3 form). A retroactive edit to Featured windows / `is_active` / `sort_order` / a soft-delete can change a *future* send's pick, but already-sent dates are settled — `promo_events` already logged the shown slug, and `findBySlug(withTrashed)` keeps that click link resolving. Shifting an already-logged date's selection is an accepted/known tradeoff.
