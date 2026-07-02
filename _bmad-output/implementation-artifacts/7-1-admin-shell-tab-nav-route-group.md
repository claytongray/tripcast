---
baseline_commit: 26251b5
---

# Story 7.1: Admin shell, tab nav & route group

Status: review

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

As the builder,
I want the admin area under one guarded route group with phone-first navigation,
so that every observability section lives behind the admin Gate with a consistent shell.

## Acceptance Criteria

**AC1 — One guarded, prefixed, named route group** *(FR-22, AD-12)*
- **Given** the `/admin/*` routes
- **When** they are registered
- **Then** they sit in one `Route::middleware(['auth','can:admin'])->prefix('admin')` group with names `admin.overview`, `admin.users`, `admin.emails`, `admin.promos`, `admin.samples`, and the existing monitoring view **renamed** to `admin.monitoring`; **guests → login**, **authenticated non-admins → 403** on **each** route.

**AC2 — Phone-first tab nav on every admin page; admin entry gated on `is_admin`** *(FR-22)*
- **Given** an admin on any admin page (on a phone)
- **When** the layout renders
- **Then** a lightweight tab nav (**Overview / Users / Emails / Promos / Samples / Monitoring**) is shown and usable at mobile width (tabs never overflow the viewport unusably; the active section is visually indicated), and the **"Admin" entry** into the panel appears in the authenticated app shell **only when** the authenticated user `is_admin`.

## Tasks / Subtasks

- [x] **Task 1 — Convert the single `admin` route into a guarded, prefixed group** (AC: 1)
  - [x] Replaced the standalone route with `Route::middleware(['auth', 'can:admin'])->prefix('admin')->group(...)`.
  - [x] Registered six GET routes → `admin.overview`, `admin.users`, `admin.emails`, `admin.promos`, `admin.samples`, `admin.monitoring`.
  - [x] Added `Route::redirect('/', '/admin/overview')` as the group's first line (bare `/admin` → overview, inherits auth+can:admin).
  - [x] Updated the comment block to note the single Gate now guards the whole panel (AD-12).
- [x] **Task 2 — `AdminController`: rename `index`→`monitoring`, add section stubs** (AC: 1)
  - [x] Renamed `index()` → `monitoring()`, body verbatim; only the rendered component changed `'Admin'` → `'Admin/Monitoring'`.
  - [x] Added five thin placeholder actions (`overview/users/emails/promos/samples`), each rendering its `Admin/*` page with no props and a PHPDoc note naming the later story that fills it.
  - [x] Controller stays thin and read-only.
- [x] **Task 3 — Relocate the monitoring page under an `Admin/` folder** (AC: 1, 2)
  - [x] `git mv resources/js/pages/Admin.vue → resources/js/pages/Admin/Monitoring.vue` (content unchanged).
  - [x] Created five placeholder pages (`Overview/Users/Emails/Promos/Samples.vue`) — `<Head>` title, `<h1>`, and a calm "Coming soon in Story 7.x" bordered card using the shared tokens.
- [x] **Task 4 — `AdminLayout.vue`: phone-first tab shell** (AC: 2)
  - [x] Created `resources/js/layouts/AdminLayout.vue` — single root, header mirrors `AppLayout` (brand + Settings), plus a six-tab nav using the Wayfinder `@/routes/admin` helpers (regenerated first).
  - [x] Phone-first tab strip: `overflow-x-auto whitespace-nowrap`, each tab `h-11 inline-flex items-center px-3` (≥44px), focus-visible ring.
  - [x] Active-tab indication derived from `usePage().url` (`startsWith` on the section path) → `border-brand text-brand`, with `aria-current="page"`.
  - [x] `<slot />` renders the section page below the header.
- [x] **Task 5 — Wire the layout resolver + the gated "Admin" entry** (AC: 1, 2)
  - [x] `app.ts`: imported `AdminLayout` and added `case name.startsWith('Admin/'): return AdminLayout;` before the default.
  - [x] `AppLayout.vue`: added a gated **"Admin"** `<Link>` (→ `admin.overview`) shown only when `usePage().props.auth.user?.is_admin` (shared props are globally typed via the `InertiaConfig` augmentation — no generic needed).
- [x] **Task 6 — Update & add tests** (AC: 1, 2)
  - [x] Updated `AdminViewTest.php`: `route('admin')` → `route('admin.monitoring')`, `->component('Admin')` → `->component('Admin/Monitoring')`; all monitoring assertions still pass.
  - [x] Added `AdminShellTest.php` — a `[routeName, component]` dataset drives guest→login, non-admin→403, admin→200+component across all six sections, plus both bare-`/admin` redirect cases.
  - [x] **Gates all green:** pest 320 passed, pint clean, phpstan 0 errors, types:check clean, lint:check clean, build:ssr built.

