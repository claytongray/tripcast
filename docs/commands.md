# Artisan commands

Custom commands for the tripcast digest pipeline. Run `php artisan list` to see
everything; this documents the project-specific ones and when to reach for each.

> ⚠️ **Two similarly-named commands — don't confuse them.**
> - `digests:send` (plural) — the **production daily run** (scheduled). Selects due trips and dispatches send jobs. Writes `email_logs`.
> - `digest:send` (singular) — a **manual, single-trip test send**. Bypasses cadence and the claim; writes **no** `email_logs` row.

---

## `digests:send` — the daily run (production)

```
php artisan digests:send
```

The scheduled morning run (AD-2/AD-7). In one invocation it:

1. Selects every trip **due** a digest today via the single cadence predicate (AD-11).
2. Dispatches one `SendTripDigest` queue job per due trip (claim-first idempotency, AD-3).
3. Runs the forecast-history **retention purge** (`PurgeForecastHistory`, AD-16) — nulls `weather_snapshot` ~30 days after each trip's return date.
4. Records a run outcome and **emits a liveness heartbeat** (AD-14).

- **Scheduled** in `routes/console.php` at **09:00 America/New_York** daily (`name('send-daily-digests')`). You normally never run it by hand.
- Per-trip sending happens **on the queue** — make sure a worker is running (`php artisan queue:work`) to actually deliver.
- Exit code is non-zero on an unhealthy run (a select/dispatch failure, or due-but-nothing-dispatched).

**Relevant env** (see `config/tripcast.php`): `TRIPCAST_HEARTBEAT_URL`, `TRIPCAST_HEARTBEAT_TIMEOUT`, `TRIPCAST_FORECAST_RETENTION_DAYS`, `TRIPCAST_FORECAST_HORIZON_DAYS`, `SEND_MAX_DELIVERY_ATTEMPTS`, `SEND_STALE_LEASE_MINUTES`. A null heartbeat URL is a silent no-op (local/dev).

---

## `digest:send` — manual single-trip send (testing/QA)

```
php artisan digest:send {trip}
                        {--to=}    # override recipient (default: the trip owner)
                        {--date=}  # send-clock date Y-m-d (default: today, America/New_York)
```

Renders and sends the **exact** `DigestMail` a user would receive for one real trip, to any address. **Does not** touch cadence, the claim, or `email_logs`, so it never interferes with the real daily run.

```
php artisan digest:send 42 --to=me@example.com --date=2026-07-03
```

- Fetches a **live** forecast for the trip's coordinates (real `WeatherProvider`).
- Requires a real, persisted trip (use a trip ID from the DB).
- Mail goes wherever `MAIL_*` points (Mailtrap in dev).

---

## `digests:preview` — sample render-state digests (dev)

```
php artisan digests:preview {--email=preview@tripcast.test}
```

Emails **three** sample digests so you can eyeball the forecast-window rendering
states. Dates are computed from the current date:

| Trip | Window | What it shows |
|---|---|---|
| Ocean City, NJ | this weekend | trip fully within the window → **full forecast** |
| Columbus, OH | next-week 7-day trip | partly beyond the window → some days + a **collapsed "forecast appears once these days are within N days"** line |
| Asheville, NC | 6 days out | only the **first day** is in the window; the rest collapse into the future line |

- **Synthetic** weather spanning exactly `tripcast.forecast.horizon_days` — the live WeatherAPI free tier only returns ~3 days, which can't exercise these boundaries.
- Builds and mails the digests from **in-memory models** — no DB writes, nothing to clean up.
- **Refuses to run in production.**
- The "Overview" narration line uses a simulated prior day (the forecast figures are what's being tested).

```
php artisan digests:preview --email=you@example.com
```

---

## `digests:conditions` — weather-icon reference sheet (dev)

```
php artisan digests:conditions {--email=preview@tripcast.test}
```

Emails **one** reference sheet listing **every** WeatherAPI condition with the
icon the digest maps it to (via `App\Digest\WeatherEmoji`), so you can eyeball the
emoji coverage in Mailtrap in one place.

- The condition catalog is WeatherAPI's published `weather_conditions.json` (60 daytime conditions), **pinned** in the command — no network needed.
- Prints a CLI **warning** listing any condition left without an icon (none, currently).
- **Refuses to run in production.**

```
php artisan digests:conditions --email=you@example.com
```

---

## `narrator:compare` — deterministic vs Claude narration (eval, read-only)

```
php artisan narrator:compare {--trip=}      # limit to one trip id
                             {--limit=20}   # max trips to compare
```

Prints the **deterministic** vs **Claude (shadow)** day-over-day narration line
side-by-side over each trip's latest consecutive `email_logs` snapshot pair. A
read-only evaluation tool — **no sends, no DB writes**.

- The Claude column only produces output when `ANTHROPIC_API_KEY` is set (otherwise it's blank). Model/timeout/thresholds: `TRIPCAST_NARRATION_MODEL` (default `claude-haiku-4-5`), `TRIPCAST_NARRATION_TIMEOUT`, `TRIPCAST_NARRATION_PRECIP_DELTA`, `TRIPCAST_NARRATION_TEMP_DELTA`.
- The live digest uses the **deterministic** narrator; Claude runs in shadow only when `TRIPCAST_NARRATION_SHADOW=true` (and is logged, never sent).

---

## Scheduled jobs (`routes/console.php`)

| Schedule | Command | Purpose |
|---|---|---|
| Daily 09:00 ET | `digests:send` | The daily digest run |
| Daily | `model:prune --model=…LoginToken` | Prune expired/consumed magic-link tokens (AD-6) |

Run the scheduler in production via `php artisan schedule:work` (or a cron entry calling `schedule:run`), and a queue worker (`php artisan queue:work`) for the per-trip send jobs.

---

## Sending mail locally

Test sends (`digest:send`, `digests:preview`) deliver through whatever `MAIL_*`
points at — Mailtrap (`sandbox.smtp.mailtrap.io`) in dev. Set `MAIL_MAILER=log`
to render into `storage/logs/laravel.log` instead of sending.

Weather: set `WEATHERAPI_KEY` for live forecasts; without it a deterministic
`FakeWeatherProvider` is used. Geocoding: `GOOGLE_GEOCODING_KEY` (else a
`FakeGeocoder`).
