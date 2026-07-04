---
id: SPEC-weatherkit-provider-swap
companions:
  - weatherkit-integration.md
sources: []
---

> **Canonical contract.** This SPEC and the files in `companions:` are the complete, preservation-validated contract for what to build, test, and validate. Source documents listed in frontmatter are for traceability only — consult them only if you need narrative rationale or prose color this contract intentionally omits.

# Weather provider swap — WeatherAPI → Apple WeatherKit

## Why

A **pain to solve** with an attached **mandate to meet**. Tripcast's digests render daily highs 5–8°F hotter than Apple Weather and AccuWeather on hot, humid inland days — one-directional, exactly when accuracy matters most. Investigation proved our extraction is faithful: the rendered high equals `round(day.maxtemp_f)`, and the inflation lives inside WeatherAPI's own `maxtemp_f` (heat-index bleed on extreme-heat days), so the fix belongs at the provider, not in our code. Apple WeatherKit *is* the Apple Weather data we benchmark against — global, alerts-capable, 500k calls/month free with the existing $99/yr Apple Developer membership — so adopting it corrects the bias at the source. The attached mandate: WeatherKit's license **requires** an Apple Weather attribution wherever its data is shown, so the attribution ships with the swap. Backdrop: pre-launch, so a hard cutover is safe. Affected: every digest recipient, and the operator who trusts the numbers.

## Capabilities

- **CAP-1 — Adapter swap behind the existing port**
  - **intent:** The system fetches forecasts from Apple WeatherKit through a new `WeatherKitProvider` implementing the existing `WeatherProvider` port, selected by config, with no change to downstream data or rendering.
  - **success:** With the provider config set to `weatherkit`, a digest renders from WeatherKit data producing the same `ForecastDay` shape (high/low in °F **and** °C, condition text, precip %, humidity, feels-like); with it unset, WeatherAPI is used; `Forecast`/`ForecastDay`, snapshot serialization, `ForecastRows`, and email templates are unchanged.

- **CAP-2 — Accurate daily high (the bug fix)**
  - **intent:** The rendered daily high reflects WeatherKit's air-temperature maximum for the destination-local day.
  - **success:** For a hot, humid inland destination the rendered high matches Apple Weather within rounding (e.g. Kennett Square Jul 4 ≈ 97°F, not 105°F); a cool coastal control is unchanged; no heat-index or feels-like value ever appears as the high.

- **CAP-3 — Metric→imperial conversion and unit scaling**
  - **intent:** The adapter converts WeatherKit's metric/SI values and 0–1 decimals into the DTO's dual-unit temperatures and integer percentages.
  - **success:** °C→°F is correct to rounding for high/low; `precipitationChance` and `humidity` (0–1) become 0–100 integers; a day missing any core value is marked limited rather than fabricated (preserves FR-7 semantics).

- **CAP-4 — Feels-like from the hourly apparent peak**
  - **intent:** The adapter sources feels-like from the peak hourly `temperatureApparent`, mirroring today's `peakFeelsLike` behavior.
  - **success:** `feelsLikeHighC/F` equal the maximum `temperatureApparent` hour converted to both units; when hourly data is absent the value is null and the day is **not** made limited.

- **CAP-5 — Condition-code mapping**
  - **intent:** WeatherKit's `conditionCode` enum maps to a human-readable label that feeds the existing `WeatherEmoji` unchanged.
  - **success:** Every documented `conditionCode` yields a non-empty label; representative codes (`PartlyCloudy`, `ScatteredThunderstorms`, `HeavyRain`, `MostlyClear`) resolve to the correct emoji through existing keyword matching; a label with no keyword match still renders text with an empty emoji.

- **CAP-6 — ES256 JWT authentication**
  - **intent:** The adapter authenticates each WeatherKit request with a cached ES256 JWT carrying the non-standard `id` header (`Team.Service`) and `iss`/`sub` claims.
  - **success:** Requests carry a Bearer token WeatherKit accepts; the token is reused within its lifetime and regenerated before expiry; signing and claim/header shape are proven by a unit test using a throwaway key; missing or invalid credentials surface as `WeatherProviderFailedException`.

- **CAP-7 — Destination timezone resolution**
  - **intent:** The system resolves the destination's IANA timezone from coordinates via the Google Time Zone API (existing key), caches it, and passes it as WeatherKit's `timezone` input so daily rollups align to destination-local days.
  - **success:** A known coordinate resolves to the correct IANA zone; the value is cached to avoid repeat calls; a resolver failure falls back to the config default `America/New_York` with a logged warning (never GMT, never aborting the send); the resolver is reusable to populate `trips.destination_timezone` for the timezone-aware-send-time feature.

