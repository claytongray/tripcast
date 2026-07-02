---
baseline_commit: 8eca050
---

# Story 8.3: Catalog CRUD UI (`/admin/promo-items`)

Status: review

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

As the builder,
I want to create, edit, and retire catalog items from the admin panel,
so that I can manage sponsored products without a code change or a deploy.

## Acceptance Criteria

**AC1 — Resourceful controller behind the single admin Gate + shared validation** *(FR-26, AD-12)*
- **Given** a resourceful `promo-items` controller registered **inside** the existing `Route::middleware(['auth','can:admin'])->prefix('admin')` group
- **When** any of index/create/store/edit/update/destroy is requested
- **Then** the group Gate guards **all six verbs incl. writes** (guests → login, authed non-admins → **403 on POST/PUT/PATCH/DELETE too**, not just GET), a shared `PromoItemRequest` (which **also re-checks `is_admin`** in `authorize()`) validates `slug` (unique, ignoring self), `label`, `image_url` (`url:https`), `url` (`url:http,https`), `merchant` (`Rule::in(PromoItem::MERCHANTS)`), `weather_profile` (`Rule::in(PromoItem::PROFILES)`), `is_active` (boolean), `sort_order` (integer), and the Featured window (`featured_from` nullable date, `featured_to` nullable/open-ended, `after_or_equal:featured_from`), and success redirects to the index with a calm `flash.status`.

**AC2 — `slug` is the immutable attribution key (set-once)** *(FR-26, AD-18)*
- **Given** `slug` is the attribution key that `promo_events.promo_slug` joins against
- **When** an item is edited
- **Then** the slug field is **set-once** — rendered **disabled** on the edit form and `update()` persists `->except('slug')` so a stray posted slug can never re-point historical `promo_events`; the unique-slug validation message **hints when the collision is a soft-deleted item** and offers a restore path rather than pushing the admin toward force-delete.

**AC3 — Retirement semantics: deactivate (reversible) vs soft-delete** *(FR-26, AD-18)*
- **Given** retirement
- **When** an admin deactivates or deletes an item
- **Then** `is_active=false` is the **reversible** toggle (edit form or a quick action) and `destroy()` performs a **soft-delete** (the row leaves the index list but still resolves via `findBySlug` `withTrashed()` for live click links — see 8.2); the UI **never force-deletes**.

**AC4 — Phone-first pages + Catalog tab + cross-link** *(FR-26, AD-12)*
- **Given** phone-first navigation
- **When** the panel renders
- **Then** `Admin/Catalog/Index` (read-only projected list) and `Admin/Catalog/Form` (shared create/edit `useForm`) render under `AdminLayout` with a new **Catalog** tab, and a "Manage catalog →" cross-link is added from the read-only `Admin/Promos` analytics page; for `other` merchants the `url`/`image_url` are stored **verbatim** with a scheme sanity check (`url:https` on image, `url:http,https` on link). `mild` is **omitted from the new-item `weather_profile` options** (existing `mild` rows stay editable/deactivatable, never newly created — see Epic 8 header binding note).

## Tasks / Subtasks

- [x] **Task 1 — Route registration (resourceful, inside the admin group)** (AC: 1)
  - [x] In `routes/web.php`, inside the existing `Route::middleware(['auth','can:admin'])->prefix('admin')->group(...)` (currently lines 58–69), register `Route::resource('promo-items', PromoItemController::class)->except(['show'])->names('admin.promo-items');` — passing a **string** to `names()` prefixes all six names → `admin.promo-items.{index,create,store,edit,update,destroy}`. It inherits the group's `auth` + `can:admin`, so writes are Gate-guarded with no second policy (AD-12).
  - [x] Import `use App\Http\Controllers\PromoItemController;` at the top (alongside the other controller imports).
  - [x] Run `php artisan wayfinder:generate` (or `npm run build`) so `@/actions/App/Http/Controllers/PromoItemController` and `@/routes/admin/promo-items` exist for the Vue pages.
