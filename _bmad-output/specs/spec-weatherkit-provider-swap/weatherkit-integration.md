# WeatherKit integration reference

Load-bearing technical contract the kernel cites. Verified against Apple's
WeatherKit REST docs (July 2026) and corroborating client libraries. Downstream
(plan + implementer) reads this alongside `SPEC.md`.

## Endpoint & request

- **Base:** `https://weatherkit.apple.com`
- **Weather:** `GET /api/v1/weather/{language}/{latitude}/{longitude}`
- **Availability:** `GET /api/v1/availability/{latitude}/{longitude}?country={ISO-alpha-2}` — reports which dataSets exist at a point (used later for alerts; not required for the core swap).
- **Query params for the swap:**
  - `dataSets=forecastDaily,forecastHourly` (current swap needs daily + hourly; `currentWeather` optional).
  - `timezone={IANA}` — **effectively required**; without it daily rollups default to GMT and the daily high/low misalign to the wrong local day. Sourced per CAP-7.
  - `dailyStart` / `dailyEnd` (ISO-8601 UTC) — window to cover today through `tripcast.forecast.horizon_days`; if omitted, daily starts today.
- **Units:** metric/SI only, **no unit param.** Temps °C, precip/snow mm, wind km/h, and `humidity`/`precipitationChance`/`cloudCover` as **0–1 decimals**. `metadata.units` reports `"m"`.

## Auth — ES256 JWT (per request, cached ~1h)

**Header** (note the non-standard `id`):
```json
{ "alg": "ES256", "kid": "<KEY_ID>", "id": "<TEAM_ID>.<SERVICE_ID>" }
```
**Claims:**
```json
{ "iss": "<TEAM_ID>", "sub": "<SERVICE_ID>", "iat": <unix>, "exp": <unix ≤ iat+3600> }
```
- Signed with the `.p8` private key (ES256 / ECDSA P-256 + SHA-256).
- Presented as `Authorization: Bearer <jwt>`.
- `SERVICE_ID` must be a registered identifier with the WeatherKit capability enabled, or requests 401.
- `firebase/php-jwt` `JWT::encode($claims, $key, 'ES256', $keyId, ['id' => "$team.$service"])` supplies the custom header and correct JOSE (`r‖s`) signature encoding. Cache the token (Laravel cache) keyed by kid until shortly before `exp`.

**Credentials (env only):** `APPLE_WEATHERKIT_TEAM_ID`, `APPLE_WEATHERKIT_SERVICE_ID`, `APPLE_WEATHERKIT_KEY_ID`, `APPLE_WEATHERKIT_PRIVATE_KEY` (`.p8` contents or path). Config under `services.weatherkit`. `.p8` never logged or committed.

## Field lineage — WeatherKit → `ForecastDay`

| `ForecastDay` field | WeatherKit source | Conversion |
|---|---|---|
| `date` | `forecastDaily.days[].forecastStart` (in `timezone`) | date portion, destination-local |
| `highC` / `highF` | `days[].temperatureMax` (°C) | F = C×9/5+32 |
| `lowC` / `lowF` | `days[].temperatureMin` (°C) | same |
| `precipChance` | `days[].precipitationChance` (0–1) | ×100, round to int % |
| `conditionText` | `days[].conditionCode` (enum) | code → label (below) |
| `humidity` | `days[].daytimeForecast.humidity` (0–1) | ×100, round to int %  *(confirmed on live payload — absent at day root)* |
| `feelsLikeHighC` / `F` | peak of `forecastHourly.hours[].temperatureApparent` (°C) | max-scan hour, then C→F |

A day missing any core value (`conditionText`, `precipChance`, high/low in either unit) stays **limited** — never fabricated (preserves FR-7). `humidity` and feels-like are optional enrichment and never make a day limited.

## Condition codes → label

`conditionCode` is a closed PascalCase enum (~38 values) shared across daily/hourly/current. Map each to a spaced human label (`PartlyCloudy` → "Partly Cloudy", `ScatteredThunderstorms` → "Scattered Thunderstorms", `HeavyRain` → "Heavy Rain", `MostlyClear` → "Mostly Clear"). The label feeds the existing `WeatherEmoji` keyword matcher **unchanged** — its keywords (`thunder`, `rain`/`drizzle`/`shower`, `snow`/`sleet`/`ice`, `fog`/`haze`/`dust`, `partly`, `cloud`/`overcast`, `sun`/`clear`, `wind`) already resolve the spaced labels. Known keyword gaps to cover so the emoji isn't dropped: `Breezy`/`Windy` (→ wind), `Hurricane`/`TropicalStorm` (no current keyword). Full enum: Apple `WeatherCondition` reference.

## Timezone resolution (CAP-7)

- **Google Time Zone API:** `GET https://maps.googleapis.com/maps/api/timezone/json?location={lat},{lng}&timestamp={unix}&key={GOOGLE_GEOCODING_KEY}` → `{ timeZoneId: "America/New_York", status: "OK" }`.
- Same key already used for geocoding/Places. **Requires the Time Zone API to be enabled** on that key (open question).
- **Persist once (CAP-9):** on the first forecast for a trip, resolve and write `trips.destination_timezone`; every later send reads the stored zone and calls Google **zero** times. Cache (by rounded coordinate) is a secondary optimization, mainly for the sample/no-trip path. Net: ≤1 Time Zone API call per trip.
- Caller passes the stored zone into `fetchForecast($lat, $lon, $timezone)`; when null (sample path) the provider resolves+caches. On resolver failure → config default `America/New_York`, logged.
- Replaces the invalidated plan to read WeatherAPI `location.tz_id`. This spec owns and populates `trips.destination_timezone`; the `timezone-aware-send-time` feature consumes it.

## Attribution (CAP-8)

- Mandatory wherever WeatherKit data is shown. Assets from `GET https://weatherkit.apple.com/attribution/{language}` (Apple Weather logo light/dark + localized name + `legalPageUrl`); legal link also at response `metadata.attributionURL`.
- Email footer: **inline** the logo (embed/base64 with `alt="Apple Weather"`) — email clients strip remote images/CSS. Link to `legalPageUrl`. Mark and name unaltered ("Apple Weather"/"Weather" are Apple trademarks).

## Pricing & limits

- 500,000 calls/month free with the Apple Developer Program membership, pooled per team, no rollover. Beyond that, paid monthly tiers (1M → $49.99, etc.). No published RPS limit — handle 429 with backoff. Caching the JWT and resolved timezone keeps us well inside the free tier.
