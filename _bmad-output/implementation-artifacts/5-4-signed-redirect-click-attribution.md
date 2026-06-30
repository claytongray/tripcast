---
baseline_commit: 290ce86
---

# Story 5.4: Signed-redirect click attribution

Status: review

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

As the builder,
I want promo clicks attributed before forwarding to Amazon,
so that I can measure affiliate engagement (SM-4) without putting raw affiliate links in the email body.

## Acceptance Criteria

**AC1 — An impression is logged when a promo renders** *(FR-18, AD-18)*
- **Given** a `promo_events` table and a `PromoRedirect` controller on a signed route
- **When** the digest renders a promo
- **Then** an **`impression`** event is logged for `(trip_id, send_date, promo_slug)`.

**AC2 — A signed click reads-then-logs-then-forwards, idempotently and prefetch-safe** *(FR-18, AD-6, AD-18)*
- **Given** a recipient taps the promo (a **signed GET**)
- **When** the redirect runs
- **Then** it **reads-then-logs** a **`click`** event (idempotent per `(trip_id, send_date, promo_slug, event)`) **then forwards** to the Amazon product URL — **no app state mutation** (only an idempotent append), **prefetch-safe**, **no PII** beyond the existing User/Trip linkage.

## Tasks / Subtasks

- [x] **Task 1 — `promo_events` table + `PromoEvent` model** (AC: 1, 2)
  - [x] Migration `database/migrations/2026_06_30_000001_create_promo_events_table.php`: `id`; `foreignId('trip_id')->constrained()->cascadeOnDelete()`; `foreignId('user_id')->constrained()->cascadeOnDelete()`; `date('send_date')`; `string('promo_slug')`; `string('event')` (`impression|click`); `timestamps()`. **Unique `(trip_id, send_date, promo_slug, event)`** — the idempotency key (AD-18). (ERD: `PROMO_EVENT { trip_id, user_id, send_date, promo_slug, event, created_at }`.)
  - [x] `app/Models/PromoEvent.php`: consts `EVENT_IMPRESSION='impression'`, `EVENT_CLICK='click'`; `$fillable = ['trip_id','user_id','send_date','promo_slug','event']`; `send_date` date cast; `trip()`/`user()` BelongsTo. A small `record(Trip $trip, string $sendDate, string $slug, string $event): void` static helper using **`firstOrCreate`** on the 4-key (idempotent append; a re-click/prefetch never double-logs).
- [x] **Task 2 — `findBySlug` on the PromoProvider port (resolve a slug → tagged URL)** (AC: 2)
  - [x] Add `findBySlug(string $slug): ?Promo` to `app/Services/Promo/PromoProvider.php`; implement in `AffiliatePromoProvider` — scan the config catalog for the matching `slug`, return a `Promo` with the **tagged** URL (reuse the existing `tagged()` helper), or null when unknown. Vendor specifics (host, tag) stay in the adapter.
- [x] **Task 3 — `PromoRedirect` controller on a signed GET route** (AC: 2)
  - [x] `app/Http/Controllers/PromoRedirect.php@click(Request $request, Trip $trip): RedirectResponse`: read the `slug` + `send_date` (signed query params); resolve `app(PromoProvider::class)->findBySlug($slug)` — unknown slug → `abort(404)`. **Read-then-log-then-forward** (AD-18): log a `click` `PromoEvent` (idempotent), then `redirect()->away($promo->url)` to the tagged Amazon URL. **No app-state mutation** beyond the idempotent append; safe for mail-client prefetch (the one signed action that stays a GET, AD-18/AD-6). `user_id` from `$trip->user_id` (no new PII).
  - [x] Route in `routes/web.php`, in the **`['signed','throttle:20,1']`** group: `Route::get('email/promo/{trip}/{slug}', [PromoRedirect::class, 'click'])->name('promo.click')` (the `send_date` rides as a signed query param, like the feedback link).
- [x] **Task 4 — Email links via the signed redirect; impression at send** (AC: 1, 2)
  - [x] `DigestMail`: build the **signed** click URL for the promo — `URL::signedRoute('promo.click', ['trip' => $this->trip->id, 'slug' => $this->promo->slug, 'send_date' => $this->sendDate])` (permanent signature, like the other email-action links) — and pass it as `'promoUrl'` to both views. The blade promo links now point at **`$promoUrl`** (the signed redirect), **not** the raw `$promo->url` — so no raw affiliate link sits in the email body (FR-18 goal). Render only when `$promo` is present.
  - [x] **Impression at send:** in `app/Jobs/SendTripDigest.php@deliver`, on a **successful** `Mail::...->send(...)` with a non-null `$promo`, log an `impression` `PromoEvent` (idempotent) for `(trip_id, send_date, slug)` — guarded so an attribution write can never fail the send (AD-18). Logged once on the sent path (not per retry attempt; the unique key makes a reclaim safe regardless).