- **CAP-8 — Apple Weather attribution in the digest**
  - **intent:** Every digest displaying WeatherKit data shows the Apple Weather mark and a link to Apple's legal attribution page.
  - **success:** The digest footer renders the Apple Weather logo (inlined, not hotlinked) with the required legal link whenever the active provider is WeatherKit; the mark and name are unaltered.

- **CAP-9 — Persist the destination timezone at trip creation (resolve once, reuse)**
  - **intent:** The destination IANA timezone is resolved and persisted **when the trip is created** (right after geocoding, where lat/long is already obtained), so it is ready before any send — including the welcome email that embeds the first tripcast.
  - **success:** Creating a trip writes a non-null `trips.destination_timezone` (new column) before the welcome email renders; every forecast and send thereafter reads the stored zone and makes **no** Time Zone API call; a trip that already has a stored zone never re-resolves; the send-time feature consumes this column rather than deriving zones itself.

## Constraints

- Adapter-only swap for the response contract: the `Forecast`/`ForecastDay` shape, snapshot serialization into `email_logs.weather_snapshot`, and the render pipeline must not change. The `WeatherProvider::fetchForecast` signature gains an optional `?string $timezone` param (caller passes the persisted zone; existing adapters ignore it) — the only allowed port change.
- WeatherKit returns metric/SI only with no unit parameter — the adapter must convert °C→°F and scale 0–1 decimals to percentages.
- `timezone` is effectively required: omitting it defaults daily rollups to GMT and misaligns the very daily high being corrected.
- The JWT must be ES256 **and** include the non-standard `id` header (`Team.Service`), or requests 401 — the dominant failure mode.
- Attribution is legally mandatory wherever WeatherKit data appears; the logo must be inlined because email clients strip remote images and CSS.
- Credentials live in env only (`APPLE_WEATHERKIT_TEAM_ID` / `SERVICE_ID` / `KEY_ID` / `PRIVATE_KEY`); the `.p8` is never logged or committed.
- The only new dependency permitted is `firebase/php-jwt`; no others.
- Hard cutover (pre-launch): build behind config, verify locally with `MAIL_MAILER=log`, then push — no shadow-compare harness.
- On timezone-resolution failure, fall back to the config default `America/New_York` (logged) — never GMT and never abort the send.
- `APPLE_WEATHERKIT_PRIVATE_KEY` holds a filesystem path to the `.p8` (read via `base_path`), not inline PEM; `*.p8` is git-ignored so the key is never committed.
- Stay within the 500k-calls/month free tier by caching the JWT and the resolved timezone.
- Preserve FR-7 "limited data, never fabricate" semantics across all mapped fields.

## Non-goals

- Weather alerts (Bug 2b) — a deliberate follow-up spec, even though WeatherKit can supply them.
- Condition/precipitation reconciliation such as "Sunny · 58% precipitation" (Bug 2a) — a separate spec; provider-independent.
- Building the timezone-aware-send-time scheduler (phase-aware 7am send clock) — this spec ships the `trips.destination_timezone` column and populates it (CAP-9), but the send-scheduling logic that consumes it is that feature's job.
- Retaining WeatherAPI as a long-term fallback or blending multiple providers.
- WeatherKit datasets beyond `forecastDaily` + `forecastHourly` (no `forecastNextHour`, AQI, minute precip).
- Editable temperature units or per-user provider selection.

## Success signal

Setting the provider config to WeatherKit and sending a digest for a hot, humid inland destination produces a daily high that matches Apple Weather within rounding — instead of the 5–8°F-inflated WeatherAPI value — verified locally via `MAIL_MAILER=log`, with the Apple Weather attribution present in the footer and the rest of the digest visually unchanged.

<!-- No open assumptions remain — all confirmed against a live WeatherKit payload (2026-07-04). See .memlog.md. -->

## Verified setup & live proof

- `firebase/php-jwt` installed; ES256 auth (with the `id` header) validated against the live API — all requests HTTP 200.
- Google Time Zone API enabled on `GOOGLE_GEOCODING_KEY` (live test: `OK`).
- Credentials present: `APPLE_WEATHERKIT_TEAM_ID`, `_SERVICE_ID`, `_KEY_ID` set; `_PRIVATE_KEY` = git-ignored path to a valid PKCS#8 `.p8`.
- **CAP-2 proven live:** Jul 4 highs came back Kennett 97°F / Lewistown 93°F / Dewey 86°F (vs WeatherAPI's 105 / 101 / 87) — inland inflation gone, coastal control intact.
- **Field lineage confirmed on real data:** `days[].temperatureMax`/`Min` (°C), `precipitationChance` (0–1), `conditionCode` (e.g. `Thunderstorms`), humidity at `days[].daytimeForecast.humidity` (0–1, **absent at day root**), feels-like at hourly `temperatureApparent` (peak > air temp on humid days, as expected).
