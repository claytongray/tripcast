---
baseline_commit: 9d75370
---

# Story 4.2: Narrator port + day-over-day narration (enhancement-only)

Status: review

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

As a traveler,
I want a short calm line when the forecast notably changes,
so that I get reassurance without ever a broken or delayed email.

## Design decision (2026-06-30): deterministic-first, Claude in shadow

The narration is reached through a **`Narrator` port** (AD-17), with **two adapters** behind it plus a shared deterministic detector. This **softens AD-17's AI-by-default** assumption (recorded like the prior sprint-change notes):
- **`DeterministicNarrator` ships live** — diffs prior vs current stored snapshot, finds the notable day-over-day change (precip swing / temp swing), and templates a calm sentence. This is the line that goes in the email.
- **`ClaudeNarrator` runs in shadow** — Haiku, official Anthropic PHP SDK (`anthropic-ai/sdk`, **user-approved** dependency), grounded **only** on the same computed deltas (never raw weather). Its line is **logged alongside, not sent**.
- A **`narrator:compare` command** prints both side-by-side over real snapshot pairs.
See [[narrator-approach]] (memory) and the existing AD-1/AD-17 port pattern.

## Acceptance Criteria

**AC1 — A calm, grounded day-over-day line renders, built after snapshot + before render** *(FR-16, AD-17, UX-DR5, UX-DR16)*
- **Given** the `Narrator` port (bound to the live `DeterministicNarrator`) and a **prior-day snapshot** available for the same trip
- **When** a digest is built **after the snapshot is secured/persisted (AD-3) and before the final render**
- **Then** it renders a **brief narration** grounded **only** in the stored prior+current snapshots, in the never-alarmist calm-concierge voice (e.g. "Since yesterday, Thursday's rain chance dropped from 60% to 20%"), placed in the UX-DR5 narration slot of the HTML **and** the plain-text twin. It **never invents figures** — every number comes from the snapshots.

**AC2 — No prior / timeout / error → line omitted, digest sends normally; never on the delivery path** *(FR-16, AD-17)*
- **Given** no prior snapshot, a timeout, or a generation error
- **When** the digest builds
- **Then** the line is **omitted and the digest sends normally**. Narration is **not** part of the idempotency claim (AD-3) and **not** part of the bounded delivery retry (AD-4); it **never re-fetches weather**, never fails or delays the send beyond its timebox. The shadow `ClaudeNarrator` call is **strictly off the delivery path** — its result is logged for comparison, never blocks or breaks the send.

## Tasks / Subtasks

- [x] **Task 1 — Dependency + config** (AC: 1, 2)
  - [x] `composer require "anthropic-ai/sdk:^0.32.0"` (**approved**). Add a `narration` block to `config/tripcast.php`: `model` (`env('TRIPCAST_NARRATION_MODEL', 'claude-haiku-4-5')` — alias, no date suffix), `api_key` (`env('ANTHROPIC_API_KEY')`, nullable → no shadow call when unset), `timeout` (`max(1, (int) env('TRIPCAST_NARRATION_TIMEOUT', 5))` seconds), `shadow` (`(bool) env('TRIPCAST_NARRATION_SHADOW', false)` — gates the inline shadow call so prod sends stay fast unless enabled), and `notable` thresholds (`precip` default 25, `temp` default 10, both floored at 1).
- [x] **Task 2 — The port + value objects + shared detector** (AC: 1, 2)
  - [x] `app/Services/Narration/Narrator.php` — interface `narrate(NarrationContext $context): ?string` (AD-1/AD-17 port; code depends on this).
  - [x] `app/Services/Narration/NarrationContext.php` — readonly value object: `?array $priorSnapshot`, `array $currentSnapshot` (both the `{days, limited}` shape), `bool $celsius`, `string $departureDate`, `string $returnDate`.
  - [x] `app/Services/Narration/NarrationDelta.php` — `string $date`, `string $dayLabel`, `string $metric` (`'rain'|'high'`), `int $from`, `int $to`; `magnitude(): int`.
  - [x] `app/Services/Narration/NarrationDiffer.php` — `diff(NarrationContext): list<NarrationDelta>`. For each calendar date present in **both** prior and current snapshots **and** within `[departureDate, returnDate]`: compare `precipChance` (notable if both non-null and `|Δ| >= notable.precip`) and the high temp in the owner's unit (`highF`/`highC`, notable if `|Δ| >= notable.temp`). Return deltas sorted by `magnitude()` desc. `dayLabel` = `CarbonImmutable::parse($date)->format('l')`. This is the **single grounded detector both adapters use** — neither invents figures.