- [x] **Task 5 — Tests** (AC: 1, 2)
  - [x] `tests/Feature/Promo/PromoRedirectTest.php`: a **valid signed** click → a `click` `PromoEvent` row for `(trip_id, send_date, slug, click)` and a **redirect away** to the catalog's tagged Amazon URL. **Idempotent:** hitting the same signed link twice → still **one** click row (prefetch-safe). An **unsigned/tampered** URL → **403** (`signed` middleware) and **no** row. An **unknown slug** → 404. Build the signed URL with `URL::signedRoute('promo.click', …)`.
  - [x] `tests/Feature/Digest/SendTripDigestTest.php` (extend): a free-tier send with a selected promo → an **`impression`** `PromoEvent` exists for `(trip_id, send_date, slug)`; a re-run/reclaim does **not** duplicate it. An **ad_free** send → **no** `promo_events` row. A send whose delivery **fails** → no impression (only on the sent path).
  - [x] `tests/Feature/Digest/DigestMailTest.php` (extend): with a promo, the HTML/text link is the **signed `promo.click`** URL (contains `email/promo/` + `signature=`), **not** the raw `amazon.com` product URL.
  - [x] **Gates:** `./vendor/bin/pest`, `vendor/bin/pint --dirty --format agent`, `./vendor/bin/phpstan analyse`, `npm run build:ssr`.

## Dev Notes

