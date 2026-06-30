---
baseline_commit: 0be2947
---

# Story 5.3: Promo slot in the digest + mandatory disclosure

Status: review

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

As a free-tier traveler,
I want at most one calm, relevant recommendation below my forecast,
so that it helps rather than clutters — and the digest never breaks when there isn't one.

## Acceptance Criteria

**AC1 — One native promo unit below the forecast + mandatory disclosure (HTML + text twin)** *(FR-17, AD-18, UX-DR5, UX-DR12, UX-DR16)*
- **Given** `shouldShowPromo` true (5.1) and a selected item (5.2), **selection running after the snapshot is secured, before render, off the idempotency/retry path, and time-boxed**
- **When** the digest renders
- **Then** it shows **one** native promo unit **below the 7-day forecast** with the **mandatory disclosure line** — *"As an Amazon Associate, tripcast earns from qualifying purchases"* — in HTML, and the **plain-text twin** carries the label, a **literal URL**, and the disclosure.

**AC2 — ad_free / error / timeout → slot absent, digest sends normally, never in subject/preheader** *(FR-17, AD-18, AD-19)*
- **Given** `ad_free`, a selection error, or a timeout
- **When** the digest renders
- **Then** the slot is **simply absent** and the digest sends normally; the promo **never** appears in the subject line or preheader.

## Tasks / Subtasks

- [x] **Task 1 — Select the promo in `SendTripDigest` (gated, off-path, guarded)** (AC: 1, 2)
  - [x] In `app/Jobs/SendTripDigest.php@handle`, **after** the snapshot persist + narration and **before** `deliver(...)`, compute the promo **once**: `$promo = $this->selectPromo($snapshot)`. Gate on entitlement — return null immediately unless `$this->trip->user->shouldShowPromo()` (5.1, AD-19). Otherwise resolve `PromoProvider` and `select($snapshot, $this->sendDate)`, **wrapped in `try/catch (\Throwable)`** → log a warning + return null (error/timeout → no slot; never fails or delays the send beyond the timebox — the v1 adapter is pure so this is instant). Pass `$promo` into `deliver()` and on into `DigestMail` (computed once, outside the AD-4 delivery retry).
- [x] **Task 2 — `DigestMail` carries the promo; render it below the forecast** (AC: 1, 2)
  - [x] `DigestMail`: add a constructor param `?Promo $promo = null`; pass `'promo' => $this->promo` to both views (alongside the existing `with` map). Keep the mailable a pure function of its inputs.
  - [x] `resources/views/emails/digest.blade.php`: **below** the 7-day forecast table (and above/within the footer), `@if($promo)` render **one** native unit — the product image (`alt` = label), the label as a link to `$promo->url`, and the **disclosure line** verbatim. Calm styling consistent with the digest (UX-DR12). Omitted entirely when `$promo` is null.
  - [x] `resources/views/emails/digest-text.blade.php`: `@if($promo)` render the label, the **literal** `$promo->url`, and the disclosure line — below the forecast. Omitted when null.
  - [x] **Subject/preheader untouched** — the promo never appears there (the `envelope()` subject already excludes it; do not add a preheader from the promo).
- [x] **Task 3 — Tests** (AC: 1, 2)
  - [x] `tests/Feature/Digest/SendTripDigestTest.php` (extend): a **free** user with a snapshot that maps to a catalog profile → the sent `DigestMail` carries a non-null `promo` (assert via `Mail::assertSent`). An **ad_free** user (`User::factory()->adFree()`) → `promo` is null (slot gated off). A `PromoProvider` that **throws** (`$this->mock(PromoProvider::class)->shouldReceive('select')->andThrow(...)`) → digest still reaches terminal `sent` with `promo` null (never breaks the send).
  - [x] `tests/Feature/Digest/DigestMailTest.php` (extend): with a `Promo` set, the HTML renders the label, the product URL, and the **exact disclosure** string; the text twin renders the label, the literal URL, and the disclosure. With `promo` null, none of the disclosure/label render and the subject is unchanged.
  - [x] **Gates:** `./vendor/bin/pest`, `vendor/bin/pint --dirty --format agent`, `./vendor/bin/phpstan analyse`, `npm run build:ssr` (Blade-only change; JS unchanged — keep green).

## Dev Notes