- [x] **Task 3 — The two adapters** (AC: 1, 2)
  - [x] `app/Services/Narration/DeterministicNarrator.php implements Narrator` (ctor `NarrationDiffer`): `narrate` → top delta → calm template, or `null` when no deltas. Templates (verbatim voice): rain → `"Since yesterday, {day}'s rain chance {dropped|climbed} from {from}% to {to}%."`; high → `"Since yesterday, {day}'s high {cooled|warmed} from {from}{°F|°C} to {to}{°F|°C}."` Never alarmist.
  - [x] `app/Services/Narration/ClaudeNarrator.php implements Narrator` (ctor `NarrationDiffer`): `narrate` → compute deltas; if empty **return null (no API call)**; if `config('tripcast.narration.api_key')` null → return null. Else build a grounded prompt listing **only** the computed deltas (day, metric, from→to) + a never-alarmist system instruction, call `new \Anthropic\Client(apiKey:, requestOptions: ['timeout' => config timeout, 'maxRetries' => 0])->messages->create(model: config model, maxTokens: 120, system: …, messages: [['role'=>'user','content'=>…]], requestOptions: ['timeout' => …, 'maxRetries' => 0])`, read `$message->content[0]->text`, trim. **Wrap the whole call in `try/catch (\Throwable)`** → log a warning + return null (timeout/error → omit). Vendor SDK appears **only** here (AD-17). The system prompt forbids inventing any figure not in the deltas and constrains to one calm sentence.
  - [x] Bind the port in `AppServiceProvider`: `$this->app->bind(Narrator::class, DeterministicNarrator::class)` — the **live** adapter (AD-17 enhancement, instant, no network).
- [x] **Task 4 — Wire into `SendTripDigest` (after snapshot, before render)** (AC: 1, 2)
  - [x] In `app/Jobs/SendTripDigest.php@handle`, **after** `$log->update(['weather_snapshot' => $snapshot])` and **before** `$this->deliver(...)`: load the **prior** snapshot — the most recent `email_logs` row for this trip with `send_date < $this->sendDate` and a non-null `weather_snapshot` (`->orderByDesc('send_date')`). Build a `NarrationContext`. Resolve the bound `Narrator` and get the **live line** inside a `try/catch (\Throwable)` (any failure → null; never breaks the send — AD-17). Pass the line into `DigestMail`.
  - [x] **Shadow:** when `config('tripcast.narration.shadow')` is true, also resolve `ClaudeNarrator` and call it on the same context inside its own guard; `Log::info('narrator:compare', ['trip_id'=>…, 'send_date'=>…, 'deterministic'=>$live, 'claude'=>$shadow])`. The shadow result is **logged only, never rendered/sent**, and its failure never affects the send. (Both calls are off the AD-3 claim and AD-4 retry — they run once here, never re-fetch weather.)
- [x] **Task 5 — Render the line (HTML + text twin, UX-DR5 slot)** (AC: 1)
  - [x] `DigestMail`: add a constructor param `?string $narration = null`; pass it to both views as `'narration' => $this->narration`. (Re-render tolerance: a stale-row re-render may regenerate or omit — accepted cosmetic variance per AD-17; `DigestMail` stays a pure function of its inputs.)
  - [x] `resources/views/emails/digest.blade.php` and `digest-text.blade.php`: render the narration in the **UX-DR5 slot** (a calm line near the countdown/forecast) **only when present** (`@if($narration)`), in the calm style — HTML and the plain-text twin both.
  - [x] `SendTripDigest@deliver` already constructs `new DigestMail($this->trip, $snapshot, $this->sendDate)` inside the retry loop — thread the narration string through so the rendered body consumes it (compute the line **once** before the loop; the bounded delivery retry must **not** re-run narration, AD-4).
- [x] **Task 6 — `narrator:compare` console command** (AC: shadow/compare)
  - [x] `app/Console/Commands/CompareNarrators.php` (`#[Signature('narrator:compare {--trip=} {--limit=20}')]`): for trips with **≥2** non-null snapshots, take the latest consecutive `(prior, current)` snapshot pair from `email_logs`, build a `NarrationContext`, and print the **deterministic** vs **Claude** line side-by-side (table). A read-only evaluation tool — no sends, no DB writes. Skips trips without a prior snapshot; honors `--trip` to scope to one.
