# Rubric Review — ARCHITECTURE-SPINE.md (Tripcast v1)

- **Reviewer role:** Spine rubric reviewer (lean invariants contract)
- **Date:** 2026-06-28
- **Subject:** `_bmad-output/planning-artifacts/architecture/architecture-tripcast-2026-06-28/ARCHITECTURE-SPINE.md`
- **Driving spec:** `prd.md` + `addendum.md` (Tripcast v1)
- **Verdict:** **PASS-WITH-FIXES**

The spine is genuinely strong: it correctly identifies the cross-feature collision points (Trip.status, cadence, time frames, external I/O, idempotency, auth surfaces) and pins each with an enforceable invariant. All 14 FRs map to ADs with no coverage holes. Tech is verified-current. The fixes below are operational-envelope gaps and a few enforceability nuances — none is a structural divergence hole, but the daily-send liveness gap is material because the entire product *is* that run.

---

## Checklist 1 — Does it fix the real divergence points for the 7 PRD features built independently? Misses none?

The 7 independently-buildable feature units are §4.1 Landing/Trip Setup, §4.2 Auth, §4.3 Email Digest, §4.4 Weather & Geocoding, §4.5 Dashboard, §4.6 Admin, §4.7 Pay Intent. The genuine cross-unit collision points and their coverage:

| Divergence point (which units collide) | Pinned by | Verdict |
| --- | --- | --- |
| `Trip.status` mutated by Dashboard, email end-trip link, daily job, Admin | AD-5 (single transition method + status-agnostic completion sweep) | Covered |
| "Is a digest due / how many days until" computed by send selector AND dashboard countdown | AD-11 (one cadence predicate) | Covered |
| Timezone math shared by selector, countdown copy, sweep, renderer | AD-7 (two pinned reference frames) | Covered |
| External I/O (weather/geocoding/mail) reached differently per feature | AD-1 (ports + adapters) | Covered |
| Login vs login-free email actions | AD-6 (single-use token vs signed URL) | Covered |
| Send dedup across retries/crashes/future workers | AD-3 (claim-first unique index) | Covered |
| Two trip-creation paths (landing FR-1/2 + dashboard add FR-12) | AD-8 + AD-10 (geocode-once, atomic account+trip) | Covered |
| Monitoring/observability data source | AD-9 (EmailLog single source of truth) | Covered |
| Admin gating scattered per route | AD-12 (single Gate on `is_admin`) | Covered |
| Temperature unit handling across renderers | Consistency Conventions (both °F+°C, convert at render) | Covered |

**Result: PASS.** Both trip-creation entry points are unified under AD-8/AD-10, which is the most commonly-missed divergence in this product shape — the spine catches it. No collision point is left unpinned.

Minor: **Feedback Click** persistence (FR-8) has a `Feedback` table keyed `(trip_id, send_date, reaction)` but no stated uniqueness/upsert rule for repeat taps. Single-unit concern (the signed-route handler), not a cross-unit divergence — acceptable to leave to story-level, noted for completeness.

## Checklist 2 — Is every AD's Rule enforceable and does it actually prevent its stated divergence?

Mostly yes. Notes by AD:

- **AD-1, AD-6, AD-8, AD-10, AD-12** — mechanically enforceable (interface binding, DB not-null, DB transaction, single Gate). Strong.
- **AD-3** — enforced at the DB level by a unique index; the strongest kind of invariant. **Enforceability nuance:** the claim row is written `status=sending` *before* fetch/send. A job that crashes after claiming but before writing `sent` leaves a permanent `sending` row that blocks any same-day re-dispatch (duplicate insert fails the constraint). That is consistent with AD-4's "recover on next day's run" and never produces a duplicate — but the stuck row is never reconciled and the crash silently costs that trip its digest for the day. The behavior is defensible; it is just **undocumented**. (LOW/MEDIUM)
- **AD-4** — clear and bounded. Covers *total* fetch/delivery failure (defer). The FR-7 "limited data note instead of fabricated values" case (a *partial* forecast that still sends) is a renderer decision adjacent to AD-4's "never fabricated/stale" clause; it is consistent but not separately pinned. Single-unit, low risk.
- **AD-5, AD-11** — enforceable only by convention ("no controller/job writes status directly"; "never a re-implementation" of the predicate). This is the right altitude for a spine, but both rely on developer discipline rather than a compiler-enforced boundary. Acceptable; worth a lint/review note downstream. (LOW)
- **AD-7** — concretely specified (`now('America/New_York')->toDateString()` for scheduling; naive DATE for trip dates; destination-local for forecast rows). Prevents the off-by-one it targets. Strong.
- **AD-2, AD-9** — enforceable structurally. Strong.

