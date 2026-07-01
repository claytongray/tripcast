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

- **Read-only + single Gate.** All `/admin/*` sit in one `Route::middleware(['auth','can:admin'])->prefix('admin')` group (AD-12). No mutations, no second gate/policy.
- **Section pattern:** a `App\Services\Metrics\*` builder returns an array payload; the controller resolves the 7/30/90 window (invalid → default 30) and renders `Admin/*`. Reuse `MetricsService` (windows, zero-filled daily series, tile deltas), `KpiTile`/`TrendChart`, and `WindowSwitcher`.
- **Cross-section consistency:** when two sections compute the same metric (e.g. send success rate = `sent/(sent+failed)`), they must use the same formula — assert it (see `tests/Feature/Admin/CrossSectionConsistencyTest.php`).
- **Reuse authorities, don't fork them:** cadence via `CadencePredicate` (AD-11), send health via `email_logs` (AD-9), promo profile via the promo catalog. Extend the authority (add a method) rather than reimplement its logic.
