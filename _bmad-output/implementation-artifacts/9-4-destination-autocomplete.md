---
baseline_commit: dc586a1
---

# Story 9.4: Destination autocomplete

Status: review

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

As a traveler,
I want live place suggestions as I type my destination,
so that I pick the right place instead of guessing at spelling.

## Acceptance Criteria

**AC1 — As-you-type suggestions on both destination fields** *(FR-22)*
- **Given** the destination field (landing hero + dashboard add-trip)
- **When** the visitor has typed a few characters
- **Then** Google Places Autocomplete suggestions (cities/regions) appear — keyboard-navigable and screen-reader accessible; session tokens used for per-session billing; the Places API is enabled on a restricted key.

**AC2 — Selection feeds the geocode-once path (AD-8 unchanged)** *(FR-22)*
- **Given** a selected suggestion
- **When** the form is submitted
- **Then** creation flows through the existing geocode-once path (coordinates resolve once at creation, never at send), using the suggestion's place identifier for exact resolution when available.

**AC3 — Graceful degradation** *(FR-22)*
- **Given** the Places API fails, times out, or has no key
- **When** the visitor types
- **Then** the field silently degrades to plain free text and the submit path is unchanged.

## Tasks / Subtasks

- [x] **Task 1 — `PlaceAutocomplete` port + Google adapter + fake** (AC: 1, 3)
  - [x] `app/Services/Geocoding/PlaceAutocomplete.php` (interface): `suggest(string $query, string $sessionToken): array` returning a list of `PlaceSuggestion` DTOs; PHPDoc array-shape. `app/Services/Geocoding/PlaceSuggestion.php`: readonly `placeId: string`, `label: string` (constructor promotion, like `GeocodeResult`).
  - [x] `app/Services/Geocoding/GooglePlacesAutocomplete.php` (adapter, vendor HTTP only here — AD-1): `POST https://places.googleapis.com/v1/places:autocomplete` with JSON body `{ input, sessionToken, includedPrimaryTypes: ['(regions)'] }` and header `X-Goog-Api-Key` (the AC's "cities/regions" — only one type collection is allowed per request and `(cities)` excludes regions/countries, so `(regions)` is the right pick; known tradeoff: it also matches postal codes/countries, so typing digits can surface postal-code suggestions — accept it, don't "fix" by swapping to `(cities)`. Per-request format mirrors the current Places API (New), confirmed 2026-07-01). `Http::timeout(3)` (fast failure → AC3), map `suggestions[].placePrediction` → `PlaceSuggestion(placeId, text.text)`, cap at ~5. **Any Throwable / failed response / malformed body → return `[]`** (degradation is the contract; no exception escapes the adapter).
  - [x] `app/Services/Geocoding/FakePlaceAutocomplete.php`: canned suggestions matching `FakeGeocoder`'s places (edinburgh/paris/tokyo, substring match), empty for anything else — dev-without-key parity. **Shared fake ids:** use `fake-edinburgh` / `fake-paris` / `fake-tokyo` as the placeIds so `FakeGeocoder::resolvePlace` (Task 3) recognizes exactly these.
  - [x] Bind in `AppServiceProvider` exactly like `Geocoder` (lines 35–47): key present → Google adapter; missing → Fake in non-production, RuntimeException in production. **Reuse `config('services.google.geocoding_key')`** — one restricted server-side key with Geocoding API + Places API (New) enabled (note for the 9.6 env checklist; key is never sent to the browser — that's what makes "restricted key" hold).
- [x] **Task 2 — Suggest endpoint** (AC: 1, 3)
  - [x] `app/Http/Controllers/PlaceSuggestController.php` (invokable): validate `q` (`required|string|min:2|max:255`) and `token` (`required|string|max:64`); return `response()->json(['suggestions' => [...]])` mapping DTOs to `{place_id, label}`. Adapter already returns `[]` on failure → the endpoint is **always 200 with a (possibly empty) list** for valid input — the client never needs an error branch (AC3).
  - [x] Route: `Route::get('places/suggest', PlaceSuggestController::class)->middleware('throttle:120,1')->name('places.suggest')` in the public section — generous per-IP budget for debounced keystrokes across both forms; no auth (the landing form is public).
- [x] **Task 3 — Place-id resolution on the geocode-once path** (AC: 2)
  - [x] Extend the `Geocoder` port: `resolvePlace(string $placeId, ?string $sessionToken = null): GeocodeResult` (throws `GeocodingFailedException`, same contract as `geocode`).
  - [x] `GoogleGeocoder::resolvePlace`: **Place Details (New)** — `GET https://places.googleapis.com/v1/places/{placeId}` with headers `X-Goog-Api-Key` + `X-Goog-FieldMask: formattedAddress,location` and query `sessionToken` when present → `GeocodeResult(formattedAddress, location.latitude, location.longitude)` (same `Str::limit(…, 255)` as `geocode`). Using Details (not the Geocoding API) is what **terminates the autocomplete session** so the keystrokes bill as one session (AC1's "per-session billing"). `Http::timeout(10)` like the existing methods.
  - [x] `FakeGeocoder::resolvePlace`: resolve the fake placeIds from `FakePlaceAutocomplete` to the same canned results; unknown → throw.
  - [x] Call sites: `LandingController@store` (line ~54) and `TripController@store` (line ~38) — when the validated request carries a non-empty `place_id`, try `$geocoder->resolvePlace($placeId, $sessionToken)` and **fall back to the existing `geocode($destination)` on `GeocodingFailedException`**; no place_id → exactly today's path. Still one resolution per creation, still before email capture, still outside any DB transaction — AD-8 unchanged.
  - [x] `TripSetupRequest` + `AddTripRequest`: add `'place_id' => ['nullable', 'string', 'max:512']` and `'session_token' => ['nullable', 'string', 'max:64']`. No changes to the locked date/destination rules or messages. `AddTripRequest::tripDetails()` keeps its current shape (place_id/session_token read separately by the controller — don't widen the typed array other stories consume).
- [x] **Task 4 — Accessible combobox on both forms** (AC: 1, 3)
  - [x] `resources/js/composables/useDestinationAutocomplete.ts`: lazily create a session token (`crypto.randomUUID()`); `watchDebounced` (from `@vueuse/core`, already a dependency — ~250ms, min 2 chars) fetching the wayfinder route via named import — `import { suggest } from '@/routes/places'` then `fetch(suggest.url({ query: { q, token } }))` (this codebase has **no** client-side `route()` helper; the convention is named imports like Landing.vue's `import { store } from '@/routes/trip-setup'`); expose `suggestions`, `select(s)`, `clear()`. **Failure handling = silence:** non-OK, network error, or abort → `suggestions = []` (no toast, no error state — AC3). Abort in-flight requests on new keystrokes (`AbortController`). After a selection, keep the token until submit (Details terminates the session server-side), then reset for the next typing session.
  - [x] `resources/js/components/DestinationAutocomplete.vue`: wrap **reka-ui Combobox primitives** (`reka-ui@^2.9.8` is already installed; use `ComboboxRoot :ignore-filter="true"` + Input/Content/Item — reka provides the WAI-ARIA combobox semantics: `role=combobox`, `aria-expanded`, listbox options, `aria-activedescendant`, arrow/Enter/Escape handling) styled to match the existing `Input` (`bg-card h-11 rounded-sm border px-3`, focus ring, `border-hairline` dropdown on `bg-surface-raised`). Props: `modelValue` (the text), `id`, `placeholder`, `ariaInvalid`/`ariaDescribedby` passthrough; emits `update:modelValue` and `select({ placeId, label })`. Free typing must always work — suggestions are an overlay, never a constraint (AC3: with zero suggestions it behaves as today's plain input).
  - [x] Wire into `Landing.vue` (destination field, lines ~121–138) and `Dashboard.vue` (`#add-destination`, lines ~265–275): selection sets `form.destination = label` + `form.place_id = placeId` + `form.session_token = token`; **any subsequent edit to the text clears `form.place_id`** (stale-id guard — typed text and id must never diverge); forms gain the two hidden fields in their `useForm` shape. **Do NOT implement the clear as a bare `watch` on `form.destination`** — the select handler's own write would fire it and wipe the id it just set (a silent failure no planned test catches: resolvePlace would never engage in real traffic). Clear `place_id` from the input's *typing* event (`update:modelValue` originating from user input), or guard the watcher to clear only when the new text differs from the last selected label. Keep `InputError` wiring and ids intact.
- [x] **Task 5 — Tests** (AC: 1, 2, 3)
  - [x] `tests/Unit/` or `tests/Feature/Geocoding/GooglePlacesAutocompleteTest.php` (mirror `GoogleGeocoderTest` style, `Http::fake`): parses `suggestions[].placePrediction` → DTOs; HTTP failure → `[]`; malformed body → `[]`; timeout simulation via `Http::fake(fn () => throw new ConnectionException)` → `[]`.
  - [x] `GoogleGeocoderTest` (extend): `resolvePlace` happy path (fake a Places Details response `{formattedAddress, location: {latitude, longitude}}`), failure → `GeocodingFailedException`; sends the `X-Goog-FieldMask` header and `sessionToken` param (`Http::assertSent`).
  - [x] `tests/Feature/Places/PlaceSuggestTest.php` (new): valid `q`+`token` with the Fake bound → 200 + suggestion shape; `q` under 2 chars → 422; missing token → 422; adapter returning `[]` → 200 empty list; throttle: **no existing route-middleware throttle test exists to copy** (the suite's throttle tests exercise the in-controller email-keyed RateLimiter, a different mechanism) — assert the route's middleware stack contains `throttle:120,1` via `Route::getRoutes()->getByName('places.suggest')` rather than firing 121 requests.
  - [x] `tests/Feature/Landing/GeocodingTest.php` + `tests/Feature/Trip/AddTripTest.php` (extend): submission **with** `place_id` resolves via `resolvePlace` (canonical name from the fake's place-id path); with a **bogus** `place_id` falls back to text geocode and still succeeds; without `place_id` → unchanged behavior (existing tests prove this — keep them green).
  - [x] **Gates:** `php artisan test --compact`, `vendor/bin/pint --dirty --format agent`, `./vendor/bin/phpstan analyse`, `npm run lint`, `npm run format`, `npm run types:check`, `npm run build:ssr`. Visual pass: chrome-devtools against `php artisan serve --port=8765` — type in both fields (Fake adapter suggests for "edin"), keyboard-navigate (arrows + Enter), Escape closes, selection fills the field; screenshot in the record.

## Dev Notes

### Scope boundary (read first)
- **In scope:** the port/adapter/fake, the proxied suggest endpoint, place-id resolution via Place Details, the combobox on both forms, tests. **Out of scope:** the Google Maps **JS SDK** (do not add it — the server proxy keeps the key restricted and vendor code in the adapter, AD-1), biasing/geolocation, per-user language, changing the passive-confirm step (UX-DR3's "no did-you-mean picker" applies to the *confirm* step — FR-22 explicitly reverses it only for *entry*), any change to the locked validation messages, the date fields (9.3, just shipped — don't disturb their wrappers). [Source: epics.md#Story-9.4 lines 691–709, #FR-22 line 43; deferred-work.md (autocomplete reversal)]

### Architecture (binding)
- **AD-1 ports:** vendor HTTP only in adapters, bound in a ServiceProvider — copy the `Geocoder` binding shape exactly (Fake fallback in non-production, throw in production without a key). [Source: app/Providers/AppServiceProvider.php:35–47]
- **AD-8 geocode-once unchanged:** still exactly one resolution per Trip, at creation, before email capture, outside any DB transaction; `resolvePlace` is an alternative *resolver*, not a second resolution. Failure of the place-id path falls back to text geocode; failure of both → today's inline error, no Trip. [Source: ARCHITECTURE-SPINE.md#AD-8; app/Http/Controllers/LandingController.php:49–69; TripController.php:33–58]
- **Session-token billing (AC1):** token is a client-generated UUID v4 sent with every suggest request; the session is **terminated by the Place Details call** (that's why resolution uses Details, not the Geocoding API). Autocomplete: `POST https://places.googleapis.com/v1/places:autocomplete` (`input`, `sessionToken`, `includedPrimaryTypes`); Details: `GET /v1/places/{id}` + `X-Goog-FieldMask`. If a token is stale/absent at submit, Google just bills those keystrokes per-request — correctness is unaffected. [Source: developers.google.com/maps/documentation/places/web-service/place-autocomplete + /reference/rest/v1/places/autocomplete + /using-session-tokens, checked 2026-07-01]

### Code intel (exact patterns to reuse)
- **Adapter style:** `GoogleGeocoder` — `Http::timeout(10)->get(...)`, Throwable → typed exception, `$response->failed()` check, `Str::limit(…, 255)` on the name; `GeocodeResult` readonly DTO. The autocomplete adapter differs in one deliberate way: it swallows failures to `[]` (degradation contract). [Source: app/Services/Geocoding/GoogleGeocoder.php; GeocodeResult.php]
- **Key config:** `config/services.php:17–19` `services.google.geocoding_key` — reuse; do not invent a second env var (the story's "restricted key" = this server-side key with both APIs enabled; add that note to `.env.example`'s existing `GOOGLE_GEOCODING_KEY` line, one comment only).
- **Call sites:** LandingController stores geocode results in the `pending_trip` session array (keys: destination, dates, canonical_place_name, latitude, longitude, temperature_unit) via a `...$validated` spread — so once `place_id`/`session_token` are validated they **will ride into `pending_trip`**; that's harmless (`CreateTrip` and `pendingTripIsComplete` read explicit keys) — don't add exclusion logic, or if you prefer a clean session use `$request->safe()->except(['place_id', 'session_token'])`; either is fine. TripController passes `CreateTrip` the same explicit shape. [Source: LandingController.php:61–66; app/Actions/CreateTrip.php:51–58]
- **Frontend:** `reka-ui@^2.9.8` + `@vueuse/core@^12.8.2` + `@lucide/vue` already installed — **no new dependencies** (a dependency addition would need approval; none is needed). No Combobox wrapper exists yet under `components/ui/` — build the one component, don't scaffold a whole shadcn combobox family. Existing `Input.vue` classes to mirror: `border-input bg-card h-11 w-full rounded-sm border px-3 py-2 text-base` + focus-visible ring. Destination inputs currently have `autocomplete="off"` — keep it (prevents browser autofill fighting the listbox).
- **Throttle:** route-level `throttle:120,1` fits the existing `throttle:20,1` convention (per-IP); the heavier in-controller `RateLimiter` pattern (ThrottlesMagicLink) is for email-keyed budgets — not needed here.
- **9.3 just touched both forms** (commit `dc586a1` — date wrappers, `todayEt`, dashboard `novalidate`): re-read both files before editing; the destination fields themselves are untouched.

### Testing standards
- Pest; adapter tests with `Http::fake` (copy `tests/Feature/Geocoding/GoogleGeocoderTest.php`); feature tests bind the fakes (`app()->bind(Geocoder::class, FakeGeocoder::class)` in `beforeEach` — extend the same for `PlaceAutocomplete`). `Http::assertSent` for header/param assertions. Frontend combobox behavior: no JS harness (9.3 precedent) — visual + keyboard pass recorded in the Dev Agent Record is the AC1 evidence beyond the endpoint tests. [Source: tests/Feature/Geocoding/GoogleGeocoderTest.php; tests/Feature/Landing/GeocodingTest.php]

### Project Structure Notes
- **New:** `app/Services/Geocoding/PlaceAutocomplete.php`, `PlaceSuggestion.php`, `GooglePlacesAutocomplete.php`, `FakePlaceAutocomplete.php`; `app/Http/Controllers/PlaceSuggestController.php`; `resources/js/composables/useDestinationAutocomplete.ts`; `resources/js/components/DestinationAutocomplete.vue`; `tests/Feature/Geocoding/GooglePlacesAutocompleteTest.php`; `tests/Feature/Places/PlaceSuggestTest.php`.
- **Modified:** `app/Services/Geocoding/Geocoder.php` (+`resolvePlace`), `GoogleGeocoder.php`, `FakeGeocoder.php`, `app/Providers/AppServiceProvider.php` (binding), `routes/web.php` (`places.suggest`), `app/Http/Requests/TripSetupRequest.php` + `AddTripRequest.php` (nullable place_id/session_token), `app/Http/Controllers/LandingController.php` + `TripController.php` (resolution branch), `resources/js/pages/Landing.vue` + `Dashboard.vue` (combobox + form fields), `.env.example` (key comment), existing geocoder/landing/add-trip tests.

### Previous story intelligence (9.1–9.3)
- Both forms just gained 9.3's date wrappers — merge carefully. `TripSetupTest`'s repopulation test (lines 48–61) asserts only the server prop `pendingTrip.destination` — extra `useForm` fields are safe.
- Visual-verification workflow: chrome-devtools MCP against `php artisan serve --port=8765` (Herd https cert untrusted); kill the serve process after.
- Run `npx eslint --fix` / `npm run format` before gates; import order is enforced.
- The Fake adapter pattern (no key → deterministic fake) is what makes the visual pass work locally — FakePlaceAutocomplete must suggest for the same inputs FakeGeocoder resolves ("edinburgh", "paris", "tokyo").

### References
- [Source: _bmad-output/planning-artifacts/epics.md#Story-9.4 (lines 691–709), #FR-22 (line 43)]
- [Source: _bmad-output/planning-artifacts/architecture/…/ARCHITECTURE-SPINE.md#AD-1, #AD-8]
- [Source: app/Services/Geocoding/*; app/Providers/AppServiceProvider.php:35–47; app/Http/Controllers/LandingController.php, TripController.php; app/Http/Requests/TripSetupRequest.php, AddTripRequest.php]
- [Source: Google Places API (New) docs — place-autocomplete, places/autocomplete REST reference, session tokens (fetched 2026-07-01)]

## Dev Agent Record

### Agent Model Used

claude-fable-5

### Debug Log References

- The suggest endpoint's 422s initially came back as 302 redirects: `bootstrap/app.php` restricts JSON error rendering to `api/*` (`shouldRenderJsonWhen`). Fixed with an explicit `Validator::make` in `PlaceSuggestController` returning `response()->json(..., 422)` — no global exception-handler change.
- The local `.env` key already has Places API (New) enabled — the browser pass ran against the **real** adapters end-to-end (live suggestions + live Details resolution), beyond the planned fake-based check.

### Completion Notes List

- **Port + adapters (AC1/AC3):** `PlaceAutocomplete` interface + `PlaceSuggestion` DTO; `GooglePlacesAutocomplete` (Places API (New) `places:autocomplete`, `(regions)`, session token, `Http::timeout(3)`, cap 5, **every failure → `[]`**); `FakePlaceAutocomplete` (`fake-edinburgh/paris/tokyo`); bound in `AppServiceProvider` with the Geocoder's fake-in-dev / throw-in-production discipline on the same restricted key.
- **Endpoint (AC1/AC3):** `GET /places/suggest` (`throttle:120,1`, named `places.suggest`) — always 200 + list for valid input; 422 JSON for short `q`/missing token.
- **Resolution (AC2):** `Geocoder::resolvePlace(placeId, sessionToken)` via **Place Details (New)** (`X-Goog-FieldMask: formattedAddress,location`, sessionToken terminates the autocomplete session for per-session billing); implemented in `GoogleGeocoder` + `FakeGeocoder`; both controllers try place-id first and fall back to text geocode on failure — AD-8's one-resolution-at-creation unchanged; `place_id`/`session_token` nullable in both FormRequests, locked rules/messages untouched.
- **Combobox (AC1/AC3):** `useDestinationAutocomplete` composable (lazy UUID session token, `watchDebounced` 250ms/min-2-chars, `AbortController`, silence-on-failure) + `DestinationAutocomplete.vue` on reka-ui Combobox primitives (`ignore-filter`, ARIA combobox semantics for free) styled to the Input tokens; wired into both forms with the **event-contract stale-id guard** — `update:modelValue` only from typing (clears `place_id`), `select` only from picking (sets text+id together): a selection can never clobber its own id.
- **Verification:** full suite **335 passed** (1138 assertions; 17 new: 5 adapter, 5 endpoint, 3 resolvePlace, 4 controller-path). pint clean, phpstan 0 errors, eslint/format/types-check/build:ssr green. **Live browser pass** (real Google key): typing "edin" → 5 real predictions with `aria-expanded=true` + listbox roles; ArrowDown highlights, Enter selects and closes; full E2E — pick "Edinburgh, UK", submit → trip-detail confirms "Edinburgh, UK"; screenshot of the open listbox recorded (Reykjavík query).

### File List

**New:**
- `app/Services/Geocoding/PlaceAutocomplete.php`, `PlaceSuggestion.php`, `GooglePlacesAutocomplete.php`, `FakePlaceAutocomplete.php`
- `app/Http/Controllers/PlaceSuggestController.php`
- `resources/js/composables/useDestinationAutocomplete.ts`
- `resources/js/components/DestinationAutocomplete.vue`
- `tests/Feature/Geocoding/GooglePlacesAutocompleteTest.php`, `tests/Feature/Places/PlaceSuggestTest.php`

**Modified:**
- `app/Services/Geocoding/Geocoder.php` (+`resolvePlace`), `GoogleGeocoder.php`, `FakeGeocoder.php`
- `app/Providers/AppServiceProvider.php` (PlaceAutocomplete binding)
- `routes/web.php` (`places.suggest`)
- `app/Http/Requests/TripSetupRequest.php` + `AddTripRequest.php` (nullable place_id/session_token)
- `app/Http/Controllers/LandingController.php` + `TripController.php` (resolveDestination branch)
- `resources/js/pages/Landing.vue` + `Dashboard.vue` (combobox + form fields + guard handlers)
- `.env.example` (restricted-key comment)
- `tests/Feature/Geocoding/GoogleGeocoderTest.php`, `tests/Feature/Landing/GeocodingTest.php`, `tests/Feature/Trip/AddTripTest.php` (extended)

### Change Log

- 2026-07-01 — Implemented Story 9.4: server-proxied Google Places autocomplete (port/adapter/fake + throttled suggest endpoint), accessible reka-ui combobox on both destination fields with silent degradation, and exact place-id resolution via Place Details (session-token billing) feeding the unchanged geocode-once path. 17 new tests; full suite 335 passed; live end-to-end browser verification.