**Result: PASS** with one documentation nit (AD-3 crash semantics).

## Checklist 3 — Could anything under Deferred let two units diverge?

Reviewed each Deferred item:

- **Stripe billing** — `plan` stubbed `beta`; no surface reads it. No divergence.
- **Per-user send-time / timezone** — `users.timezone` is *collected* (default `America/New_York`) but the send clock is hard-pinned by AD-7 to `America/New_York` regardless. As long as nothing reads `users.timezone` for send math (AD-7 forbids it implicitly), no divergence. Safe, but the live unused column is a latent trap — a future dev could wire it in and silently break the AD-7 invariant. Worth a one-line "do not read `users.timezone` for v1 sends" guard. (LOW)
- **Queue scaling** — seam pre-opened by AD-2; tuning only. No divergence.
- **Forecast content depth** — port is wide enough; additive. No divergence.
- **Privacy program** — not a structural concern.
- **Same-day-before-9am first send** — deferred *behaviorally* but the default is the AD-11 predicate, which is the *single* authority both selector and UI derive from. So even while "confirm at first signup," the two units cannot disagree. This is the correct way to defer a behavior without opening a divergence.

**Result: PASS.** Nothing in Deferred opens a two-unit divergence. The only watch item is the live-but-unused `users.timezone` column vs. the AD-7 pinned clock.

## Checklist 4 — Is named tech verified-current?

Web-verified 2026-06-28:

| Tech | Spine says | Verified current | Verdict |
| --- | --- | --- | --- |
| Laravel | 13.x | Laravel 13 stable since 2026-03-17 (latest 13.8.0, 2026-05-26); PHP 8.3+ required | Current |
| Inertia.js | 3.x (SSR via `@inertiajs/vite`) | Inertia v3 stable since late March 2026 (rethought layouts, built-in HTTP client, dev SSR) | Current |
| PHP | 8.3+ | Matches Laravel 13 floor | Current |
| Vue | 3.x | Current | Current |
| Node (SSR) | 22+ | Current LTS line | Current |
| Tailwind | 4.x | Current | Current |

**Result: PASS.** All named versions are the current stable lines as of the review date.

Watch item (not a finding): the Laravel **official Vue starter kit** named as the seed may still ship against Inertia 2 at the kit level; confirm the kit is on Inertia 3 (or budget the v2→v3 bump) before adopting. Implementation detail, not a spine defect.

## Checklist 5 — Does it cover the driving spec's capabilities (FR-1..FR-14)? Map gaps?

Every FR appears in the Capability→Architecture Map and resolves to a real home + governing AD:

| FR | Home | AD | Covered |
| --- | --- | --- | --- |
| FR-1/2 Landing + inline setup + email capture | `Pages/Landing`, `LandingController`, `Actions/CreateTrip` | AD-8, AD-10 | Yes |
| FR-3/4 Magic-link + sessions | `RequestMagicLink`, `Auth`, `login_tokens` | AD-6 | Yes |
| FR-5 Login-free email actions | signed routes, `EmailAction`, Trip transition | AD-5, AD-6 | Yes |
| FR-6 Daily cadence | `SendDailyDigests`, predicate, `SendTripDigest` | AD-2/3/4/11 | Yes |
| FR-7 Digest content (F+C, days) | `views/emails/digest`, renderer | AD-7, AD-9 | Yes |
| FR-8 Feedback Click | signed route, `Feedback` | AD-6 | Yes |
| FR-9 Welcome Email | `CreateTrip` → mail | AD-11 | Yes |
| FR-10/11 Geocode + forecast | `Services/Geocoding`, `Services/Weather` | AD-1, AD-8 | Yes |
| FR-12 Dashboard | `Pages/Dashboard`, Trip transition | AD-5, AD-11 | Yes |
| FR-13 Admin | `Pages/Admin`, Gate, `email_logs` | AD-9, AD-12 | Yes |
| FR-14 Pay Intent | upgrade page, `PayIntent` | AD-6 | Yes |

**Result: PASS — no FR gap.** Two micro-notes:
- FR-14's consequence "Pay Intent counts visible to Admin" is not wired into the Admin map row (which reads `email_logs`); the `PayIntent` entity exists, so it is a query, but the admin surface→PayIntent link is implicit. (LOW)
- FR-7's "limited data" partial-forecast rendering is governed only indirectly (AD-4 "never fabricated/stale"). (LOW)

