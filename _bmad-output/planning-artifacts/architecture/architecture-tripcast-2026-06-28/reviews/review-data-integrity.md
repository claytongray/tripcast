---
title: Data-Integrity & Persistence Review — Tripcast v1
reviewer: data-integrity reviewer
scope: ERD, AD-3 idempotency, AD-4 retry, AD-5 state machine, AD-9 EmailLog, AD-10 atomic create, FR-8 feedback, FR-12 delete, AD-6 login_tokens, Cost-control NFR
target: ARCHITECTURE-SPINE.md (architecture-tripcast-2026-06-28)
date: 2026-06-28
verdict: Conditional pass — persistence model is sound in its core idempotency seam, but four data-integrity gaps (stuck-claim recovery, feedback uniqueness, delete cascade, retry re-fetch) must be closed before build.
---

# Data-Integrity & Persistence Review — Tripcast v1

## One-line verdict

The spine's central idempotency seam (claim-first `(trip_id, send_date)` unique index) is the right design and is genuinely load-bearing, but it has an undocumented failure mode, and three adjacent areas — feedback uniqueness, trip deletion cascade, and retry cost — have unstated or contradictory semantics that will corrupt data or metrics in normal operation.

---

## 1. email_logs claim-first pattern (AD-3 / AD-9) — does it hold under retry and crash?

**What holds.** Inserting `status = sending` with a DB `unique(trip_id, send_date)` BEFORE doing the work is a correct claim-first dedup. It correctly prevents double-sends across:
- concurrent/future queue workers (second insert hits the unique violation and aborts),
- a pause→resume→re-select within the same day (same key, aborts),
- naive re-dispatch of the command.

The in-run retry loop (AD-4, ≤3×) does **not** re-insert — it loops back to fetch/send on the already-claimed row — so retries are safe against the constraint.

**What does NOT hold — the stuck `sending` row (HIGH).**
If the worker crashes (OOM, deploy/restart, SIGKILL) AFTER the claim insert but BEFORE writing the terminal `sent`/`failed`, the row is left in `sending` **permanently**. Consequences:

- **That trip can never send again that day.** Any retry — Laravel queue-level retry, manual re-dispatch, a second worker — will attempt the same insert, hit the unique violation, and abort as "already claimed." The constraint cannot distinguish "another worker is actively sending right now" from "my previous attempt died and left a stale claim." So same-day recovery is structurally impossible. Recovery is only the next day's run (new `send_date`, new row), which matches the PRD's tolerated edge ("if a digest can't be built one morning, she gets nothing; the next day resumes").
- **This contradicts AD-3's own stated `Prevents`:** "double-sends across retries, **crash-reruns**, and future concurrent queue workers." The dedup is asymmetric — it is biased toward *not* sending (which is the correct safety bias: never double-send), but a crash between claim and send silently drops that day's digest. AD-3 advertises crash-rerun protection; in fact a crash-rerun is *blocked from completing*, not protected.
- **`sending` is also ambiguous for observability (AD-9).** A stuck `sending` row could mean "crashed before send" OR "MailerSend accepted the message but the worker crashed before writing `sent`." The admin view (AD-9, FR-13 — declared the single source of truth for system health) cannot tell these apart, and a forever-`sending` row reads as neither success nor failure.

**Is the behavior acceptable? Yes — but only if stated, and it is not.** Dropping one morning's digest on a rare crash is acceptable for a low-tens-of-users beta and is consistent with the PRD's "you get nothing rather than a broken email" stance. The defect is that (a) the spine never states this lost-send-on-crash outcome, (b) it implies the opposite via AD-3's crash-rerun claim, and (c) there is no mechanism to detect or reclaim stale `sending` rows.

**Fix (choose one, then document):**
- Minimal/state-only: add a sentence to AD-3/AD-4 that a crash between claim and terminal write forfeits that day's send (recovered next day), and correct AD-3's `Prevents` wording.
- Better: add a stale-claim reclaim — at job start (or in the command), treat `sending` rows whose `created_at`/`updated_at` predates the current run as abandoned and allow the owning retry to reclaim them via `updateOrCreate`-style logic with a lease/timestamp, instead of a blind insert.
- And add an admin alert / daily sweep for rows left in `sending` past the run window (observability for AD-9).

---

## 2. Feedback (FR-8) — uniqueness, multiple reactions, change-of-mind (HIGH)

ERD: `FEEDBACK { fk trip_id; date send_date; enum reaction "helped|not_helpful" }`. **No unique constraint is declared**, and feedback is delivered via a signed, login-free **GET** link in the email footer (AD-6, FR-8).