- [x] **Task 7 — Tests** (AC: 1, 2)
  - [x] `tests/Unit/Narration/NarrationDifferTest.php`: notable precip swing detected; notable temp swing detected; **sub-threshold change → no delta**; a day **outside the trip window** is ignored; a date present in only one snapshot is ignored; null values ignored; deltas sorted by magnitude; respects configurable thresholds; celsius vs fahrenheit picks the right high field.
  - [x] `tests/Unit/Narration/DeterministicNarratorTest.php`: rain-drop/-climb and temp-cool/-warm phrasings exact; no prior or no notable change → null; the line never contains a figure absent from the snapshots.
  - [x] `tests/Feature/Narration/ClaudeNarratorTest.php`: no deltas → null + **no API call**; no api_key configured → null. (The live HTTP path is exercised via `narrator:compare`, not unit-tested — it's the shadow path.)
  - [x] `tests/Feature/Digest/SendTripDigestTest.php` (extend): with a **prior** snapshot that differs notably, the sent `DigestMail` carries the narration line (assert via `Mail::fake()` / the mailable's `narration`); with **no prior** snapshot the digest still sends with no line; a narrator that **throws** never fails the send (mock the bound `Narrator` to throw → job still reaches terminal `sent`). Shadow off by default → no `ClaudeNarrator`/API in the normal path.
  - [x] `tests/Feature/Digest/DigestMailTest.php` (extend): the HTML and text views render the narration line when present and omit it when null.
  - [x] **Gates:** `./vendor/bin/pest`, `vendor/bin/pint --dirty --format agent`, `./vendor/bin/phpstan analyse`, `npm run types:check`, `npm run lint:check`, `npm run build:ssr` (frontend unchanged).

## Dev Notes

### Scope boundary (read first)
- **In scope:** the `Narrator` port, the shared deterministic detector, both adapters, the live wiring + shadow logging, the rendered line (HTML + text), the compare command, tests. **Out of scope / forbidden:** narration on the AD-3 claim or AD-4 retry path; re-fetching weather; persisting the narration text (it's derivable from snapshots — no `email_logs` shape change, AD-17); the promo slot (Epic 5). Frontend (Vue) is untouched — this is Blade email only. [Source: epics.md#Story-4.2; ARCHITECTURE-SPINE.md#AD-17]

### Architecture (binding)
- **AD-17 — AI narration via a Narrator port, enhancement-only, never on the delivery path:** "narration is reached through a new **`Narrator` port** bound to a concrete adapter in a `ServiceProvider`, exactly like AD-1 — the vendor SDK/HTTP appears **only in the adapter**. Generation runs **inside `SendTripDigest`, after the forecast snapshot is secured/persisted (AD-3) and before the final render** … not part of the idempotency claim (AD-3) and not part of the bounded delivery retry (AD-4). The call is **time-boxed** … on timeout, error, or a missing prior snapshot the line is **omitted and the digest sends normally**. Narration must never *fail* the send, re-fetch weather, or enter the AD-4 retry loop. The narration text is **not separately persisted** … fully derivable from the persisted prior+current snapshots … Output is **grounded strictly** in the stored snapshots passed to it (the model never invents figures) and constrained to the never-alarmist calm-concierge voice." The deterministic adapter satisfies every clause trivially (no network); the shadow Claude adapter is held to the same off-path discipline. [Source: ARCHITECTURE-SPINE.md#AD-17, line 149]
- **AD-9 — narration is a read-only consumer of `email_logs`:** "FR-16 narration reads the prior `send_date`'s snapshot for the same trip from here." No mutation; tolerate a purged (snapshot-absent) row. [Source: ARCHITECTURE-SPINE.md#AD-9, line 109]

### Code intel (exact patterns to reuse)
- **Snapshot shape** (`email_logs.weather_snapshot`, cast `array`): `{days: list<{date, conditionText, precipChance, highC, highF, lowC, lowF}>, limited: bool}`. Per-day `precipChance` is an int percent (or null); highs are floats in both units (or null); `date` is `Y-m-d` destination-local. [Source: app/Services/Weather/ForecastDay.php, Forecast.php]
- **`SendTripDigest@handle`** secures the snapshot with `$log->update(['weather_snapshot' => $snapshot])`, then calls `$this->deliver($log, $snapshot)`. Insert narration **between** these. `deliver()` builds `new DigestMail($this->trip, $snapshot, $this->sendDate)` inside a `for` retry loop — compute the narration **once** before the loop and pass it in (AD-4: retry is delivery-only, never re-narrate). `tries = 1`. [Source: app/Jobs/SendTripDigest.php]
- **Prior snapshot query:** `EmailLog::query()->where('trip_id', $trip->id)->where('send_date', '<', $sendDate)->whereNotNull('weather_snapshot')->orderByDesc('send_date')->first()?->weather_snapshot`. The `weather_snapshot` cast returns the array (or null). [Source: app/Models/EmailLog.php]
- **`DigestMail`** is a pure mailable: ctor `(Trip $trip, array $snapshot, string $sendDate)`, `content()` passes a `with: [...]` map to `emails.digest` + `emails.digest-text`. Add `?string $narration` and a `'narration'` key. The owner's unit is `$trip->user->temperature_unit === User::UNIT_CELSIUS`. [Source: app/Mail/DigestMail.php]
- **Port binding** mirrors `AppServiceProvider`'s `Geocoder`/`WeatherProvider` binds (interface → concrete, env/config-keyed). Bind `Narrator` → `DeterministicNarrator`. [Source: app/Providers/AppServiceProvider.php]
- **PHP SDK** (`anthropic-ai/sdk` ^0.32): `new \Anthropic\Client(apiKey: $key, requestOptions: ['timeout' => $s, 'maxRetries' => 0])`; `$client->messages->create(model: …, maxTokens: 120, system: …, messages: [['role'=>'user','content'=>$prompt]], requestOptions: ['timeout' => $s, 'maxRetries' => 0])`; text at `$message->content[0]->text`. Model id `claude-haiku-4-5` (alias — **never** append a date suffix). No `thinking`/`effort`/sampling params (Haiku 4.5 + a trivial task — keep the request minimal). [Source: anthropic-sdk-php README; claude-api skill]
- **Command style:** `#[Signature(...)]` + `#[Description(...)]` attributes, `handle()` returns `self::SUCCESS` (see `SendDailyDigests`, `SendDigest`). [Source: app/Console/Commands/]

### Testing standards
- Pest, `RefreshDatabase`, pinned ET clock. Seed `email_logs` snapshots via `$trip->emailLogs()->create([... 'weather_snapshot' => [...]])`. The differ/deterministic adapter are pure → unit tests in `tests/Unit/Narration`. For the job test, mock the bound `Narrator` (`$this->mock(Narrator::class)`) to return a fixed line or throw. **Never** hit the real Anthropic API in tests — `ClaudeNarrator` tests assert the null guards only (no key / no deltas); the configured-key live path is the compare command's job. Use `Mail::fake()` and assert on the captured `DigestMail`'s `narration` property. [Source: tests/Feature/Digest/SendTripDigestTest.php, DigestMailTest.php]

### Project Structure Notes
- **New:** `app/Services/Narration/{Narrator,NarrationContext,NarrationDelta,NarrationDiffer,DeterministicNarrator,ClaudeNarrator}.php`, `app/Console/Commands/CompareNarrators.php`, `tests/Unit/Narration/*`, `tests/Feature/Narration/ClaudeNarratorTest.php`.
- **Modified:** `composer.json`/`composer.lock` (the approved SDK), `config/tripcast.php` (`narration` block), `app/Providers/AppServiceProvider.php` (bind), `app/Jobs/SendTripDigest.php` (compute + pass + shadow-log), `app/Mail/DigestMail.php` (`narration` param), `resources/views/emails/digest.blade.php` + `digest-text.blade.php` (slot), `tests/Feature/Digest/SendTripDigestTest.php` + `DigestMailTest.php`.
- **Unchanged:** `email_logs` schema (AD-17 adds no column), the Vue frontend, the weather port.

### Previous story intelligence (4.1 + Epic 2)
- 4.1 confirmed the `email_logs` snapshot series is the diffable history and bounded it — 4.2 is the first **reader** of the prior snapshot; tolerate a purged (null) prior gracefully (→ no line). `now()` is `CarbonImmutable` app-wide (type any clock param `CarbonInterface`). Keep the narration call wrapped so it can **never** break the send — same discipline as AD-4's never-broken-digest and 2.7's monitoring-never-breaks-the-run. [Source: 4-1; app/Jobs/SendTripDigest.php]
- This **softens AD-17's "AI by default"** to deterministic-live + Claude-shadow — record a one-line spec note (sprint-change style) and see [[narrator-approach]].

### References
- [Source: _bmad-output/planning-artifacts/epics.md#Story-4.2]
- [Source: _bmad-output/planning-artifacts/architecture/architecture-tripcast-2026-06-28/ARCHITECTURE-SPINE.md#AD-17, #AD-9, #AD-1]
- [Source: _bmad-output/specs/spec-tripcast/SPEC.md#FR-16]
- [Source: app/Jobs/SendTripDigest.php; app/Mail/DigestMail.php; app/Services/Weather/ForecastDay.php; app/Providers/AppServiceProvider.php; anthropic-sdk-php]

## Dev Agent Record

### Agent Model Used

claude-opus-4-8 (1M context)

### Debug Log References

- The published SDK max is `anthropic-ai/sdk` **v0.31** (not 0.32 as the README's install snippet showed) — required `^0.31.0`.
- PHP SDK surface (verified against the installed vendor): `new Anthropic\Client(apiKey:, requestOptions: ['timeout'=>s, 'maxRetries'=>0])`; `$client->messages->create(maxTokens:, messages:, model:, system:, requestOptions:)`; text via `instanceof Anthropic\Messages\TextBlock` → `->text` (phpstan-safe).
- Prior snapshot from the `weather_snapshot` array cast is `array<string,mixed>` — typed `NarrationContext`/`NarrationDiffer` to that (with a `days` guard) so phpstan level 7 passes.
- Pest global-helper collisions: renamed `context()` → `diffCtx()` (clashed with Laravel's `context()` helper); `tripDetails()` pattern avoided.
- The plain-text digest twin must use `{!! $narration !!}` — `{{ }}` HTML-escaped the apostrophe (`Friday&#039;s`) into the text body.

### Completion Notes List

- **Deterministic-first hybrid (softens AD-17):** the `Narrator` port is bound to `DeterministicNarrator` (live, no network, can't invent figures); `ClaudeNarrator` (Haiku, official PHP SDK — **user-approved** dep) runs in shadow, grounded on the same computed deltas, logged not sent. Both share `NarrationDiffer` (precip/temp swing detection, configurable thresholds, trip-window + shared-date filtering, magnitude sort).
- **Pipeline (AD-17):** `SendTripDigest@handle` computes the line **after** the snapshot persists and **before** `deliver()` — once, outside the delivery retry; reads the prior send's snapshot (AD-9, read-only). Every narrator call is wrapped so any failure → null line, never a failed/delayed send. Shadow (`config tripcast.narration.shadow`, default off) logs `narrator:compare` with both lines.
- **Render:** `DigestMail` gained `?string $narration`; the HTML slot (UX-DR5) and the plain-text twin render it only when present.
- **`narrator:compare`** command prints deterministic vs Claude over each trip's latest snapshot pair (read-only eval).
- **Tests:** `NarrationDifferTest` (9), `DeterministicNarratorTest` (6), `ClaudeNarratorTest` (2 off-path guards — never hits the API), `SendTripDigestTest` +3 (line attached on notable change, omitted with no prior, send survives a throwing narrator), `DigestMailTest` +2 (HTML/text render + omit). 22 new.
- **Verification:** full suite **217 passed** (778 assertions). `pint` clean, `phpstan` 0 errors, `types`/`lint`/`build:ssr` green. No `email_logs` shape change (AD-17); narration is derived, not persisted.

### File List

**New:**
- `app/Services/Narration/Narrator.php`, `NarrationContext.php`, `NarrationDelta.php`, `NarrationDiffer.php`, `DeterministicNarrator.php`, `ClaudeNarrator.php`
- `app/Console/Commands/CompareNarrators.php`
- `tests/Feature/Narration/{NarrationDifferTest,DeterministicNarratorTest,ClaudeNarratorTest}.php`

**Modified:**
- `composer.json` / `composer.lock` (approved `anthropic-ai/sdk` ^0.31)
- `config/tripcast.php` (`narration` block)
- `app/Providers/AppServiceProvider.php` (bind `Narrator` → `DeterministicNarrator`)
- `app/Jobs/SendTripDigest.php` (compute line after snapshot + before render; shadow log)
- `app/Mail/DigestMail.php` (`narration` param)
- `resources/views/emails/digest.blade.php` + `digest-text.blade.php` (UX-DR5 slot)
- `tests/Feature/Digest/SendTripDigestTest.php` + `DigestMailTest.php`

### Change Log

- 2026-06-30 — Implemented Story 4.2: day-over-day narration via a `Narrator` port, enhancement-only. A deterministic narrator ships the live calm line (built after the snapshot is secured, before render; grounded strictly in stored snapshots; omitted on no-prior/error and never failing or delaying the send); a Claude (Haiku) adapter runs in shadow behind the same port for comparison, with a `narrator:compare` command. Softens AD-17's AI-by-default to deterministic-live + Claude-shadow. All gates green.
