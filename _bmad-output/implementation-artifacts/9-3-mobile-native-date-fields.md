---
baseline_commit: f4c37c1
---

# Story 9.3: Mobile-native date fields

Status: review

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

As a traveler on my phone,
I want the departure and return fields to look and behave like date fields,
so that trip setup isn't confusing on mobile.

## Acceptance Criteria

**AC1 — Visible date affordance on mobile empty state** *(FR-23)*
- **Given** the landing and dashboard add-trip forms on a mobile viewport (iOS Safari, Android Chrome)
- **When** the date fields render empty
- **Then** they show a visible date affordance (format hint + calendar indicator) instead of a bare box, and tapping opens the native picker — styling/markup on the existing native inputs only.

**AC2 — Native min constraints mirror server validation** *(FR-23)*
- **Given** the native inputs
- **When** the platform supports `min`
- **Then** departure has `min = today` (America/New_York calendar date — no past departure) and return has `min = departure` (return ≥ departure); the polished range-picker component remains deferred to the polish phase.

## Tasks / Subtasks

- [x] **Task 1 — ET "today" helper (client)** (AC: 2)
  - [x] New `resources/js/lib/date.ts`: `todayInEasternTime(): string` returning `YYYY-MM-DD` via `new Intl.DateTimeFormat('en-CA', { timeZone: 'America/New_York' }).format(new Date())` (`en-CA` yields ISO format). No server/controller changes and no drift against the server's `now('America/New_York')->toDateString()` anchor — the server rule stays the only authority; `min` is an affordance, not enforcement. There are no existing date helpers in `resources/js` (checked `lib/utils.ts`, composables) — this is the first, keep it tiny.
- [x] **Task 2 — `min` attributes on both surfaces** (AC: 2)
  - [x] `resources/js/pages/Landing.vue` (departure lines ~137–150, return ~155–166): `:min="todayEt"` on departure; `:min="form.departure_date || todayEt"` on return (`todayEt = todayInEasternTime()` computed once in setup). Mirrors `TripSetupRequest` exactly: `after_or_equal:$today` / `after_or_equal:departure_date` (`app/Http/Requests/TripSetupRequest.php:24–39`).
  - [x] `resources/js/pages/Dashboard.vue` add-trip panel (departure `#add-departure` lines ~273–277, return `#add-return` ~282–286): same two bindings; mirrors `AddTripRequest.php:26–34`. **Also add `novalidate` to the add-trip `<form>` (Dashboard.vue:258) to match Landing.vue:110** — without it, native `min` validation blocks submit with a browser bubble instead of the locked server message ("Return is before departure — check the dates."). The `novalidate` attribute is in scope; the `openAddPanel`/`submitAdd` logic is not. (If the user moves departure later than an already-picked return, the native input does not clear — it just goes range-invalid; with `novalidate` the submit reaches the server and the locked message shows, which is the intended behavior.)
