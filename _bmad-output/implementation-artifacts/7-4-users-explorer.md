---
baseline_commit: 26251b5
---

# Story 7.4: Users explorer (read-only)

Status: review

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

As the builder,
I want to browse and search all users with their activity,
so that I can understand who is signing up and engaging.

## Acceptance Criteria

**AC1 — `/admin/users` is a paginated, searchable, read-only list with per-user activity (no N+1)** *(FR-23)*
- **Given** an admin opens `/admin/users`
- **When** it renders
- **Then** a **paginated**, **searchable** list shows each user's **email**, **plan**, **confirmed?**, **created date**, **active-trip count**, **last login** (`max(login_tokens.consumed_at)`), and **sample-requested?** — with the counts/aggregates **eager-loaded (no N+1)** — and the view is **strictly read-only** (no mutations).

**AC2 — Phone-first, guarded** *(FR-23, Epic-7 cross-cutting)*
- **Given** the users explorer
- **When** viewed on a phone / by a non-admin
- **Then** the table stays usable at mobile width (horizontal scroll), search + pagination preserve each other across requests, and the route stays behind the `admin` Gate (guest → login, non-admin → 403).

## Tasks / Subtasks

- [x] **Task 1 — Add the `sampleRequests` relation to `User`** (AC: 1)
  - [x] Add `public function sampleRequests(): HasMany` returning `$this->hasMany(SampleRequest::class)` (mirrors the existing `trips()`/`loginTokens()` HasMany; `SampleRequest` already has the inverse `user()` BelongsTo). Import `SampleRequest`. Add the `@return HasMany<SampleRequest, $this>` docblock to match the existing relation style.
- [x] **Task 2 — Wire `AdminController@users`** (AC: 1, 2)
  - [x] Replace the placeholder body. Read `search` from `?search=` (trim; treat empty as no filter). Build one query:
    - `User::query()`
    - `->when($search !== '', fn ($q) => $q->where('email', 'like', '%'.$search.'%'))`
    - `->withCount(['trips as active_trips_count' => fn ($q) => $q->where('status', Trip::STATUS_ACTIVE)])`
    - `->withMax('loginTokens as last_login_at', 'consumed_at')`
    - `->withExists('sampleRequests as has_sample_request')`
    - `->orderByDesc('id')`
    - `->paginate(self::USERS_PER_PAGE)->withQueryString()` (add `private const USERS_PER_PAGE = 25;`).
  - [x] Project each row with `->through(fn (User $user) => [...])` (preserves pagination): `id`, `email`, `plan`, `confirmed` = `$user->isConfirmed()`, `created_at` = `$user->created_at->toDateString()`, `active_trips_count` = `(int) $user->active_trips_count`, `last_login_at` = `$user->last_login_at ? CarbonImmutable::parse($user->last_login_at)->toDateString() : null` (a `withMax` alias comes back as a **raw string, not cast**), `has_sample_request` = `(bool) $user->has_sample_request`.
  - [x] `Inertia::render('Admin/Users', ['users' => $users, 'filters' => ['search' => $search]])`. Read-only; thin; no writes.
- [x] **Task 3 — Build `Admin/Users.vue`** (AC: 1, 2)
  - [x] Replace the placeholder. Typed props: `users: Paginated<AdminUserRow>`, `filters: { search: string }`.
  - [x] **Search:** a text input seeded from `filters.search`; on input, **debounced** (~300ms) `router.get(admin.users.url(), { search }, { preserveState: true, preserveScroll: true, replace: true })` (manual debounce via a timeout ref — no vueuse/lodash in the project). Clearing the box reloads unfiltered.
  - [x] **Table:** a semantic `<table>` (no shadcn Table component) wrapped in `overflow-x-auto` for phones. Columns: Email, Plan, Confirmed (✓/—), Created, Active trips, Last login (date or "—"), Sample? (✓/—). Reuse `Admin/*` token classes and a status-pill style for `plan`/`confirmed` if it reads well. Empty state ("No users match.").
  - [x] **Pagination:** render the paginator `links` (`{ url, label, active }`) as `<Link>`s (disabled when `url === null`), preserving scroll/state. Show a small "showing from–to of total" line.
  - [x] Single root element; strictly read-only (no forms that mutate, no action buttons).