## Checklist 6 — Is every dimension the initiative altitude owns decided / deferred / open? (esp. operational/environmental envelope)

The deployment **topology** is a real strength — the Forge single-box diagram explicitly names Nginx+PHP-FPM, the SSR Node, the scheduler cron, the queue-worker daemon, Redis, and the DB, plus the external-call fan-out. Config/secrets are pinned (`.env` + provider binding). That is most of the envelope.

The thin / silent sub-dimensions:

1. **Daily-send liveness / alerting — silent (the most material gap).** Observability is satisfied *per send* (AD-9 EmailLog + Admin view), but the Admin view is **pull-only**. If the whole run never fires — cron stopped, worker daemon down, Redis unreachable — there is **no detector and no alert**. For a product whose entire value is "an email shows up every morning," a silent total-failure of the run is the single scariest operational risk and nothing in the spine catches it. The PRD Observability NFR is technically met (it only asks for logs + admin view), so this is a "spec met, dimension thin" call — but at initiative altitude "how do we know the core job ran" is an owned dimension. **(HIGH)**

2. **Release / environment envelope — silent.** No deploy process (Forge deploy hook, `migrate --force` on release), no staging vs prod stance, and — for a single box holding the *only* copy of all user + trip data — no backup/DR posture. Forge offers DB backups, but the spine does not pin it. Also the **Inertia SSR Node process needs its own supervised daemon** (like the queue worker); the topology diagram folds it into the "Web" node without calling out process supervision. **(MEDIUM)**

3. **DBMS not pinned.** Both the deployment diagram and conventions leave it as "MySQL/Postgres." AD-3's unique index and AD-10's email upsert/matching work on either, so it is not load-bearing for correctness — but email-uniqueness case-folding and collation differ between engines, and the seed should not be ambiguous about its own datastore. **(MEDIUM/LOW)**

4. **Magic-link / email request throttling — unaddressed.** FR-3 exposes an unauthenticated "enter email → we send a link" endpoint. With no rate-limit invariant this is an email-bomb / account-enumeration vector. More detailed than spine grain, but it is an owned security/operational dimension for an auth surface that *is* the only login. **(LOW)**

**Result: PARTIAL.** Deployment/infra is well covered (not a whole silent dimension), but the **operational** sub-envelope — run liveness/alerting, release pipeline, backup/DR, datastore choice — is under-decided. None is deferred-with-rationale or marked open; they are simply absent.

---

## Findings (ranked)

| # | Severity | Finding | Fix (one line) |
| --- | --- | --- | --- |
| 1 | **HIGH** | No liveness/alert on the daily send *run as a whole*; Admin view is pull-only, so cron/worker/Redis total failure goes undetected — yet the run is the entire product. | Add an operational invariant: post-run heartbeat / dead-man's-switch (e.g. "run completed: N sent, M failed" push, or a missed-heartbeat alert) so a silent no-run is detectable. |
| 2 | **MEDIUM** | Operational release/environment envelope silent: no deploy+migrate process, no backup/DR for the single box holding all data, SSR-Node process supervision not called out. | Add a short Operations note pinning Forge deploy + `migrate --force`, scheduled DB backup, and supervised daemons for both the queue worker and the SSR Node. |
| 3 | **MEDIUM** | DBMS left ambiguous ("MySQL/Postgres") in the seed; AD-3/AD-10 depend on a unique index + email matching whose collation/case-folding semantics differ by engine. | Pin one engine in the Stack table and note the email-uniqueness collation. |
| 4 | **LOW** | AD-3 crash semantics undocumented: a job crashing after claim (`status=sending`) leaves an unreconciled row that blocks that trip's same-day retry. | One line in AD-3/AD-4 stating crash-mid-send defers to next day by design (or add a stale-`sending` sweep). |
| 5 | **LOW** | Live-but-unused `users.timezone` (default `America/New_York`) is a latent trap against the AD-7 pinned send clock; plus FR-3 magic-link request endpoint has no throttling invariant. | Add "do not read `users.timezone` for v1 sends" guard to AD-7/Deferred; add a request-throttle convention for the magic-link endpoint. |

## Bottom line

**PASS-WITH-FIXES.** The structural core — the part a spine exists to protect — is excellent: every real cross-feature divergence point is pinned with an enforceable invariant, all 14 FRs are covered, Deferred is clean of divergence risk, and the named tech is current. The fixes are concentrated in the operational envelope (Finding 1 is the one to address before build: the core daily run has no failure detector). Address Findings 1–3 and this is a clean PASS.