## Dev Notes

### Scope boundary (read first)
- **Shell only.** This story delivers the guarded route group, the renamed monitoring route, an `AdminLayout` with phone-first tab nav, the gated "Admin" entry link, and **placeholder** section pages. It does **NOT** build any metrics, charts, tables, or queries for Overview/Users/Emails/Promos/Samples — those are Stories 7.2–7.7. The only page with real content is the relocated `Monitoring.vue` (unchanged from 3.4). Keep everything **read-only** (AD-12 cross-cutting AC): no mutations anywhere.
- **Do not break Story 3.4's monitoring behavior.** The existing `AdminController` body, `Admin.vue` markup, and its tests must keep working after the rename/relocation. The only intended changes are: method name (`index`→`monitoring`), rendered component string (`'Admin'`→`'Admin/Monitoring'`), file location, route name (`admin`→`admin.monitoring`), and the layout it renders under (now `AdminLayout` instead of `AppLayout`).

### Architecture (binding)
- **AD-12 — single admin Gate:** admin access is an `is_admin` boolean enforced by **one** Gate/middleware — no allowlist, no admin CMS. The `admin` Gate is already defined in `AppServiceProvider@boot` (`Gate::define('admin', fn (User $user) => $user->is_admin)`, from Story 3.4). This story **reuses** it via `can:admin` on the group — do **not** add a second gate/policy. [Source: _bmad-output/planning-artifacts/epics.md#Epic-7 cross-cutting ACs; app/Providers/AppServiceProvider.php]
- **Cross-cutting Epic-7 ACs (every story):** phone-first (stacks/scrolls at mobile width), read-only (no mutations), guarded by the `admin` Gate (guests → login, authed non-admins → 403 on every `/admin/*`). [Source: epics.md#Epic-7]
- **FR-22:** admin observability panel + overview metrics; this story is the panel's shell/navigation. [Source: epics.md#FR-Coverage-Map]

### Code intel (exact patterns to reuse)
- **Current admin route (to replace)** — `routes/web.php:54-57`: `Route::get('admin', [AdminController::class, 'index'])->middleware(['auth', 'can:admin'])->name('admin');`. `AdminController` is already imported at the top. [Source: routes/web.php]
- **`AdminController@index`** — thin, eager-loads all non-trashed trips with `user` + `emailLogs` (send_date desc) to avoid N+1, projects a read-only view-model, and calls `Inertia::render('Admin', ['trips' => …])`. Returns `Illuminate\Http\Response` (Inertia). Rename to `monitoring`; change only the component string. [Source: app/Http/Controllers/AdminController.php]
- **Layout resolution is centralized** — `resources/js/app.ts` uses `layout: (name) => { switch (true) { … default: return AppLayout } }`. Admin pages currently hit `default` → `AppLayout`. Add a `name.startsWith('Admin/')` case → `AdminLayout` **before** the default. Pages themselves do **not** set `defineOptions({ layout })`; keep that convention (resolver owns layout choice). [Source: resources/js/app.ts]
- **`AppLayout.vue`** — calm authenticated shell: a `<header>` with a tripcast `<Link :href="home()">` and a `Settings` `<Link :href="settingsEdit()">`, then `<slot />`. Imports: `home` from `@/routes`, `edit as settingsEdit` from `@/routes/settings`. Mirror this structure in `AdminLayout` and add the gated "Admin" link here. [Source: resources/js/layouts/AppLayout.vue]
- **`is_admin` reaches the frontend already** — `HandleInertiaRequests@share` shares `auth.user => $request->user()`; the `User` model has **no `$hidden`** entry for `is_admin` (only `plan`/`is_admin` are non-mass-assignable, which does not affect serialization), so `is_admin` is present in the shared prop. The TS type already declares it: `resources/js/types/auth.ts` → `User.is_admin: boolean`, and `Auth.user: User | null`. Read it via `usePage<SharedData>().props.auth.user?.is_admin`. **No backend change needed** to expose it. [Source: app/Http/Middleware/HandleInertiaRequests.php:40-42; app/Models/User.php; resources/js/types/auth.ts; resources/js/types/global.d.ts]
- **Design tokens / pill styling** — reuse the token classes already in `Admin.vue`/`Monitoring.vue` and `Dashboard.vue`: `text-ink`, `text-ink-secondary`, `text-title`, `text-subtitle`, `text-body`, `text-meta`, `border-hairline`, `bg-surface-raised`, `text-brand`, `hover:text-brand-hover`, focus ring `focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background`. [Source: resources/js/pages/Admin.vue; resources/js/layouts/AppLayout.vue]

### Wayfinder (route helpers)
- Wayfinder runs as a **Vite plugin** (`@laravel/vite-plugin-wayfinder`) and regenerates typed helpers under `resources/js/routes` on `npm run dev`/`build` — the generated files are **gitignored**. After editing `routes/web.php`, run a build (`npm run build:ssr`) or dev so the new named routes (`admin.overview`, …, `admin.monitoring`) produce helpers before importing them in `AdminLayout`/`AppLayout`.
- Import the generated helpers by name-prefix (Wayfinder groups `admin.*` under `@/routes/admin`). Confirm the exact export names after regenerating (e.g. `import { overview, users, emails, promos, samples, monitoring } from '@/routes/admin'`); use `overview()` etc. as `:href`. If the generated shape differs, follow the generated file — do not hardcode URL strings. Activate the `wayfinder-development` skill when wiring these. [Source: package.json @laravel/vite-plugin-wayfinder; CLAUDE.md Wayfinder rules]

### Frontend patterns (Inertia + Vue)
- Activate the **`inertia-vue-development`** skill for the layout/page work. Vue components need a **single root element**. Use `<script setup lang="ts">`. Access shared props with `usePage<SharedData>()` (type from `resources/js/types`). For active-tab detection use `usePage().url` (current path string) — not `window.location`. [Source: CLAUDE.md Inertia rules; resources/js/types]
- Phone-first: the tab strip must remain usable at ~360px width — horizontal scroll (`overflow-x-auto whitespace-nowrap`) is the intended pattern (six tabs won't fit unscrolled on a phone). Tap targets ≥44px tall. Activate the **`tailwindcss-development`** skill for the utility classes.

### Testing standards
- Pest + `RefreshDatabase`. Admin: `User::factory()->admin()->confirmed()`. Non-admin: a plain confirmed user (`User::factory()->confirmed()`). Guests: no `actingAs`. Assert authz with `assertForbidden()` (403) and `assertRedirect(route('login'))`. Inertia assertions via `->component('Admin/Overview')` etc. Use a **dataset** for the six routes to avoid repetition. For `Monitoring`, keep 3.4's data assertions (trips across two users, an `email_logs` `sent` and a `failed` w/ `failure_reason`, latest-snapshot reference). Seed `email_logs` via `$trip->emailLogs()->create([...])` or `DB::table('email_logs')->insert([...])` (EmailLog has no factory). [Source: tests/Feature/Admin/AdminViewTest.php; tests/Feature/Dashboard/DashboardTest.php; app/Models/EmailLog.php]
- The gated "Admin" **link visibility** is a Vue conditional on a shared prop; it does not need its own Pest test (the shared `auth.user.is_admin` prop is already exercised). An optional Pest v4 **browser** smoke (tab nav renders + is clickable for an admin) is nice-to-have but not required for this story.

### Project Structure Notes
- **New:** `resources/js/layouts/AdminLayout.vue`; `resources/js/pages/Admin/Overview.vue`, `Users.vue`, `Emails.vue`, `Promos.vue`, `Samples.vue`; `tests/Feature/Admin/AdminShellTest.php`.
- **Moved:** `resources/js/pages/Admin.vue` → `resources/js/pages/Admin/Monitoring.vue`.
- **Modified:** `routes/web.php` (single route → guarded prefixed group + redirect), `app/Http/Controllers/AdminController.php` (`index`→`monitoring` + 5 stubs, component string), `resources/js/app.ts` (resolver case + import), `resources/js/layouts/AppLayout.vue` (gated "Admin" link), `tests/Feature/Admin/AdminViewTest.php` (route/component rename), regenerated Wayfinder (gitignored).
- **Unchanged:** the `admin` Gate in `AppServiceProvider`, `HandleInertiaRequests` (is_admin already shared), `User`/`Trip`/`EmailLog` models, `resources/js/types/auth.ts` (is_admin already typed). **No migrations.**

### Previous story intelligence (3.4 — admin monitoring)
- 3.4 established the single Gate + `can:admin` pattern, the thin `AdminController`, `Admin.vue`, and `AdminViewTest`. Its review flagged (deferred) that the monitoring view loads all trips/logs unbounded — **still out of scope here** (do not add pagination in this shell story). Regenerate Wayfinder after route changes (gitignored). Keep controllers thin and views read-only. [Source: _bmad-output/implementation-artifacts/3-4-admin-monitoring-view.md]
- This is the **first** story of Epic 7 (epic already `in-progress`). Naming/route conventions set here (the `Admin/` page folder, `admin.*` route names, `AdminLayout`) are the base every later Epic-7 story builds on — get them exact.

### References
- [Source: _bmad-output/planning-artifacts/epics.md#Story-7.1] (ACs, cross-cutting Epic-7 ACs)
- [Source: _bmad-output/planning-artifacts/epics.md#Epic-7] (goal, FRs FR-22–FR-25, ADs AD-12/AD-9/AD-14/AD-18)
- [Source: _bmad-output/planning-artifacts/sprint-change-proposal-2026-07-01.md] (Epic 7 rationale/scope)
- [Source: _bmad-output/implementation-artifacts/3-4-admin-monitoring-view.md] (reuse base: Gate, controller, page, tests)
- [Source: routes/web.php; app/Http/Controllers/AdminController.php; resources/js/app.ts; resources/js/layouts/AppLayout.vue; app/Http/Middleware/HandleInertiaRequests.php; resources/js/types/auth.ts]

## Dev Agent Record

### Agent Model Used

claude-opus-4-8 (1M context)

### Debug Log References

- None — clean implementation. Only friction was ESLint `import/order` on the two layouts, auto-fixed via `npm run lint`.

### Completion Notes List

- **Route group (Task 1):** one `Route::middleware(['auth','can:admin'])->prefix('admin')` group; six named GET routes + a bare `/admin` → `/admin/overview` redirect. The single `admin` Gate (defined in 3.4) now guards the whole panel (AD-12) — no new gate/policy added.
- **Controller (Task 2):** `AdminController@index` → `monitoring()` (body verbatim, component string → `Admin/Monitoring`); five thin placeholder actions render their `Admin/*` page with no props, each documented with the Story (7.3–7.7) that fills it. Read-only throughout.
- **Pages (Task 3):** `Admin.vue` relocated (git mv) to `Admin/Monitoring.vue`; five placeholder section pages created with calm "Coming soon in Story 7.x" cards using the existing design tokens.
- **AdminLayout (Task 4):** phone-first shell — brand + Settings header plus a horizontally scrollable six-tab strip built on the Wayfinder `@/routes/admin` helpers; active tab derived from `usePage().url` with `aria-current="page"` and a `border-brand text-brand` indicator; ≥44px tap targets.
- **Wiring (Task 5):** `app.ts` resolver routes `Admin/*` pages to `AdminLayout`; `AppLayout` gains a gated "Admin" link (→ overview) shown only when `auth.user.is_admin`. `is_admin` was already shared + TS-typed, so no backend/type change was needed.
- **Tests (Task 6):** `AdminShellTest` (dataset-driven authz + component across all six sections + the two `/admin` redirect cases) and the rename in `AdminViewTest`. **Full suite: 320 passed / 1094 assertions.** pint, phpstan (0 errors), types:check, lint:check, build:ssr all green.
- **Scope held:** no metrics/charts/queries for the placeholder sections (those are 7.2–7.7), no mutations, no migrations, no new dependencies. Monitoring's 3.4 behavior is unchanged (now under `AdminLayout`).

### File List

**New:**
- `resources/js/layouts/AdminLayout.vue`
- `resources/js/pages/Admin/Overview.vue`
- `resources/js/pages/Admin/Users.vue`
- `resources/js/pages/Admin/Emails.vue`
- `resources/js/pages/Admin/Promos.vue`
- `resources/js/pages/Admin/Samples.vue`
- `tests/Feature/Admin/AdminShellTest.php`

**Moved:**
- `resources/js/pages/Admin.vue` → `resources/js/pages/Admin/Monitoring.vue`

**Modified:**
- `routes/web.php` (single route → guarded prefixed group + bare-prefix redirect)
- `app/Http/Controllers/AdminController.php` (`index`→`monitoring` + 5 section stubs, component string)
- `resources/js/app.ts` (AdminLayout import + resolver case)
- `resources/js/layouts/AppLayout.vue` (gated "Admin" entry link)
- `tests/Feature/Admin/AdminViewTest.php` (route/component rename)
- regenerated Wayfinder helpers under `resources/js/routes` + `resources/js/actions` (gitignored)

### Change Log

- 2026-07-01 — Implemented Story 7.1: admin shell, tab nav & route group. Folded the standalone admin monitoring route into one Gate-guarded `/admin` group with six named sections (overview/users/emails/promos/samples/monitoring) + a bare-prefix redirect; added a phone-first `AdminLayout` tab shell and a gated "Admin" entry link in the app header. Overview/Users/Emails/Promos/Samples are shell placeholders for Stories 7.3–7.7; Monitoring keeps its 3.4 behavior. All gates green (320 tests). Opens Epic 7.