- [x] **Task 2 — `PromoItemController` (resourceful, dedicated)** (AC: 1, 2, 3, 4)
  - [x] Create `app/Http/Controllers/PromoItemController.php` (a **new** controller, not a method on `AdminController` — this is the first *mutating* admin surface and is resourceful).
  - [x] `index()`: render `Admin/Catalog/Index` with a projected list of **non-trashed** items ordered `(weather_profile asc, sort_order asc, slug asc)` (grouping-by-profile is the FR-26 intent; a flat ordered table is acceptable). Project only display columns (`id, slug, label, image_url, url, merchant, weather_profile, is_active, featured_from, featured_to, sort_order`). **No** impressions/clicks/CTR here — that is Story 8.5. Read-only over the list.
  - [x] `create()`: render `Admin/Catalog/Form` with `item => null`, `merchants`, and `profiles` (the **selectable** list = `PromoItem::PROFILES` **minus** `mild`).
  - [x] `store(PromoItemRequest $request)`: `PromoItem::create($request->validated())`; redirect `->route('admin.promo-items.index')->with('status', 'Catalog item added.')`.
  - [x] `edit(PromoItem $promoItem)`: render `Admin/Catalog/Form` with the item, `merchants`, and `profiles` — for edit, pass the **full** `PROFILES` (so a legacy `mild` row shows its current value) but flag `slugLocked: true`.
  - [x] `update(PromoItemRequest $request, PromoItem $promoItem)`: `$promoItem->update($request->validated()->except('slug'))` — or in the request expose `validatedExceptSlug()`; **slug is never updated** (AC2). Redirect to index with `->with('status', 'Catalog item saved.')`.
  - [x] `destroy(PromoItem $promoItem)`: `$promoItem->delete()` (soft-delete via the model's `SoftDeletes`); redirect to index with `->with('status', 'Catalog item retired.')`. **No force delete.**
  - [x] Route-model binding: default `{promo_item}` binds by `id`. Keep it — slug is display-only, not the URL key.
- [x] **Task 3 — `PromoItemRequest` (shared store/update)** (AC: 1, 2)
  - [x] `php artisan make:request PromoItemRequest`. `authorize()` returns `$this->user()?->can('admin') ?? false` (defense-in-depth re-check on top of the group Gate).
  - [x] `rules()`:
    - `slug` → `['required','string','max:255', Rule::unique('promo_items','slug')->ignore($this->route('promo_item'))]`. **Note:** `Rule::unique` queries the table directly (it does **not** apply the SoftDeletes global scope), so uniqueness naturally spans soft-deleted rows — matching AC2's "collision is a soft-deleted item" case. On update, `ignore(...)` accepts the bound `PromoItem` (or its id).
    - `label` → `['required','string','max:255']`
    - `image_url` → `['required','string','url:https','max:2048']`
    - `url` → `['required','string','url:http,https','max:2048']`
    - `merchant` → `['required', Rule::in(PromoItem::MERCHANTS)]`
    - `weather_profile` → `['required', Rule::in(PromoItem::PROFILES)]` — **validation allows the full taxonomy incl. `mild`** so editing a legacy `mild` row doesn't fail; the **create form UI** is what omits `mild` from the options (AC4). This reconciles AC1 (`Rule::in(PROFILES)`) with the Epic 8 header "no *new* mild items" rule.
    - `is_active` → `['required','boolean']`
    - `sort_order` → `['required','integer','min:0']`
    - `featured_from` → `['nullable','date_format:Y-m-d']`
    - `featured_to` → `['nullable','date_format:Y-m-d','after_or_equal:featured_from']`
  - [x] `messages()`: add the soft-delete-collision hint for `slug.unique` — e.g. `"That slug is already used (it may belong to a retired item — restore that item instead of creating a new one)."` Keep the voice calm (EXPERIENCE.md).
  - [x] `prepareForValidation()`: coerce the checkbox `is_active` to a real boolean if the form posts `"0"/"1"` (mirror how the Vue `useForm` sends it).
- [x] **Task 4 — `Admin/Catalog/Index.vue` (read-only list)** (AC: 3, 4)
  - [x] Create `resources/js/pages/Admin/Catalog/Index.vue`. Auto-gets `AdminLayout` (the `Admin/` prefix rule in `resources/js/app.ts:20`). `<Head title="Admin — catalog" />`.
  - [x] Props: `items: PromoItemRow[]`, `profiles: string[]`, `merchants: string[]` (types inline, matching the other admin pages' style).
  - [x] Phone-first table (scrolls at ~360px like `Admin/Promos`): columns label · slug · profile · merchant · Featured window · active pill · sort_order · actions (Edit link, Retire). Group visually by `weather_profile` (headers) or a flat ordered table — either satisfies AC4.
  - [x] A primary "Add item" link → `create()` route. Each row: "Edit" `<Link>` to `edit(item.id)`, and a "Retire" action that `router.delete(destroy(item.id).url, { preserveScroll: true })` with a calm confirm (no browser `confirm()` dialog if it risks the harness — use an inline confirm affordance; a native `window.confirm` is acceptable here since it's the admin, but prefer an inline pattern consistent with the dashboard delete).
  - [x] Surface `flash.status` via the existing toast pipeline (`initializeFlashToast()` in `app.ts` already listens — a redirect `->with('status', ...)` shows a toast automatically; no page-level code needed beyond confirming it fires).
- [x] **Task 5 — `Admin/Catalog/Form.vue` (shared create/edit `useForm`)** (AC: 1, 2, 4)
  - [x] Create `resources/js/pages/Admin/Catalog/Form.vue`. Auto-`AdminLayout`. `<Head :title="item ? 'Admin — edit item' : 'Admin — new item'" />`.
  - [x] Props: `item: PromoItemRow | null`, `merchants: string[]`, `profiles: string[]`, and (for edit) `slugLocked: boolean`.
  - [x] `useForm({...})` seeded from `item` (or blank defaults: `merchant: 'amazon'`, `is_active: true`, `sort_order: 0`, `weather_profile: profiles[0]`, empty featured window).
  - [x] `slug` input is **disabled** when editing (`:disabled="slugLocked"`); the field still displays the value but the server ignores it (AC2). On create it is editable.
  - [x] `weather_profile` `<select>` binds to the `profiles` prop — on create this **excludes `mild`**; on edit it includes the item's current value even if `mild`.
  - [x] Submit: `form.post(store().url)` on create, `form.put(update(item.id).url)` on edit. Show inline field errors from `form.errors` in `ink-secondary` (UX-DR14 — no red-fill drama), matching the landing/dashboard form styling. Buttons use the shadcn `Button` primitive (see `Settings.vue`).
  - [x] Featured window: two date inputs (`featured_from`, `featured_to`), both optional; helper copy explaining open-ended (`featured_to` blank = pinned indefinitely).
  - [x] Merchant helper: note that `amazon` links get the associate tag appended at send (8.2), `other` links are used verbatim.
- [x] **Task 6 — AdminLayout Catalog tab + Promos cross-link** (AC: 4)
  - [x] In `resources/js/layouts/AdminLayout.vue`: import the catalog index route (`import { index as catalogIndex } from '@/routes/admin/promo-items';`) and add `{ label: 'Catalog', href: catalogIndex(), path: '/admin/promo-items' }` to the `tabs` array (after `Promos` is the natural slot; it's the mutating sibling of the read-only Promos analytics).
  - [x] In `resources/js/pages/Admin/Promos.vue`: add a "Manage catalog →" `<Link>` to `catalogIndex()` near the header (a quiet `text-brand` link), cross-linking analytics → management.
- [x] **Task 7 — Tests** (AC: 1, 2, 3, 4)
  - [x] Create `tests/Feature/Admin/PromoItemCrudTest.php` (Pest, `RefreshDatabase`). Use `PromoItem::factory()` states (`forProfile`, `essentials`, `other`, `featured`, `inactive`, `trashed`) and a `User::factory()` admin (`is_admin => true`) vs non-admin.
    - **Gate on every verb:** a guest hitting `index`, `create`, `store` (POST), `update` (PUT), `destroy` (DELETE) → redirect to `login`; an authed **non-admin** hitting each of the six → **403** (assert POST/PUT/PATCH/DELETE, not just GET — this is the cross-cutting AC).
    - **store happy path:** admin POSTs a valid Amazon item → row created, redirect to `admin.promo-items.index`, `session('status')` set.
    - **validation:** `image_url` non-https → error; `url` bad scheme → error; `weather_profile` outside `PROFILES` → error; `merchant` outside `MERCHANTS` → error; `featured_to` before `featured_from` → error.
    - **slug uniqueness spans soft-deleted:** create item, soft-delete it, then a new create with the same slug → `slug` validation error (and the hint message present).
    - **slug set-once:** edit an existing item POSTing a **different** slug → after `update`, the row's slug is **unchanged** (AC2).
    - **destroy = soft delete:** admin DELETEs → `assertSoftDeleted('promo_items', ...)`, row absent from the `index` projection, but `PromoItem::withTrashed()->find(...)` still resolves (the 8.2 click path).
    - **is_active toggle:** update with `is_active=false` → row deactivated, reversible by a second update to true.
    - **Inertia render:** `index` renders `Admin/Catalog/Index` with the projected `items`; `create`/`edit` render `Admin/Catalog/Form` with `profiles` excluding/including `mild` respectively (assert `mild` absent from create `profiles`, present on edit of a `mild` row).
  - [x] **Check `tests/Feature/Admin/AdminShellTest.php`** — it drives a dataset of `[routeName, component]` GET sections for the guest-redirect / non-admin-403 / admin-OK sweep. Add the catalog `index` (`admin.promo-items.index` → `Admin/Catalog/Index`) to that coverage (or rely on the dedicated CRUD test; do **not** leave the new GET section un-gated in the shell sweep). The write verbs are covered by the dedicated test above.
  - [x] **Gates:** `php artisan test --compact`, `vendor/bin/pint --dirty --format agent`, `./vendor/bin/phpstan analyse`, `npm run types:check`, `npm run lint:check`, `npm run build:ssr`. **Run `php artisan wayfinder:generate` before `types:check`** so the new route/action modules resolve.

## Dev Notes

### Scope boundary (read first)
- **CRUD UI only.** This is the **first mutating** admin surface. It adds a resourceful controller, a shared FormRequest, and two Vue pages behind the existing single admin Gate. It does **not** add per-item analytics (impressions/clicks/CTR) — that is **Story 8.5**. It does **not** change selection precedence, the provider, `promo_events`, or the migration/model (8.1/8.2 own those). No new migration; `promo_items` already exists (8.1).

### Architecture (binding)
- **AD-12 — single admin Gate, no second policy.** Register the resource route **inside** the existing `['auth','can:admin']->prefix('admin')` group so all six verbs (incl. writes) inherit it. Guests → login, authed non-admins → 403 on **every** verb. The `PromoItemRequest::authorize()` re-checks `is_admin` as defense-in-depth, but there is **no** `PromoItemPolicy` and no allowlist. [Source: routes/web.php:58-69; app/Providers/AppServiceProvider.php:87 `Gate::define('admin', ...)`]
- **AD-18 — `slug` is the immutable attribution key.** `promo_events.promo_slug` joins on `promo_items.slug` (see `PromoItem::promoEvents()` at model:154). Editing must never re-point it → slug is set-once (disabled on edit + `->except('slug')` on update). Retirement is soft-delete (or `is_active=false`), never force-delete, so the 8.2 `findBySlug(withTrashed)` click path keeps resolving. [Source: app/Models/PromoItem.php:147-157; 8-2 story AC3]
- **FR-26 / `mild` → Essentials (binding, 2026-07-01).** `mild` is a **neutral/legacy** key: `WeatherProfiler` never emits it (8.2), so a `mild` item is not weather-selectable. Therefore the **create** form omits `mild` from the profile options; existing `mild` rows stay editable/deactivatable (validation still allows `PROFILES`). Do **not** delete or re-bucket `mild` rows here — the switchover re-bucket was 8.2's job. [Source: epics.md Epic 8 header "`mild` → Essentials"; Story 8.4 AC2]

### Code intel (exact)
- **Admin route group:** `routes/web.php:58` — `Route::middleware(['auth','can:admin'])->prefix('admin')->group(...)`. Existing sections are simple GETs named `admin.overview`/`admin.users`/… on `AdminController`. Add the resource here. [Source: routes/web.php:58-69]
- **Layout resolver:** `resources/js/app.ts:20` — any page whose name `startsWith('Admin/')` gets `AdminLayout`. So `Admin/Catalog/Index` and `Admin/Catalog/Form` auto-wrap; **no** `defineOptions`/persistent-layout code needed (matches the other admin pages, which set none). [Source: resources/js/app.ts:12-25]
- **Tab strip:** `resources/js/layouts/AdminLayout.vue:10-17` — a `tabs` array of `{ label, href, path }` using Wayfinder route helpers from `@/routes/admin`. Add `Catalog`. `isActive` uses `currentPath.startsWith(path)` so `/admin/promo-items/{id}/edit` still highlights the Catalog tab. [Source: AdminLayout.vue:10-23]
- **Flash → toast:** `resources/js/app.ts:35` calls `initializeFlashToast()`; a redirect `->with('status', '…')` surfaces a toast automatically (see `SettingsController::update` → `back()->with('status', …)` and `TripController` pause/resume/destroy). Redirect to the index with `->with('status', …)`. [Source: app.ts:35; SettingsController.php:41; TripController.php:83-108]
- **`useForm` precedent:** `Dashboard.vue`, `Landing.vue`, `TripDetail.vue`, the `auth/*` pages all use `@inertiajs/vue3` `useForm` / `router` with inline `form.errors` in `ink-secondary`. Mirror that; use the shadcn `Button` primitive (`Settings.vue:107`). [Source: resources/js/pages/Dashboard.vue; Settings.vue]
- **`PromoItem` model (8.1):** `MERCHANTS`, `PROFILES` (incl. `mild`), `$fillable` already lists exactly the admin-managed columns (`slug,label,image_url,url,merchant,weather_profile,is_active,featured_from,featured_to,sort_order`), casts, SoftDeletes, `scopeActive/scopeForProfile/scopeFeaturedOn`, `promoEvents()`. `create($request->validated())` is safe (fillable-scoped). [Source: app/Models/PromoItem.php:44-96]
- **Factory states (8.1):** `forProfile($p)`, `essentials()`, `other($url)`, `featured($from,$to)`, `inactive()`, `trashed()`. Use these in the CRUD test. [Source: database/factories/PromoItemFactory.php]
- **`config('tripcast.promo.catalog')`:** the legacy config catalog still exists (8.2 empty-table fallback). This story does **not** touch it; 8.5 handles the analytics repoint + eventual retirement. [Source: config/tripcast.php:151+]

### `->except('slug')` on update — the exact shape
- `PromoItemRequest::validated()` returns an array. In `update()`: `$promoItem->update(collect($request->validated())->except('slug')->all());` — or add a request helper `validatedForUpdate(): array`. Either way the posted slug is dropped server-side, so a disabled field that gets re-enabled via devtools still cannot re-point attribution (AC2 belt-and-suspenders).

### PHPStan / testing gotchas (carry-forward from Epic 7)
- **Fluent `assertInertia` closures receive Collections, not arrays** — use `collect($items)->...`, not array functions. [Source: project-context.md]
- **Whole-number floats serialize to int** — not relevant here (no float metrics in 8.3; that's 8.5), but keep in mind if you preview counts.
- **`Rule::in(...)` with `PromoItem::PROFILES`/`MERCHANTS`** — these are `list<string>` consts; `Rule::in` accepts them directly, no PHPStan noise.
- **Pending-migration trap:** `promo_items` shipped in 8.1 — if the **dev** DB predates it, run `php artisan migrate` (the test DB is fine under `RefreshDatabase`). [Source: project-context.md "Tests ≠ environment"]

### Project Structure Notes
- **New:** `app/Http/Controllers/PromoItemController.php`, `app/Http/Requests/PromoItemRequest.php`, `resources/js/pages/Admin/Catalog/Index.vue`, `resources/js/pages/Admin/Catalog/Form.vue`, `tests/Feature/Admin/PromoItemCrudTest.php`.
- **Modified:** `routes/web.php` (resource route + import), `resources/js/layouts/AdminLayout.vue` (Catalog tab), `resources/js/pages/Admin/Promos.vue` ("Manage catalog →" cross-link), `tests/Feature/Admin/AdminShellTest.php` (catalog GET section in the gate sweep). Wayfinder output (`resources/js/routes/admin/promo-items.*`, `resources/js/actions/App/Http/Controllers/PromoItemController.*`) is **generated**, not hand-edited.
- **Unchanged:** `PromoItem` model, `PromoItemFactory`, `PromoItemSeeder`, the migration, `DatabasePromoProvider`/`AffiliatePromoProvider`/`WeatherProfiler` (8.2), `promo_events`, `PromoAnalytics` (7.6 — 8.5 repoints it), `AdminController` (the CRUD lives in its own resourceful controller).

### Previous story intelligence (8.1 / 8.2)
- 8.1 built `promo_items` + model + fidelity seeder + indexes `(is_active, weather_profile, sort_order)` and `(is_active, featured_from, featured_to)` — the index list projection and Featured lookups are already covered.
- 8.2 made the DB catalog live behind the port with `findBySlug(withTrashed())` for the click path — **this is why retirement here is soft-delete, never force-delete.** The `mild`→Essentials decision and the "no new mild items" rule come straight from 8.2's Dev Notes / the Epic 8 header. [Source: 8-1-*.md; 8-2-*.md]
- Full suite was **388 passing** after 8.2. Expect this story to add a CRUD test (~10–14 cases) and keep everything green.

### References
- [Source: _bmad-output/planning-artifacts/epics.md#Story-8.3 + Epic 8 "Cross-cutting ACs" + "`mild` → Essentials" header notes]
- [Source: routes/web.php:58-69; app/Providers/AppServiceProvider.php:87]
- [Source: app/Models/PromoItem.php; database/factories/PromoItemFactory.php]
- [Source: resources/js/app.ts; resources/js/layouts/AdminLayout.vue; resources/js/pages/Admin/Promos.vue; resources/js/pages/Settings.vue]
- [Source: app/Http/Controllers/SettingsController.php:41; app/Http/Controllers/TripController.php:33-108 (redirect+flash pattern)]
- [Source: _bmad-output/planning-artifacts/project-context.md (gates, admin conventions, gotchas)]

## Dev Agent Record

### Agent Model Used

claude-opus-4-8 (1M context)

### Debug Log References

- **`featured_from`/`featured_to` date-cast serialization.** The model casts these as `date`, which serialize to full ISO8601 strings — unusable for both the `<input type="date">` (needs `Y-m-d`) and the list display. Added a private `toArray()` in the controller that formats them via `?->format('Y-m-d')` for both `index` and `edit` payloads.
- **Route param name.** The resource param is `{promo_item}` (snake_case, from `promo-items`); the controller methods type-hint `PromoItem $promoItem` — Laravel's implicit binding snake_cases the variable name to match, so binding resolves without a custom `->parameters()` map.
- **Pre-existing flaky test discovered (8.2).** `DatabasePromoProviderTest` "honors an open-ended Featured window" failed intermittently in the full-suite run (~1 in 3). Root cause: the `featured()` factory state leaves `weather_profile` random, so the lapsed "expired" item could randomly become `travel-essentials` and legitimately join the Essentials pool, letting the crc32 rotation pick it over "ess". Fixed by pinning that item to `PROFILE_SNOW` (`->forProfile(...)`) so it can only reach the slot via the (expired) Featured window — preserving the test's intent and making it deterministic. Verified stable across 5 consecutive runs.

### Completion Notes List

- **Resourceful `PromoItemController`** registered inside the existing `['auth','can:admin']->prefix('admin')` group via `Route::resource(...)->except(['show'])->names('admin.promo-items')` — the single admin Gate (AD-12) now guards all six verbs incl. writes; the CRUD test asserts guest→login and non-admin→403 on **every** verb (GET *and* POST/PUT/DELETE), and that rejected writes leave no trace.
- **`slug` is set-once (AD-18):** disabled on the edit form + `PromoItemRequest::validatedExceptSlug()` drops any posted slug in `update()`, so a re-enabled field still can't re-point historical `promo_events`. `Rule::unique('promo_items','slug')` queries the table directly (no SoftDeletes scope), so uniqueness spans retired rows; the collision message nudges toward restore, not force-delete.
- **Retirement = reversible `is_active` toggle or soft-delete**, never force-delete — the row leaves the index but 8.2's `findBySlug(withTrashed)` click path still resolves it (asserted in the test).
- **`mild` reconciliation (FR-26):** validation allows the full `PROFILES` (a legacy `mild` row stays editable), but the **create** form's profile options omit `mild` via a controller `selectableProfiles()` helper; **edit** offers the full taxonomy so a `mild` row shows its current value. Both cases are asserted.
- **Phone-first Vue:** `Admin/Catalog/Index` (scrollable table, calm retire-confirm Dialog mirroring the dashboard delete) and `Admin/Catalog/Form` (shared create/edit `useForm`, inline `InputError`, shadcn `Button`/`Input`/`Label`). New **Catalog** tab in `AdminLayout` (after Promos) + a "Manage catalog →" cross-link from the read-only Promos analytics page. Flash `status` surfaces via the existing `initializeFlashToast()` pipeline.
- **Scope held:** no per-item analytics (that's 8.5), no provider/model/migration changes, `promo_events` untouched.
- **Verification:** full suite **415 passed / 1735 assertions** (+27 new CRUD cases +1 catalog section in the shell sweep; the fixed 8.2 flake included). `pint` clean, `phpstan` 0 errors, `types:check` clean, `lint:check` clean, `build:ssr` OK.

### File List

**New:**
- `app/Http/Controllers/PromoItemController.php`
- `app/Http/Requests/PromoItemRequest.php`
- `resources/js/pages/Admin/Catalog/Index.vue`
- `resources/js/pages/Admin/Catalog/Form.vue`
- `tests/Feature/Admin/PromoItemCrudTest.php`

**Modified:**
- `routes/web.php` (resource route + `PromoItemController` import)
- `resources/js/layouts/AdminLayout.vue` (Catalog tab)
- `resources/js/pages/Admin/Promos.vue` ("Manage catalog →" cross-link)
- `tests/Feature/Admin/AdminShellTest.php` (catalog GET section in the gate sweep)
- `tests/Feature/Promo/DatabasePromoProviderTest.php` (deterministic fix for a pre-existing flaky Featured-window case)

**Generated (Wayfinder, not hand-edited):**
- `resources/js/actions/App/Http/Controllers/PromoItemController.ts`
- `resources/js/routes/admin/promo-items/*`

**Unchanged:** `PromoItem` model, `PromoItemFactory`, `PromoItemSeeder`, the migration, `DatabasePromoProvider`/`AffiliatePromoProvider`/`WeatherProfiler`, `promo_events`, `PromoAnalytics`, `AdminController`.

### Change Log

- 2026-07-01 — Implemented Story 8.3: Catalog CRUD UI. Added a resourceful `PromoItemController` + shared `PromoItemRequest` inside the single admin Gate group (all six verbs guarded, AD-12), with set-once `slug` (AD-18), soft-delete retirement, and `mild` omitted from new-item options (FR-26). Added phone-first `Admin/Catalog/Index` + `Admin/Catalog/Form` Vue pages, a Catalog tab, and a Promos→catalog cross-link. Fixed a pre-existing flaky 8.2 provider test found during the full-suite run. All gates green (415 tests).