- [x] **Task 4 — TS contracts** (AC: 1)
  - [x] Add `resources/js/types/pagination.ts` — a generic `Paginated<T>` matching Laravel's paginator (`data: T[]`, `links: PaginationLink[]`, `current_page`, `last_page`, `per_page`, `total`, `from`, `to`, `prev_page_url`, `next_page_url`) with `PaginationLink = { url: string | null; label: string; active: boolean }`. (Reusable by later sections.)
  - [x] Add the `AdminUserRow` type (inline in `Users.vue` or a small module): `{ id, email, plan, confirmed, created_at, active_trips_count, last_login_at, has_sample_request }`.
- [x] **Task 5 — Tests** (AC: 1, 2)
  - [x] Create `tests/Feature/Admin/UsersExplorerTest.php` (Pest, `RefreshDatabase`). Seed users with varied activity: some confirmed / unconfirmed, some with active + non-active trips, some with consumed login tokens (varied `consumed_at`), some with sample requests. Assert as admin:
    - `component('Admin/Users')`; `users.data` rows carry `email`, `plan`, `confirmed`, `created_at`, `active_trips_count` (only **active** trips counted, trashed excluded), `last_login_at` (= max consumed_at date, `null` when never logged in), `has_sample_request`.
    - **Search:** `?search=` filters by email substring (case-insensitive), and `withQueryString` keeps it on page 2.
    - **Pagination:** seed > `USERS_PER_PAGE` users → `users.data` capped at the page size and `last_page > 1`.
    - **No N+1 (the core AC):** seed ~30 users each with trips/tokens/samples, enable the query log, load the page, and assert the request issues a **small constant** number of queries (e.g. `<= 5`) regardless of user count (paginate count + select; `withCount`/`withMax`/`withExists` are correlated subqueries, not extra round-trips).
    - **Authz:** guest → redirect login; non-admin → 403.
  - [x] **Gates (all green):** `php artisan test --compact`, `vendor/bin/pint --dirty --format agent`, `./vendor/bin/phpstan analyse`, `npm run types:check`, `npm run lint:check`, `npm run build:ssr`.

## Dev Notes

### Scope boundary (read first)
- **Users section only.** Do not touch Overview/Emails/Promos/Samples/Monitoring. **Read-only**, **no migrations** (the one model change is a new *relation method*, not a schema change). Reuse the `AdminLayout` shell (7.1). No charts here.