### Scope boundary (read first)
- **In scope:** selecting the promo on the send seam (gated + guarded), threading it into `DigestMail`, rendering the unit + disclosure in HTML + text. **Out of scope:** the signed-redirect **click attribution + `promo_events`** (Story 5.4) — in this story the unit links **directly** to the tagged Amazon URL (`$promo->url`); 5.4 will swap that for the signed redirect and add impression/click logging. No Vue changes (Blade email only). [Source: epics.md#Story-5.3; ARCHITECTURE-SPINE.md#AD-18]

### Architecture (binding)
- **AD-18 — render-slot only, never on the delivery path:** selection runs "inside `SendTripDigest`, after the forecast snapshot is secured/persisted (AD-3) and before the final render — **not** part of the idempotency claim (AD-3) and **not** part of the bounded delivery retry (AD-4). It is **time-boxed**; on timeout, error, no profile match, or empty catalog the **slot is empty and the digest sends normally** — it must never *fail*, delay beyond its timebox, or re-trigger a send… Bounded to **one** native unit **below the 7-day forecast**, **never** in the subject/preheader; a **mandatory affiliate-disclosure line** renders in HTML + the plain-text twin." [Source: ARCHITECTURE-SPINE.md#AD-18, line 154]
- **AD-19 — gate on the one predicate:** show the promo only when `User::shouldShowPromo()` (free) is true. [Source: ARCHITECTURE-SPINE.md#AD-19]

### Code intel (exact patterns to reuse)
- **`SendTripDigest@handle`** already computes narration once after the snapshot persist and before `deliver()`, with a `try/catch` guard (`narrateSafely`) and passes the result into `deliver()` → `DigestMail`. **Mirror that exactly** for the promo: compute once, guard, pass through; never inside the delivery loop. The job is already `tries = 1`. [Source: app/Jobs/SendTripDigest.php (4.2 narration wiring)]
- **`DigestMail`** is a pure mailable; its `content()` passes a `with: [...]` map and it already carries `?string $narration` (4.2). Add `?Promo $promo` the same way. The owner gate is `$this->trip->user->shouldShowPromo()`. [Source: app/Mail/DigestMail.php]
- **The blade slots**: the HTML view renders the forecast `@foreach ($days …)` table; place the promo **after** it. The text twin lists days then the footer; place the promo after the days. The narration slot (4.2) shows the calm-line pattern to mirror. [Source: resources/views/emails/digest.blade.php, digest-text.blade.php]
- **`Promo`** value object: `slug`, `label`, `imageUrl`, `url` (already tagged). [Source: app/Services/Promo/Promo.php]

### Disclosure copy (verbatim, UX-DR16)
- `As an Amazon Associate, tripcast earns from qualifying purchases` — render in HTML and the plain-text twin whenever a promo shows.

### Testing standards
- Pest, pinned ET clock (the digest tests pin it). `Mail::fake()` + `Mail::assertSent(DigestMail::class, fn ($m) => …)` on the `promo` property. `User::factory()->adFree()` for the gated-off case. `$this->mock(PromoProvider::class)` to force the throw. For render assertions use `DigestMail::assertSeeInHtml` / `assertSeeInText` (as `DigestMailTest` does). Build a snapshot whose days map to a real catalog profile (e.g. mild/hot) so a placeholder item is selected. [Source: tests/Feature/Digest/SendTripDigestTest.php, DigestMailTest.php]

### Project Structure Notes
- **Modified:** `app/Jobs/SendTripDigest.php` (select + thread promo), `app/Mail/DigestMail.php` (`?Promo $promo`), `resources/views/emails/digest.blade.php` + `digest-text.blade.php` (promo unit + disclosure), `tests/Feature/Digest/SendTripDigestTest.php` + `DigestMailTest.php`.
- **Unchanged:** the `PromoProvider`/adapter/catalog (5.2), the entitlement predicate (5.1), the Vue frontend. No migration.

### Previous story intelligence (4.2 + 5.1/5.2)
- This is the **same after-snapshot/before-render seam** narration (4.2) uses — keep the promo selection just as off-path and guarded (any failure → no slot, never a broken/delayed send, AD-18 ≡ AD-17's discipline). Gate with 5.1's `shouldShowPromo()`; select with 5.2's `PromoProvider`. The link is direct here; 5.4 wraps it. [Source: app/Jobs/SendTripDigest.php; app/Models/User.php; app/Services/Promo/*]

### References
- [Source: _bmad-output/planning-artifacts/epics.md#Story-5.3]
- [Source: _bmad-output/planning-artifacts/architecture/architecture-tripcast-2026-06-28/ARCHITECTURE-SPINE.md#AD-18, #AD-19]
- [Source: _bmad-output/specs/spec-tripcast/SPEC.md#FR-17]
- [Source: app/Jobs/SendTripDigest.php; app/Mail/DigestMail.php; app/Services/Promo/Promo.php; resources/views/emails/*]

## Dev Agent Record

### Agent Model Used

claude-opus-4-8 (1M context)

### Debug Log References

- None. Mirrored the 4.2 narration wiring exactly (compute once after snapshot/before deliver, guarded, thread through `deliver()` → `DigestMail`).

### Completion Notes List

- **Select (gated + guarded):** `SendTripDigest::selectPromo` returns null unless `User::shouldShowPromo()` (AD-19), else resolves `PromoProvider` and selects on the secured snapshot + send_date inside a `try/catch` (any error → no slot). Computed once before the delivery retry; the v1 adapter is pure so the timebox is trivially met.
- **Thread + render:** `DigestMail` gained `?Promo $promo`; the HTML view renders one native unit below the forecast (image, label link → tagged URL, mandatory disclosure) and the text twin renders label + literal URL + disclosure — both omitted when null. Subject/preheader untouched.
- **Link is direct here;** Story 5.4 swaps `$promo->url` for the signed redirect + impression/click logging.
- **Tests:** `SendTripDigestTest` +3 (promo attached for a free user with a tagged URL; omitted for ad_free; send survives a throwing `PromoProvider`), `DigestMailTest` +3 (HTML+text render the unit + exact disclosure; omitted when null; never in the subject). 6 new.
- **Verification:** full suite **234 passed** (807 assertions); pint clean, phpstan 0 errors, build:ssr green. No migration (promo_events is 5.4).

### File List

**Modified:**
- `app/Jobs/SendTripDigest.php` (`selectPromo` + thread promo through `deliver`)
- `app/Mail/DigestMail.php` (`?Promo $promo`)
- `resources/views/emails/digest.blade.php` + `digest-text.blade.php` (promo unit + disclosure slot)
- `tests/Feature/Digest/SendTripDigestTest.php` + `DigestMailTest.php`

### Change Log

- 2026-06-30 — Implemented Story 5.3: the affiliate promo slot. On the after-snapshot/before-render seam, a free-tier digest selects one weather-keyed promo (gated by `shouldShowPromo`, guarded so any failure → no slot) and renders one native unit below the 7-day forecast with the mandatory Amazon Associate disclosure in HTML and the plain-text twin; ad_free/error/timeout → slot absent, never in the subject. The link is direct pending 5.4's signed-redirect attribution.