### Scope boundary (read first)
- Closes Epic 5. **In scope:** the `promo_events` store, the signed redirect + click logging, impression-at-send, and swapping the email link to the signed redirect. No Vue. No checkout/billing. [Source: epics.md#Story-5.4; ARCHITECTURE-SPINE.md#AD-18]

### Architecture (binding)
- **AD-18 / AD-6 — the one signed GET that forwards:** "**Promo-click attribution (FR-18) is the one signed action that stays a GET**: a signed redirect route **reads-then-logs-then-forwards** to the external Amazon URL — it mutates no app state (only appends an idempotent `promo_events` row keyed `(trip_id, send_date, promo_slug, event)`), so prefetch is harmless." "**Attribution** is the AD-6 signed GET redirect that logs an idempotent `promo_events` row (impression at render, click at follow) then forwards to the Amazon URL. The `promo_events` series is tripcast's own affiliate-engagement measure (SM-4); the promo text/selection is not separately persisted beyond the event rows (config-derivable)." [Source: ARCHITECTURE-SPINE.md#AD-18 line 154, #login line 92]
- **ERD / idempotency:** `PROMO_EVENT { fk trip_id; fk user_id; date send_date; string promo_slug; enum event "impression|click"; datetime created_at }`; idempotent per `(trip_id, send_date, promo_slug, event)`. [Source: ARCHITECTURE-SPINE.md#ERD line 239, #Idempotency-keys line 170]

### Code intel (exact patterns to reuse)
- **Signed email routes** live in the `['signed','throttle:20,1']` group; the feedback link `email/trip/{trip}/feedback/{reaction}` carries `send_date` as a signed query param and `DigestMail` builds it with `URL::signedRoute(..., ['trip'=>…, 'reaction'=>…, 'send_date'=>$this->sendDate])`. **Mirror that** for the promo link. The promo route is a **GET that forwards** (not the confirm-POST pattern — AD-18's explicit exception). [Source: routes/web.php; app/Mail/DigestMail.php `feedbackUrl()`]
- **Migration style**: mirror `feedback`/`email_logs` (foreignId constrained cascadeOnDelete, `date('send_date')`, `timestamps()`, a `unique([...])`). [Source: database/migrations/2026_06_29_000004_create_feedback_table.php]
- **`DigestMail`** already carries `?Promo $promo` (5.3) and a `with` map; add `'promoUrl'` and repoint the blade links from `$promo->url` to `$promoUrl`. [Source: app/Mail/DigestMail.php; resources/views/emails/digest*.blade.php]
- **`SendTripDigest@deliver`** sends inside the retry loop and sets terminal `sent`; log the impression on the **success** branch (after `$log->update(['status' => SENT])`), guarded. `$promo`/`$this->trip` are in scope. [Source: app/Jobs/SendTripDigest.php]
- **`AffiliatePromoProvider`** has `tagged()` and reads `config('tripcast.promo.catalog')`; add `findBySlug` scanning all profiles. [Source: app/Services/Promo/AffiliatePromoProvider.php]

### Testing standards
- Pest, `RefreshDatabase`, pinned ET clock. `URL::signedRoute('promo.click', …)` to build a valid link; hit it with `$this->get(...)` and assert `assertRedirect`/`assertRedirectContains` to the Amazon URL + `assertDatabaseCount('promo_events', 1)`. Tamper a query param for the 403 case. `Mail::fake()` for the send tests; assert `promo_events` impression rows via `assertDatabaseHas`. Use `User::factory()->adFree()` for the no-row case. [Source: tests/Feature/Email/*, tests/Feature/Digest/SendTripDigestTest.php]

### Project Structure Notes
- **New:** `database/migrations/2026_06_30_000001_create_promo_events_table.php`, `app/Models/PromoEvent.php`, `app/Http/Controllers/PromoRedirect.php`, `tests/Feature/Promo/PromoRedirectTest.php`.
- **Modified:** `app/Services/Promo/PromoProvider.php` + `AffiliatePromoProvider.php` (`findBySlug`), `routes/web.php` (`promo.click`), `app/Mail/DigestMail.php` (`promoUrl`), `resources/views/emails/digest.blade.php` + `digest-text.blade.php` (link → signed URL), `app/Jobs/SendTripDigest.php` (impression at send), `tests/Feature/Digest/SendTripDigestTest.php` + `DigestMailTest.php`.

### Previous story intelligence (5.1–5.3 + Epic 2 signed links)
- 5.3 rendered the promo with a **direct** `$promo->url`; this story replaces it with the signed redirect (no raw affiliate link in the body) and adds the `promo_events` attribution. The signed-link discipline mirrors the Story 2.5/2.6 email actions, with the AD-18 twist that the promo click **stays a GET** (forwards, no confirm page) because it mutates no app state. Keep impression logging guarded so attribution never breaks a send (AD-18 ≡ AD-4 discipline). [Source: app/Mail/DigestMail.php; app/Http/Controllers/EmailAction.php]

### References
- [Source: _bmad-output/planning-artifacts/epics.md#Story-5.4]
- [Source: _bmad-output/planning-artifacts/architecture/architecture-tripcast-2026-06-28/ARCHITECTURE-SPINE.md#AD-18, #AD-6, #ERD]
- [Source: _bmad-output/specs/spec-tripcast/SPEC.md#FR-18]
- [Source: routes/web.php; app/Mail/DigestMail.php; app/Jobs/SendTripDigest.php; app/Services/Promo/*; database/migrations/2026_06_29_000004_create_feedback_table.php]

## Dev Agent Record

### Agent Model Used

claude-opus-4-8 (1M context)

### Debug Log References

- None. `signed` middleware gives 403 on unsigned/tampered links for free; `redirect()->away()` handles the external forward; `firstOrCreate` on the 4-key gives idempotency.

### Completion Notes List

- **Store:** `promo_events` migration (`trip_id`/`user_id` FK cascade, `send_date`, `promo_slug`, `event`, timestamps, unique `(trip_id, send_date, promo_slug, event)`) + `PromoEvent` model with `EVENT_*` consts and a static idempotent `record()` (firstOrCreate).
- **Resolve:** added `findBySlug()` to the `PromoProvider` port + adapter (scans the catalog, returns the tagged URL) — the click redirect's target lookup.
- **Redirect:** `PromoRedirect@click` on a **signed GET** (`promo.click`, in the signed+throttle group) reads-then-logs an idempotent `click` then `redirect()->away()` to the tagged Amazon URL; unknown slug → 404; mutates no app state beyond the append (prefetch-safe, AD-18/AD-6).
- **Email link + impression:** `DigestMail` now links the promo to the **signed redirect** (`URL::signedRoute('promo.click', …)`, permanent signature) — no raw affiliate URL in the body (FR-18). `SendTripDigest@deliver` logs an idempotent `impression` on the **sent** path only, guarded so attribution never affects the send.
- **Tests:** `PromoRedirectTest` (signed click logs + forwards; idempotent re-click; unsigned/tampered → 403 + no row; unknown slug → 404); `SendTripDigestTest` +3 (impression on send / none for ad_free / none on failed delivery); `DigestMailTest` updated (link is the signed redirect, raw Amazon URL absent). 7 new.
- **Verification:** full suite **241 passed** (828 assertions); pint clean, phpstan 0 errors, build:ssr green. **Closes Epic 5.**

### File List

**New:**
- `database/migrations/2026_06_30_000001_create_promo_events_table.php`
- `app/Models/PromoEvent.php`
- `app/Http/Controllers/PromoRedirect.php`
- `tests/Feature/Promo/PromoRedirectTest.php`

**Modified:**
- `app/Services/Promo/PromoProvider.php` + `AffiliatePromoProvider.php` (`findBySlug`)
- `routes/web.php` (`promo.click` signed GET)
- `app/Mail/DigestMail.php` (`promoUrl` signed redirect)
- `resources/views/emails/digest.blade.php` + `digest-text.blade.php` (link → signed URL)
- `app/Jobs/SendTripDigest.php` (impression at send)
- `tests/Feature/Digest/SendTripDigestTest.php` + `DigestMailTest.php`

### Change Log

- 2026-06-30 — Implemented Story 5.4: signed-redirect click attribution. A `promo_events` table + a signed GET `PromoRedirect` that reads-then-logs an idempotent click then forwards to Amazon; the digest links the promo through this signed redirect (no raw affiliate URL in the body) and logs an idempotent impression on send. Closes Epic 5.