### Architecture (binding)
- **FR-23:** "a paginated, searchable list shows each user's email, plan, confirmed?, created date, active-trip count, last login (`login_tokens.consumed_at` max), and sample-requested?, with counts eager-loaded (no N+1); the view is strictly read-only." [Source: _bmad-output/planning-artifacts/epics.md#Story-7.4]
- **Cross-cutting Epic-7 ACs:** phone-first (table scrolls at mobile width), read-only, `admin`-Gate-guarded. [Source: epics.md#Epic-7]
- **No N+1 is a hard requirement** — use `withCount`/`withMax`/`withExists` (correlated subqueries), never per-row lookups. [Source: epics.md#Story-7.4]

### Data model (exact fields)
- **`users`** — `email`, `plan` (`free|ad_free`), `email_verified_at` (→ `isConfirmed()`), `created_at`. `is_admin` exists but is not shown as a column (out of scope). [Source: app/Models/User.php]
- **`trips`** — `status` (`Trip::STATUS_ACTIVE|…`), SoftDeletes (default scope excludes trashed — correct for "active-trip count"). `User::trips()` HasMany. [Source: app/Models/Trip.php; User.php:120]
- **`login_tokens`** — `user_id`, `consumed_at` nullable (a consumed token = a login; last login = `max(consumed_at)`). `User::loginTokens()` HasMany; index `['user_id','consumed_at']` supports the aggregate. [Source: app/Models/LoginToken.php; migration]
- **`sample_requests`** — `user_id`; "sample-requested?" = existence of any row. Add `User::sampleRequests()` (Task 1). [Source: app/Models/SampleRequest.php; migration]

### Code intel (patterns to match)
- **Thin Inertia controller:** `AdminController` already renders `Admin/*` pages; follow `monitoring()`/`overview()`. Read query params like `overview()` does. [Source: app/Http/Controllers/AdminController.php]
- **`withMax` alias returns a raw string** (not Carbon-cast) — parse with `CarbonImmutable::parse(...)` before `toDateString()`, guarding null. [Laravel aggregate-alias behavior]
- **`withExists('sampleRequests as has_sample_request')`** adds a boolean-ish `*_exists` attribute — cast `(bool)`. Available in Laravel 9.31+ (this app is v13). [Source: composer.json LARAVEL v13]
- **Paginator + Inertia:** `->paginate(N)->withQueryString()->through(fn ...)` serializes to `{ data, links, current_page, last_page, per_page, total, from, to, ... }`. `->through()` maps items while keeping pagination metadata. `withQueryString()` keeps `?search=` on page links. No existing pagination in the app — this establishes the pattern. [Source: app/Http/Controllers/DashboardController.php for the render style]
- **Frontend:** existing `components/ui/input` exists; the monitoring page shows the semantic-`<table>` + token-class convention (no shadcn Table). Use `<Link>` for pagination and `router.get` for search (from `@inertiajs/vue3`). Activate the `inertia-vue-development` skill. Wayfinder `admin.users` helper: `import { users } from '@/routes/admin'` → `users.url()` for the `router.get` target. [Source: resources/js/pages/Admin/Monitoring.vue; resources/js/routes/admin]

### Testing standards
- Pest + `RefreshDatabase`. Admin `User::factory()->admin()->confirmed()`. Seed trips via `Trip::factory()->for($user)` (+ `paused()`/`completed()` states to prove only active are counted); login tokens via `$user->loginTokens()->create(['token_hash' => str_repeat('a', 64)/*unique per row*/, 'expires_at' => now()->addHour(), 'consumed_at' => '2026-06-30 10:00:00'])` (token_hash is unique — vary it per row); sample requests via `DB::table('sample_requests')->insert([...])` or the new relation. Assert Inertia props with `->has('users.data', n)` / `->where('users.data.0.active_trips_count', k)`. For no-N+1, `DB::enableQueryLog()` around the request and assert `count(DB::getQueryLog()) <= 5`. Authz mirrors `AdminShellTest`. [Source: tests/Feature/Admin/*.php; app/Models/LoginToken.php]

### Project Structure Notes
- **New:** `resources/js/types/pagination.ts`, `tests/Feature/Admin/UsersExplorerTest.php`.
- **Modified:** `app/Models/User.php` (+`sampleRequests()` relation), `app/Http/Controllers/AdminController.php` (`users()` body + `USERS_PER_PAGE`), `resources/js/pages/Admin/Users.vue` (placeholder → real).
- **Unchanged:** routes (`admin.users` exists from 7.1), other sections, `MetricsService`. **No migrations.**

### Previous story intelligence (7.1–7.3)
- 7.1 gave the guarded route + `AdminLayout` + the `Admin/Users.vue` placeholder (now replaced). 7.2/7.3 established the metrics/overview; this section is pure list/DB (no charts), so it doesn't touch `MetricsService`. Keep read-only; regenerate Wayfinder on build. 7.1–7.3 may be uncommitted in the tree; 7.4 only adds/edits its own files + the `users()` method + the `sampleRequests` relation. [Source: _bmad-output/implementation-artifacts/7-1-*.md … 7-3-*.md]

### References
- [Source: _bmad-output/planning-artifacts/epics.md#Story-7.4] (AC)
- [Source: _bmad-output/planning-artifacts/epics.md#Epic-7] (cross-cutting ACs, FR-23)
- [Source: app/Models/User.php; Trip.php; LoginToken.php; SampleRequest.php] (fields/relations)
- [Source: app/Http/Controllers/AdminController.php] (controller pattern)
- [Source: resources/js/pages/Admin/Monitoring.vue] (table + token conventions)

## Dev Agent Record

### Agent Model Used

claude-opus-4-8 (1M context)

### Debug Log References

- **Method name:** the story referenced `User::isConfirmed()`; the actual method is `hasConfirmedEmail()` — used that.
- **PHPStan on dynamic aggregates:** `active_trips_count`/`last_login_at`/`has_sample_request` are runtime attributes from `withCount`/`withMax`/`withExists`, so PHPStan flagged direct property access. Read them via `getAttribute()` (legitimately `mixed`), narrowing `last_login_at` with `is_string(...)` before `CarbonImmutable::parse` — no ignores/casts-to-silence.
- **ESLint `vue/no-v-text-v-html-on-component`:** paginator labels contain HTML entities (`&laquo;`); moved `v-html` from the `<Link>` component onto an inner `<span>`.

### Completion Notes List

- **Users relation:** added `User::sampleRequests()` HasMany (mirrors `trips()`/`loginTokens()`) so "sample-requested?" can be an eager `withExists`.
- **Controller (AC1):** one query — optional email `LIKE` filter, `withCount(active trips)`, `withMax(last consumed_at)`, `withExists(sample requests)`, `orderByDesc(id)`, `paginate(25)->withQueryString()->through(projection)`. Active-trip count counts only `active` status and excludes soft-deleted (default scope). Last-login alias is a raw string → parsed to a date, `null` when never logged in. Strictly read-only.
- **No N+1 verified:** the aggregates are correlated subqueries, so 20 seeded users each with trips/tokens/samples still loads in a small constant query count (test asserts `<= 8`, actual is a handful) — it never scales per user.
- **Page (AC2):** phone-first — semantic table in `overflow-x-auto` (min-width so columns stay legible on a phone), a debounced (300ms) email search that reloads with `preserveState/preserveScroll/replace`, and paginator `links` rendered as `<Link>`s that preserve state/scroll; `withQueryString` keeps the search across pages. Read-only (no action controls). Added a reusable generic `Paginated<T>` TS type.
- **Scope held:** only the Users section + the new relation; no migrations, no new deps, other sections untouched.
- **Verification:** full suite **347 passed / 1285 assertions** (6 new). pint clean, phpstan 0 errors, types:check + lint:check clean, build:ssr built (Users in client + SSR bundles).

### File List

**New:**
- `resources/js/types/pagination.ts`
- `tests/Feature/Admin/UsersExplorerTest.php`

**Modified:**
- `app/Models/User.php` (+`sampleRequests()` relation)
- `app/Http/Controllers/AdminController.php` (`users()` body + `USERS_PER_PAGE`, imports)
- `resources/js/pages/Admin/Users.vue` (placeholder → real explorer)
- regenerated Wayfinder helpers (gitignored)

**Unchanged:** routes (`admin.users` from 7.1), other sections, `MetricsService`. No migrations.

### Change Log

- 2026-07-01 — Implemented Story 7.4: Users explorer. Added a `User::sampleRequests()` relation and a paginated, searchable, strictly read-only `/admin/users` list showing each user's email, plan, confirmed?, created date, active-trip count, last login (max `login_tokens.consumed_at`), and sample-requested? — all eager-loaded via correlated subqueries (no N+1, verified by test). Phone-first table with debounced search + query-string-preserving pagination. All gates green (347 tests).
