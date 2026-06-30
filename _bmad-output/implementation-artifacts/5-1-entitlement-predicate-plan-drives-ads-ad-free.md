---
baseline_commit: 919f2fc
---

# Story 5.1: Entitlement predicate — `plan` drives ads/ad-free

Status: review

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

As the system,
I want a single predicate deciding whether to show a promo,
so that ad/ad-free gating lives in one place and `plan` is a real switch.

## Acceptance Criteria

**AC1 — One read-only predicate derived from `users.plan`** *(FR-17, AD-19)*
- **Given** `users.plan` is a live entitlement (`free` default | `ad_free`)
- **When** `shouldShowPromo(user)` is evaluated at the one decision point consumed by the digest renderer
- **Then** it returns **true** for `free` and **false** for `ad_free`; **no other call site re-implements** the check.

**AC2 — Architecture-ready, billing deferred** *(AD-19)*
- **And** **no checkout sets `ad_free` in v1** — the switch is settable in data (factory state / direct write), billing is deferred. There is no payment flow.

## Tasks / Subtasks

- [x] **Task 1 — Plan constants + the predicate** (AC: 1, 2)
  - [x] In `app/Models/User.php` add `public const PLAN_FREE = 'free';` and `public const PLAN_AD_FREE = 'ad_free';` (mirror the existing `UNIT_*` constants). Add a single read-only method `public function shouldShowPromo(): bool { return $this->plan === self::PLAN_FREE; }` — the **one** entitlement decision point (AD-19). `plan` stays **not** mass-assignable (already enforced). No migration (the column exists, default `free`).
- [x] **Task 2 — Tests** (AC: 1, 2)
  - [x] `tests/Feature/Entitlement/ShouldShowPromoTest.php`: a `free` user (default) → `shouldShowPromo()` true; an `ad_free` user (`User::factory()->adFree()`) → false. Assert `plan` is **not** mass-assignable (a `User::create(['plan' => 'ad_free', ...])` / `fill` does not set it — defaults to `free`), proving the switch is data-only, not request-settable.
  - [x] **Gates:** `./vendor/bin/pest`, `vendor/bin/pint --dirty --format agent`, `./vendor/bin/phpstan analyse` (frontend untouched — no need to run JS gates, but they must remain green if run).

## Dev Notes

### Scope boundary (read first)
- **Only** the entitlement predicate. The renderer that **consumes** it (the promo slot) is Story 5.3; the `PromoProvider` is Story 5.2. No checkout/billing (AD-19 defers it). No new column. [Source: epics.md#Story-5.1; ARCHITECTURE-SPINE.md#AD-19]

### Architecture (binding)
- **AD-19 — entitlement is a single predicate; `plan` is the switch:** "`shouldShowPromo(user)` is a **single read-only predicate** derived from `users.plan` (`free` → promos on; `ad_free` → promos off), evaluated at **one decision point** consumed by the digest renderer (AD-18). `plan` is a **live entitlement**, no longer a stub. Billing that *sets* `ad_free` is still **deferred** (no checkout in v1)." [Source: ARCHITECTURE-SPINE.md#AD-19, line 159]

### Code intel (exact patterns to reuse)
- **`User`**: `plan` is a `string` property defaulting `free` (migration `0001_01_01_000000_create_users_table.php:24`), **not** in `$fillable` (intentional — line 25 comment). Mirror the `UNIT_FAHRENHEIT`/`UNIT_CELSIUS` const style and the small boolean-method style (`hasConfirmedEmail()`). [Source: app/Models/User.php]
- **Factory**: `User::factory()->adFree()` already sets `plan => 'ad_free'`; default is `free`. [Source: database/factories/UserFactory.php]

### Project Structure Notes
- **Modified:** `app/Models/User.php` (constants + `shouldShowPromo()`).
- **New:** `tests/Feature/Entitlement/ShouldShowPromoTest.php`.
- **Unchanged:** migrations, factory, the digest pipeline (5.3 will call the predicate).

### References
- [Source: _bmad-output/planning-artifacts/epics.md#Story-5.1]
- [Source: _bmad-output/planning-artifacts/architecture/architecture-tripcast-2026-06-28/ARCHITECTURE-SPINE.md#AD-19]
- [Source: _bmad-output/specs/spec-tripcast/SPEC.md#FR-17]
- [Source: app/Models/User.php; database/factories/UserFactory.php]

## Dev Agent Record

### Agent Model Used

claude-opus-4-8 (1M context)

### Debug Log References

- None. (Test initially asserted `shouldShowPromo()` on the stale in-memory instance after `User::create` — the DB-default `plan` isn't reflected without a `fresh()`; switched the assertion to `fresh()`.)

### Completion Notes List

- Added `User::PLAN_FREE`/`PLAN_AD_FREE` constants and `shouldShowPromo(): bool` — the single AD-19 decision point (`free` → true, `ad_free` → false). `plan` stays out of `$fillable` (no checkout flips it; data-settable only). No migration.
- Tests: free → true, ad_free → false, and a mass-assignment guard proving `plan` can't be request-set (stays `free`). 3 tests.
- Verification: full suite green; pint clean, phpstan 0 errors. Frontend untouched.

### File List

**New:** `tests/Feature/Entitlement/ShouldShowPromoTest.php`
**Modified:** `app/Models/User.php` (`PLAN_*` constants + `shouldShowPromo()`)

### Change Log

- 2026-06-30 — Implemented Story 5.1: a single `User::shouldShowPromo()` entitlement predicate derived from `users.plan` (free → promos on, ad_free → off), the one decision point the digest renderer will consume. `plan` stays a data-only switch (billing deferred, AD-19).
