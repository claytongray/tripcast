# Deferred Work

## Deferred from: code review of story-1.1 (2026-06-29)

- Attacker can invalidate a victim's pending magic link by re-requesting it — every new request hard-deletes prior unconsumed tokens for that email (`app/Actions/RequestMagicLink.php:33`). Inherent to AC4's invalidate-prior-tokens rule and bounded by the per-email throttle; revisit only if abuse is observed.
- Frontend chrome remnant — the shared Inertia `sidebarOpen` prop and related starter-kit cruft (`app/Http/Middleware/HandleInertiaRequests.php`). Deferred to the Group B (frontend) review pass, which covers the Vue/UI surface of story 1.1.
