# Deferred Work

## Deferred from: code review of story-1.1 (2026-06-29)

- Attacker can invalidate a victim's pending magic link by re-requesting it — every new request hard-deletes prior unconsumed tokens for that email (`app/Actions/RequestMagicLink.php:33`). Inherent to AC4's invalidate-prior-tokens rule and bounded by the per-email throttle; revisit only if abuse is observed.
- Frontend chrome remnant — the shared Inertia `sidebarOpen` prop and related starter-kit cruft (`app/Http/Middleware/HandleInertiaRequests.php`). Deferred to the Group B (frontend) review pass, which covers the Vue/UI surface of story 1.1.

## Deferred from: Epic 1 batch code review (2026-06-29)

- **Trip-level dedup** (story 1.4, `app/Actions/CreateTrip.php`) — no idempotency key/unique constraint on trips, so a true concurrent double-POST could insert duplicate trips. Mitigated now by the per-IP route throttle, immediate session-clear on commit, and the client `form.processing` disable. A DB unique on `(user_id, canonical_place_name, departure_date, return_date)` has soft-delete (re-add after delete) implications — revisit if duplicates are observed.
- **`firstOrCreate` user race** (story 1.4, `app/Actions/CreateTrip.php`) — SELECT-then-INSERT can hit the CI-unique email index under concurrent same-email submits (uncaught QueryException). Low likelihood; fix by catching the unique violation and re-fetching. (Tracked as an open action item on story 1.4.)
- **`WelcomeMail` + `SerializesModels`** (story 1.5, `app/Mail/WelcomeMail.php`) — if a trip is soft-deleted between queue and processing, model re-fetch throws `ModelNotFoundException`. No soft-delete path exists until Story 3.1; revisit then (pass scalars or tolerate the missing model).