Problems:
- **GET links are non-idempotent here.** Mail-client link prefetchers / security scanners (Gmail image+link proxy, Outlook SafeLinks, corporate URL-rewriters) routinely fire footer links without a human click. With no uniqueness and an `INSERT`-per-click model, this manufactures phantom and duplicate rows.
- **Multiple/contradictory reactions accumulate.** A user clicking "helped" then "not_helpful" (changing their mind) creates two rows with no defined winner. There is no rule for which is current.
- **This directly pollutes the PRIMARY success metric.** SM-1 ("share of trips registering ≥1 positive Feedback Click") and SM-3 read off these rows. Duplicate/prefetched "helped" rows inflate engagement; that is exactly the counter-metric SM-C1/SM-C2 caution against gaming, here gamed accidentally by the data model.

**ERD does not support a clean answer to "can a user change their reaction?"** — the question is simply undefined.

**Fix:** add `unique(trip_id, send_date)` on `feedback` and make recording an **upsert** (last reaction wins → re-clicks become idempotent and changing one's mind is supported, both for free). State explicitly: one reaction per (trip, send_date), latest click is authoritative. This both de-dupes prefetch noise and answers the change-of-mind question. (Optional hardening: render feedback as a tiny confirmation page or POST rather than a bare state-mutating GET.)

---

## 3. Trip.status enum + single-owner transition (AD-5) — coverage

States: `active | paused | completed`. Transitions per AD-5: `active ⇄ paused` (user), `→ completed` (system sweep or end-trip link).

**Triggers covered:**
- User pause/resume (FR-12): `active ⇄ paused` ✓
- End-trip link (FR-5): `→ completed` from active or paused ✓
- System completion (`CompleteExpiredTrips`, AD-5): any trip past Return Date → `completed`, "regardless of active/paused" — this correctly kills the "paused-past-return lingers forever" case ✓

**Gaps (LOW–MEDIUM):**
- **`completed` is not declared terminal.** There is no transition out of `completed`. That is almost certainly intended (re-engage = create a new trip), but it is unstated, and "resume" (FR-12) must be defined to reject `completed → active`/`paused`. Without an explicit table, the single transition method may or may not guard these.
- **No enumerated forbidden-transition / idempotency rules.** AD-5 names the single transition surface but never lists the rejected edges (`completed → paused`, `completed → active`) nor the idempotent no-op cases. Notably, AD-6 says the end-trip signed URL is idempotent — so `completed → completed` (re-click of an already-ended trip) must be a no-op, not an exception. State it.
- **Initial state / default not in ERD.** The `status` enum carries no `default`. New trips should default to `active`; mark it on the column.

These are correctness-of-spec gaps, not reachability bugs — no unreachable state and no missing trigger were found. Recommend AD-5 carry a small explicit transition table (allowed, forbidden, idempotent-noop) and a `default active`.

---

## 4. Deletion (FR-12 "delete a trip") — cascade behavior (HIGH)

FR-12: "User can delete a Trip (removes it and stops emails)." **Cascade behavior to `email_logs` and `feedback` is stated nowhere** — not in the ERD, not in AD-5, AD-9, or the migrations note. The ERD shows `TRIP ||--o{ EMAIL_LOG` and `TRIP ||--o{ FEEDBACK` with no `ON DELETE` semantics.

Problems:
- **Referential integrity is undefined.** A hard delete of a trip with no `ON DELETE CASCADE` violates the FKs on `email_logs`/`feedback`; with cascade, their rows vanish.
- **Hard delete conflicts with AD-9.** AD-9 declares `email_logs` the *single source of truth* for send history and admin health. Hard-deleting a trip erases its entire send/audit history. Deleting feedback rows also retroactively changes SM-1/SM-2/SM-3 denominators and numerators — trips that contributed engagement signal silently disappear from the metrics.
- **Interaction with the idempotency key is fine** (a re-created "same" trip gets a new `trip_id`, so no `(trip_id, send_date)` collision), but that does not resolve the audit-loss issue.

**Fix:** decide and state one model:
- Preferred: **soft-delete the Trip** (`deleted_at`), preserving `email_logs`/`feedback` for AD-9 and the SM metrics, and have the cadence predicate (AD-11) exclude `deleted_at IS NOT NULL` so "stops emails" is honored. This best satisfies AD-9 + FR-12 together.
- Alternative: explicit `ON DELETE CASCADE` to `email_logs` + `feedback`, with an accepted, documented loss of history/metrics. Less compatible with AD-9.
Either way, this must be written down; today it is a silent gap on a bound FR.

---

## 5. login_tokens (AD-6) — single-use, expiry, cleanup

Schema: `LOGIN_TOKEN { fk user_id; string token_hash; datetime expires_at; datetime consumed_at }`.

**What holds:** hashed token (not plaintext) ✓, single-use via `consumed_at` set on click ✓, expiry via `expires_at` ✓, and FR-3's "reject expired/consumed and offer a fresh one" is supported by these columns ✓.

**Gaps:**
- **No cleanup/pruning of consumed or expired tokens (MEDIUM).** There is no sweep analogous to `CompleteExpiredTrips`. The table accrues dead rows indefinitely. Low urgency at beta volume but unbounded and unstated. Add a scheduled prune (delete where `consumed_at IS NOT NULL OR expires_at < now()` older than a small retention window).
- **Prior tokens not invalidated on re-request (LOW).** Requesting a new magic link inserts a new row while older unconsumed tokens remain valid until expiry, so multiple live links can authenticate. For a passwordless-only product this is a (small) replay surface. Consider invalidating a user's outstanding unconsumed tokens when a new one is issued.
- Not strictly data-integrity, but flag: magic-link request endpoint needs rate-limiting (email-bomb / enumeration) — out of scope here, noted for the auth review.

---

## 6. Cost-control invariant (geocode once; weather once per trip per send-day)

**Geocode once — adequately enforced (PASS, minor note).** AD-8 makes `latitude`/`longitude`/`canonical_place_name` `NOT NULL`, set exactly once at creation, never recomputed at send. Crucially, FR-12 exposes only view/add/pause/resume/delete — **there is no edit-destination feature** — so there is no code path that could re-geocode an existing trip. Enforcement is by (a) not-null persisted columns and (b) the structural absence of an edit path, rather than a DB constraint, but for v1 that is sufficient. Note: if trip-destination editing is ever added, geocode-once breaks and must re-acquire coordinates deliberately.

**Weather once per trip per send-day — enforced across workers, VIOLATED within the retry loop (MEDIUM).**
- *Across jobs/workers:* the claim-first `(trip_id, send_date)` insert means only one job per trip per `send_date` ever proceeds past the claim to call `WeatherProvider.fetch`. So the same constraint that gives idempotency also enforces one-fetch-per-trip-per-day at the dispatch level. This is the strongest enforcement in the design and worth crediting explicitly.
- *Within a job:* the send pipeline diagram routes the retry as `Send --fail ≤3x--> Fetch` — i.e., a **delivery** failure re-enters at **Fetch**, re-calling the weather API. A single send-day can therefore incur up to **3 weather fetches**, directly contradicting the PRD Cost-control NFR ("weather is fetched once per Trip per send day") and risking the forecast *changing mid-retry* (the snapshot logged may differ from what an earlier attempt would have sent).

**Fix:** fetch weather once, persist the snapshot onto the already-claimed `sending` row (AD-9 already carries `weather_snapshot`), then retry **delivery only** — the retry edge should loop `Send → Send` (or `Render → Send`), never back to `Fetch`. This makes the data model genuinely enforce the cost invariant and removes mid-retry forecast drift.

---

## Findings summary (by severity)

| # | Sev | Area | Finding | Fix |
|---|-----|------|---------|-----|
| 1 | HIGH | AD-3/AD-9 | Crash after claim leaves row stuck in `sending`; trip cannot send that day and the constraint blocks legitimate same-day retry — contradicts AD-3's "crash-rerun" claim; `sending` is also an unreadable admin state | State the lost-send-on-crash behavior + fix AD-3 wording; add stale-claim reclaim (lease/timestamp) and a stale-`sending` alert/sweep |
| 2 | HIGH | FR-8 feedback | No `unique(trip_id, send_date)`; GET-based signed link is non-idempotent → prefetch/re-click duplicates and contradictory reactions pollute the PRIMARY metric SM-1 | Add `unique(trip_id, send_date)` + upsert (last reaction wins, re-click idempotent); states change-of-mind = allowed |
| 3 | HIGH | FR-12 delete | Cascade of trip deletion to `email_logs`/`feedback` unstated; hard delete conflicts with AD-9 (source-of-truth) and erases SM metrics | Soft-delete Trip (`deleted_at`, excluded from cadence) preserving logs/feedback — or explicit `ON DELETE CASCADE` with documented metric loss |
| 4 | MEDIUM | Cost-control / AD-4 | Retry loops back to `Fetch`, so a delivery failure re-fetches weather (up to 3×/day) — violates "weather once per trip per send day" and risks mid-retry forecast drift | Fetch once → persist snapshot on the `sending` row → retry delivery only (`Send → Send`) |
| 5 | MEDIUM | AD-6 login_tokens | No pruning of consumed/expired tokens (unbounded growth); prior tokens not invalidated on re-request | Scheduled prune sweep; optionally invalidate outstanding unconsumed tokens on new request |
| 6 | LOW–MED | AD-5 state machine | All triggers covered & no unreachable state, but `completed` not declared terminal, no explicit forbidden/idempotent-noop transition table, no `default active` on the enum | Add explicit transition table (allowed/forbidden/noop) + `default active` to AD-5/ERD |

**Positive notes:** AD-10 atomic User+Trip create with not-null `user_id` is clean (no orphan/half-create path). The claim-first unique index is a strong, correct seam that simultaneously delivers idempotency AND the cross-worker half of the cost invariant. Geocode-once is structurally safe given the absence of a destination-edit feature.
