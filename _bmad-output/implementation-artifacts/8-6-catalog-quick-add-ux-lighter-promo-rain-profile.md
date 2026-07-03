---
baseline_commit: 88eed3d
---

# Story 8.6: Catalog quick-add UX, lighter promo unit, `rain` profile

Status: done

> **Provenance note:** this story was executed 2026-07-03 via the superpowers
> pipeline (brainstorm → spec → plan → subagent-driven TDD with per-task
> spec+quality reviews and a final whole-branch review) and this artifact was
> authored retrospectively as the BMAD paper trail. Canonical working docs:
> `docs/superpowers/specs/2026-07-03-catalog-ux-and-lighter-promo-design.md` (spec)
> and `docs/superpowers/plans/2026-07-03-catalog-ux-and-lighter-promo.md` (plan);
> execution ledger in `.superpowers/sdd/progress.md` (git-ignored scratch).

## Story

As the admin,
I want to add catalog items fast (title-first form that prefills the rest) and have the digest promo read like a quiet editorial link,
so that I can stock the catalog in bulk and sponsored items avoid banner blindness.

## Acceptance Criteria

**AC1 — Title-first form with derived fields** *(FR-26, AD-18)*
- **Given** the create form at `/admin/promo-items/create`
- **When** the admin types a Title (Label)
- **Then** the Slug prefills live as its kebab-case (stops permanently once hand-edited; on edit the slug stays locked — AD-18 set-once is unchanged), and pasting a Product URL auto-selects the Merchant (`amazon.*` host → `amazon`, else `other`, admin-overridable).

**AC2 — Images hidden, not removed** *(FR-26)*
- **Given** the catalog form
- **When** an item is created or edited
- **Then** no image field is shown and no `image_url` is posted; the column relaxed to `NULL` (data kept for later reuse); a *present* non-https image still fails validation (defense-in-depth for direct posts).

**AC3 — Optional description flows end-to-end** *(FR-26, FR-17)*
- **Given** a new nullable `promo_items.description` (VARCHAR 500, validated `nullable|string|max:500`)
- **When** an item with a description is served in a digest
- **Then** the description renders as a quiet secondary line under the label link in both HTML and text digests, and is omitted entirely when blank (`tc-promo-desc` marker only when present).

**AC4 — Lighter promo unit, compliance intact** *(FR-17, FR-18, UX-DR12)*
- **Given** the digest promo slot
- **When** it renders
- **Then** the unit is: "Sponsored" kicker → label as the only link (signed redirect, never a raw affiliate URL) → optional description → Amazon Associate disclosure. The 40px thumbnail and the "View price" CTA line are removed; `promoCta` / `tripcast.promo.cta` / `TRIPCAST_PROMO_CTA` are removed end-to-end.

**AC5 — `rain` weather profile fills the warm-rain gap** *(FR-26)*
- **Given** `WeatherProfiler` previously routed warm rain (max precip ≥ 50%, avg high ≥ 60°F) to null → Essentials
- **When** a snapshot in that band is profiled
- **Then** it returns `rain` (new `PromoItem::PROFILE_RAIN` in the fixed taxonomy); cold rain (< 60°F) still maps to `cold-wet`; hot (≥ 80°F) outranks rain; snow short-circuit and the <2-day early-exit are unchanged. Boundary pinned by test at exactly 60°F.

**AC6 — Display-only rename of `cold-wet`** *(FR-26, AD-18)*
- **Given** stored profile values are the attribution/rotation contract
- **When** profiles surface in the admin UI (form dropdown, Index Profile column)
- **Then** a shared label map (`resources/js/lib/weatherProfiles.ts`) renders "Cold and rainy", "Rain", "Travel essentials", etc.; stored values never change and `AffiliatePromoProvider` (frozen rollback adapter) is untouched.

**AC7 — Schema-drift heal (found in manual QA)** *(post-review fix)*
- **Given** the Epic 8 create migration was amended in place (fecda3c) from `string('url')` to `string('url', 2048)` after some databases had already migrated
- **When** a long pasted Amazon search-result URL (~700 chars) is stored on a drifted DB
- **Then** it no longer throws SQLSTATE[22001]: migration `2026_07_03_185557_widen_promo_items_url_column` idempotently re-declares `url` at 2048, and a regression test posts a 2038-char URL through the store endpoint.

## Tasks / Subtasks

- [x] **Task 1 — Schema** (AC: 2, 3): migration `2026_07_03_180120` — `image_url` nullable, `description` VARCHAR(500) NULL after `label`; model PHPDoc + `$fillable`; factory `'description' => null`. *(commit 4128aee)*
- [x] **Task 2 — Validation** (AC: 2, 3): `image_url` → nullable (https rule kept), `description` → `nullable|string|max:500`; CRUD payload updated; 501-char rejection test. *(commit 8159337)*
- [x] **Task 3 — Form rebuild** (AC: 1, 2, 3, 6): label-first field order; slug/merchant dirty-flag watchers; description textarea; image field removed; `weatherProfiles.ts` label map; controller `toArray()` exposes `description`. *(commit 3de6d13)*
- [x] **Task 4 — Rain profile** (AC: 5): `PROFILE_RAIN` + taxonomy entry; profiler match arm after cold-wet; boundary tests. *(commit 272df59; implementer agent crashed post-commit, controller verified 35/35 independently)*
- [x] **Task 5 — Promo DTO** (AC: 3): `imageUrl` → `?string`, `description` → `?string = null` (positional order preserved); `DatabasePromoProvider` passthrough; frozen adapter untouched. *(commit 48c6745)*
- [x] **Task 6 — Email unit** (AC: 3, 4): HTML + text digest promo blocks rewritten; `promoCta` removed from `DigestMail` + config; repo-wide grep clean; mail tests rewritten. *(commit 3c43ad7)*
- [x] **Task 7 — Index labels + full verification** (AC: 6): Index Profile column uses labels; row type synced; full suite 536/536, eslint/build/pint clean. *(commit ac143e4)*
- [x] **Task 8 — Post-review fix** (AC: 7): url 255→2048 healing migration + long-URL regression test after user-found QueryException in manual QA. *(commit d158797)*

## Dev Notes

- **Reviews:** every task passed a task-scoped spec+quality review; final whole-branch review (most capable model) returned **0 code-level Critical/Important** and triaged all rolled-up minors ACCEPT — including refuting (via `Blade::render`) a claimed blank-line artifact in the no-description text digest.
- **Cross-cutting checks (final review):** `Promo` is never queue-serialized (`DigestMail` sent inline in the job), so old-worker/new-schema deploys can't hit the nullable `imageUrl`; the config fallback never emits `rain` (no missing-catalog-key hazard); an empty `rain` pool degrades to Essentials (pre-change behavior).
- **Merged & deployed 2026-07-03:** merge commit `f9d06a6` to main (after the welcome-first-tripcast merge `e1ed908`); merged result verified 551/551 (2213 assertions) + clean build before push; Forge auto-deploy.
- **Operational follow-ups (open):** on prod — confirm the Forge queue worker cycled post-deploy *before* saving items via the new image-less form (2026-07-02 worker-restart gotcha × stale old-release `Promo` DTO); stock the `rain` pool (ships dark — seeder mirrors the config catalog, which has no rain key); retire the 10 placeholder items only *after* real items exist (empty-live-table fallback re-serves config placeholders); `TRIPCAST_PROMO_CTA` in the Forge env is now inert, remove at leisure.