- [x] **Task 3 — Empty-state affordance (format hint + calendar indicator)** (AC: 1)
  - [x] Wrap each of the four date `<Input>`s in a `relative` div with a decorative `Calendar` icon from **`@lucide/vue`** (the project's icon package — `import { Calendar } from '@lucide/vue'`, matching existing imports like `AlertError.vue:2`; do **NOT** install `lucide-vue-next`) — `aria-hidden="true"`, `pointer-events-none`, absolutely positioned right, `text-ink-secondary`, ~size-4 — and `pr-10` on the input — a consistent calendar indicator on every platform (iOS shows none natively when empty). Consider a tiny local wrapper only if the 4 copies get noisy — do NOT modify the shared `ui/input/Input.vue` (it serves text/email fields everywhere).
  - [x] Format hint for iOS's blank empty state, CSS-only in `resources/css/app.css` (no date-input CSS exists yet — clean slate): scope to iOS via `@supports (-webkit-touch-callout: none)`, show `content: attr(placeholder)` via `::before` on `input[type='date'][data-empty='true']` (WebKit renders `::before` on date inputs; other engines ignore it, and Android Chrome already shows a native dd/mm/yyyy hint — the scope guard prevents a doubled hint on desktop Chrome). Bind `:data-empty="!form.departure_date ? 'true' : 'false'"` (and return equivalent) and `placeholder="mm/dd/yyyy"` in the Vue templates.
  - [x] Also in the same CSS block (safe, iOS-specific fixes): `input[type='date']::-webkit-date-and-time-value { text-align: left; }` (iOS centers the value otherwise) and a `min-height`/`min-width: 0` guard so an empty date input doesn't collapse in the `sm:grid-cols-2` grid. Keep the whole block ≤ ~15 lines with a comment naming FR-23. **Placement:** append unlayered at the end of `app.css`, after the final `@layer base` (the file is all Tailwind layers — unlayered rules win over the layers, which these fixes need).
  - [x] Native picker on tap needs nothing extra — no `appearance-none` exists on `Input.vue` to suppress it (verified); do not add any.
- [x] **Task 4 — Verification** (AC: 1, 2)
  - [x] No JS test harness exists in this repo (no vitest/Playwright in package.json) — client attrs can't be feature-tested. The `min` values mirror server rules that are already pinned by `TripSetupTest.php:99–122` ("rejects a past departure in the America/New_York frame", "rejects a return before departure", "accepts a departure of today") and `AddTripTest.php:67–85` — those must stay green (the server messages are locked strings; don't touch the FormRequests).
  - [x] Visual verification (this replaces the missing JS tests — do it, record it): chrome-devtools mobile emulation (iPhone viewport) verifies the **calendar icon, grid layout, filled state, and the no-doubled-hint guard on desktop Chrome** — but emulation is still Blink, so `@supports (-webkit-touch-callout: none)` is false there and the iOS `::before` hint **will not render in those screenshots** (don't mistake Chrome's native mm/dd/yyyy skeleton for the hack working). The hint itself is only observable in real WebKit-on-iOS (Safari responsive design mode on macOS, an iOS simulator, or a device) — verify there if available; otherwise record the gap explicitly in the Dev Agent Record as residual risk. Screenshot evidence either way.
  - [x] **Gates:** `php artisan test --compact` (full suite — no regressions), `npm run lint` + `npm run format` (the repo's paved-path scripts), `npm run types:check` (new TS file), `npm run build:ssr`, `vendor/bin/pint --dirty --format agent` (no PHP expected — run anyway), `./vendor/bin/phpstan analyse`.

## Dev Notes

### Scope boundary (read first)
- **In scope:** `min` bindings, empty-state affordance (icon + iOS format hint), the tiny ET-today helper, visual verification. **Out of scope:** the polished range-picker component (explicitly deferred to the polish phase — FR-23/deferred-work), any change to server validation or its locked error messages, any change to the shared `Input.vue`, destination autocomplete (9.4 — it will touch the same two forms *after* this story; keep the date-field diff tight so they don't collide). [Source: epics.md#Story-9.3 lines 677–689; deferred-work.md]

### Architecture (binding)
- **AD-7 time frames:** "today" for the no-past-departure rule is the **America/New_York calendar date** — both FormRequests anchor on `now('America/New_York')->toDateString()`. The client `min` must use the same frame (hence `Intl … timeZone: 'America/New_York'`), otherwise a user west of ET could pick a date the server rejects (or be blocked from one it accepts) for a few hours a day. [Source: app/Http/Requests/TripSetupRequest.php:24–39; AddTripRequest.php:26–34; ARCHITECTURE-SPINE.md#AD-7]
- **Server stays the authority:** `min` is progressive enhancement ("where the platform supports it" — the AC's own words); the locked inline messages ("That date's already passed — pick a future trip." / "Return is before departure — check the dates.") remain the real gate. [Source: epics.md line 689; TripSetupRequest.php:50–51]

### Code intel (exact patterns to reuse)
- **Four inputs, two files:** Landing `departure_date`/`return_date` (Landing.vue ~137–166) and Dashboard `#add-departure`/`#add-return` (Dashboard.vue ~273–286), all `<Input type="date">` inside `sm:grid-cols-2` grids, backed by Inertia `useForm`. [Source: recon 2026-07-01]
- **`Input.vue` is clean for native rendering:** no `appearance-none`, `bg-card h-11 rounded-sm border px-3` — the native indicator/picker are not suppressed today; the gap is only iOS's *blank* empty value. `props.class` passthrough exists — `pr-10` can go through it. [Source: resources/js/components/ui/input/Input.vue:26–31]
- **No existing `input[type=date]` CSS anywhere** in `app.css` — add the one small block, don't scatter. Icons come from **`@lucide/vue`** (`package.json:40`) — 15+ existing imports use `from '@lucide/vue'` (e.g. `AlertError.vue:2`); `lucide-vue-next` is NOT installed.
- **9.2 note:** Landing.vue just gained the explainer section + SiteFooter (commit `f4c37c1`) — the hero form markup itself is unchanged; line numbers above still hold. `TripSetupTest` "repopulates form from session" (lines 48–62) exercises these fields' props — keep prop names identical.

### Testing standards
- Pest feature tests only; no JS harness — do not add one (dependency change needs approval). The server-rule tests named above are the programmatic coverage; browser-emulation screenshots are the AC1 evidence. [Source: tests/Feature/Landing/TripSetupTest.php; tests/Feature/Trip/AddTripTest.php; package.json]

### Project Structure Notes
- **New:** `resources/js/lib/date.ts`.
- **Modified:** `resources/js/pages/Landing.vue`, `resources/js/pages/Dashboard.vue`, `resources/css/app.css`.
- **Untouched (guard):** `app/Http/Requests/*`, `resources/js/components/ui/input/Input.vue`, all PHP.

### Previous story intelligence (9.1–9.2)
- 9.2 restructured Landing.vue's root (flex column + explainer + footer) — re-read the file before editing; the form is now mid-file.
- Visual-verification workflow that worked in 9.2: chrome-devtools MCP (`new_page`/`emulate`/`resize_page`/screenshot) against `php artisan serve --port=8765` (Herd's https cert is untrusted in the automation browser; kill the serve process when done). Vite dev server is already running for assets.
- Prettier/eslint will re-order imports (`import/order` rule) — run `npx eslint --fix` on touched Vue/TS files before the gates.

### References
- [Source: _bmad-output/planning-artifacts/epics.md#Story-9.3 (lines 677–689), #FR-23 (line 44)]
- [Source: app/Http/Requests/TripSetupRequest.php; app/Http/Requests/AddTripRequest.php]
- [Source: resources/js/pages/Landing.vue; resources/js/pages/Dashboard.vue; resources/js/components/ui/input/Input.vue; resources/css/app.css]
- [Source: tests/Feature/Landing/TripSetupTest.php:99–122; tests/Feature/Trip/AddTripTest.php:67–85]

## Dev Agent Record

### Agent Model Used

claude-fable-5

### Debug Log References

- Mobile emulation surfaced a **doubled calendar glyph** (Chrome's native indicator + the new tokenized icon) — resolved with a second small CSS rule: `::-webkit-calendar-picker-indicator` becomes an invisible full-field trigger (`inset: 0; opacity: 0`), so one icon shows everywhere and desktop Chrome's click-to-open still works (arguably better — the whole field opens the picker).

### Completion Notes List

- **ET-today helper (AC2):** `resources/js/lib/date.ts` → `todayInEasternTime()` via `Intl.DateTimeFormat('en-CA', { timeZone: 'America/New_York' })`; verified live: `min="2026-07-01"` matched the server anchor.
- **`min` bindings (AC2):** departure `:min="todayEt"`, return `:min="form.departure_date || todayEt"` on all four inputs (Landing + Dashboard add-trip); verified reactive — picking departure 2026-07-10 flipped return's `min` to 2026-07-10. Dashboard's add-trip `<form>` gained `novalidate` (matching Landing) so out-of-range submits reach the server's locked messages instead of a native bubble. FormRequests untouched.
- **Affordance (AC1):** each date input wrapped in a `relative` div with a `Calendar` icon from `@lucide/vue` (`aria-hidden`, `pointer-events-none`, right-aligned, `pr-10` via Input's class passthrough); `placeholder="mm/dd/yyyy"` + `:data-empty` bindings; unlayered CSS block at the end of `app.css`: min-width/height guard, iOS left-aligned value, iOS-scoped (`@supports (-webkit-touch-callout: none)`) `::before` format hint, and the invisible-native-indicator rule above.
- **Visual evidence:** iPhone-viewport emulation — empty state shows format hint + single calendar icon, filled state clean, 2-col grid intact, return-min reactivity confirmed via DOM inspection; desktop Chrome — single native skeleton + single icon, no doubled hint. **Residual risk (recorded per story):** the iOS `::before` hint is only observable in real WebKit-on-iOS; emulation is Blink, so that one behavior is unverified here — the guard is CSS-only and inert on other engines.
- **Verification:** full suite **318 passed** (no new server behavior → no new feature tests, per story); `npm run lint`, `format` (unchanged), `types:check`, `build:ssr` green; pint clean; phpstan 0 errors.

### File List

**New:**
- `resources/js/lib/date.ts`

**Modified:**
- `resources/js/pages/Landing.vue` (min/data-empty/placeholder bindings, icon wrappers)
- `resources/js/pages/Dashboard.vue` (same + `novalidate` on the add-trip form)
- `resources/css/app.css` (FR-23 date-input block: guards, iOS hint, single-glyph indicator rule)

### Change Log

- 2026-07-01 — Implemented Story 9.3: mobile-native date fields — ET-anchored `min` attributes mirroring the FormRequest rules on both trip forms, calendar-icon + format-hint empty-state affordance on the existing native inputs (no picker component), `novalidate` parity on the dashboard add-trip form. Full suite 318 passed; visually verified on mobile emulation + desktop.
