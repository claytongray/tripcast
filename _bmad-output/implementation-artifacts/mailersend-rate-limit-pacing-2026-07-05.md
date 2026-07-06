# Incident fix: MailerSend 429 on the 7am digest send — dispatch pacing + 429 cooldown

**Date:** 2026-07-05 · **Type:** production incident fix (email delivery reliability) · **Trigger:** a prod recipient's daily digest failed with `429 Too Many Requests` from MailerSend during the 07:00 ET run.

## Root cause (systematic-debugging, evidence-based)

MailerSend caps `POST /v1/email` at **120 requests/min** (general endpoints 60/min, bulk 10/min); exceeding it returns `429` + a `retry-after` header.

Two defects on the send path combined to drop an email:

1. **No send spacing (caused the 429).** `SendDailyDigests` dispatched one `SendTripDigest` job per due trip *immediately, un-spaced* (`foreach … dispatch()`), and a **single** Forge worker drained them back-to-back — each job one synchronous MailerSend call (~300ms), i.e. ~180/min — sailing past the 120/min ceiling once enough emails were due.
2. **Tight-loop retry (dropped the email).** `SendTripDigest::deliver()` retried a failed send up to 3× in a `for` loop with **no backoff and no `retry-after` respect**, so a *transient* 429 got hammered two more times and the row was marked `failed`. That is the recipient whose email "failed" — lost to our retry giving up, not to the limit itself.

There was **no throttling anywhere** on the path prior to this fix.

## Constraint that shaped the fix

`SendTripDigest` is `tries = 1` on purpose — the claim-first `email_logs` row is the dedup authority (AD-3), "the queue must never re-dispatch." The textbook `Redis::throttle` **job middleware** works by *releasing the job back to the queue* when over the cap, which re-runs it; `claim()` would then see its own fresh `sending` row, abort, and **drop the email**. So the throttle had to live at **dispatch time**, not as release-based middleware.

Also: the vendor transport (`MailerSendTransport::send`) catches `MailerSendRateLimitException` and re-throws a generic Symfony `TransportException` that preserves only the message and **code 429** — the real `retry-after` value is **unreachable**. The cooldown is therefore a fixed configured backoff, and 429 detection is by `getCode() === 429` across the cause chain.

## Change

- **Dispatch pacing** — `SendDailyDigests::handle()` dispatches each job with `->delay(intdiv($i * 60, $ratePerMinute))`, so no more than `SEND_MAX_RATE_PER_MINUTE` (default **100**, ~17% headroom under 120) become due in any 60s window. Jobs stay `tries = 1`; this is a dispatch delay, not a retry. Primary guard.
- **429 cooldown** — `SendTripDigest::deliver()` calls `Sleep::for(SEND_RATE_LIMIT_BACKOFF_SECONDS)->seconds()` (default **10**) before retrying *only* on a rate-limit failure and *only* when an attempt remains, letting the window drain instead of tight-looping. Recovery net. Bounded well under the 60s worker timeout even across all delivery attempts.
- **Config** — `config/tripcast.php` `send.max_rate_per_minute` and `send.rate_limit_backoff_seconds`, both env-overridable, both floored.

Files: `app/Console/Commands/SendDailyDigests.php`, `app/Jobs/SendTripDigest.php`, `config/tripcast.php`.

## Tests (3 added, all green; 121 pass across digest/mail/trip surface)

- `SendDailyDigestsTest` — "paces dispatch to stay under the MailerSend per-minute rate limit" (asserts staggered `->delay` values at a known rate).
- `SendTripDigestTest` — "pauses and retries on a 429, then delivers successfully" (`Sleep::fake()` + a transient 429 → `sent`, slept once).
- `SendTripDigestTest` — "does not pause between retries for a non-rate-limit error" (regression guard: ordinary failures still fast-fail, never sleep).

## Tuning knobs (prod env)

- `SEND_MAX_RATE_PER_MINUTE` (default 100) — raise toward (never to) 120 to drain faster; lower to be more conservative.
- `SEND_RATE_LIMIT_BACKOFF_SECONDS` (default 10) — worker pause on a 429; keep `attempts × this` comfortably below the 60s worker timeout.
