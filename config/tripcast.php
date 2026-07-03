<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Magic-link authentication (AD-6)
    |--------------------------------------------------------------------------
    |
    | Passwordless login: a single-use, time-limited token is emailed; clicking
    | it consumes the token and establishes a long-lived session. Requesting a
    | new link invalidates prior unconsumed tokens, and requests are throttled
    | per email address.
    |
    */

    'magic_link' => [
        // Minutes a magic link stays valid after it is issued. Floored at 1 so a
        // zero/negative misconfiguration can't issue links that are never usable.
        'ttl_minutes' => max(1, (int) env('MAGIC_LINK_TTL_MINUTES', 15)),

        // Request throttle. Values are floored at 1: a 0 decay would never engage
        // the limiter, and 0 max_attempts would lock everyone out on the first try.
        'throttle' => [
            'max_attempts' => max(1, (int) env('MAGIC_LINK_MAX_ATTEMPTS', 5)),
            'decay_minutes' => max(1, (int) env('MAGIC_LINK_DECAY_MINUTES', 10)),
            // Per-IP cap (defends against address-rotating email-bombing).
            'ip_max_attempts' => max(1, (int) env('MAGIC_LINK_IP_MAX_ATTEMPTS', 20)),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Public sample tripcast (MVP)
    |--------------------------------------------------------------------------
    |
    | One fixed demo destination. Its forecast is fetched live once per day and
    | cached; a baked-in fallback covers a provider outage so the public sample
    | never breaks.
    */
    'sample' => [
        'destination' => [
            'key' => 'reykjavik',
            'label' => 'Reykjavik, Iceland',
            'latitude' => 64.1466,
            'longitude' => -21.9426,
        ],
        // Minutes a sample "Get started" magic link stays valid. Floored at 1 so a
        // zero/negative misconfiguration can't issue links that are never usable.
        'magic_link_ttl_minutes' => max(1, (int) env('SAMPLE_MAGIC_LINK_TTL_MINUTES', 2880)),
    ],

    /*
    |--------------------------------------------------------------------------
    | Daily send (AD-3)
    |--------------------------------------------------------------------------
    |
    | An email_logs row stuck in `sending` longer than the stale-lease threshold
    | (a crash mid-send) is reclaimable by a later run.
    |
    */

    'send' => [
        'stale_lease_minutes' => max(1, (int) env('SEND_STALE_LEASE_MINUTES', 30)),

        // Bounded in-process delivery retries (AD-4): the job stays tries = 1 and
        // retries the Mailer (delivery only — never re-fetching weather) up to this
        // cap before reaching a terminal `failed`. Floored at 1 (at least one send).
        'max_delivery_attempts' => max(1, (int) env('SEND_MAX_DELIVERY_ATTEMPTS', 3)),

        // The daily send hour on the America/New_York clock (Milestone 2 makes the
        // *zone* per-trip; the hour stays this one knob). 0–23, floored/capped.
        'default_hour' => min(23, max(0, (int) env('TRIPCAST_SEND_HOUR', 7))),
    ],

    /*
    |--------------------------------------------------------------------------
    | Forecast horizon (AD-11)
    |--------------------------------------------------------------------------
    |
    | How many days into the future the weather API can forecast — the single
    | knob behind the trip cadence and the rendered window. The send window
    | opens this many days before departure (so the first digest can already
    | show the departure day), and the fetch requests this many days ahead
    | (plus today). Bump it as the upstream API's reach grows: both the send
    | window and the trip-day forecast follow automatically. Floored at 1.
    |
    */

    'forecast' => [
        'horizon_days' => max(1, (int) env('TRIPCAST_FORECAST_HORIZON_DAYS', 7)),

        // AD-16 retention horizon: a daily sweep nulls `email_logs.weather_snapshot`
        // this many days after the owning Trip's return_date (anchored on
        // return_date — never send_date). The send-outcome row survives. Floored at 1.
        'retention_days' => max(1, (int) env('TRIPCAST_FORECAST_RETENTION_DAYS', 30)),
    ],

    /*
    |--------------------------------------------------------------------------
    | Free-tier cost-control cap (AD-15)
    |--------------------------------------------------------------------------
    |
    | The maximum number of active Trips a user may hold at once — pure
    | cost-control, decoupled from monetization (no upsell, no billing). Slot
    | occupancy is `status == active && deleted_at null` only; paused/completed
    | Trips don't occupy a slot. Enforced at the single CreateTrip decision
    | point. Floored at 1.
    |
    */

    'free_tier' => [
        'max_active_trips' => max(1, (int) env('TRIPCAST_MAX_ACTIVE_TRIPS', 3)),
    ],

    /*
    |--------------------------------------------------------------------------
    | Day-over-day narration (AD-17, FR-16)
    |--------------------------------------------------------------------------
    |
    | A short calm line about a notable forecast change since yesterday,
    | enhancement-only (never on the delivery path). The deterministic narrator
    | ships live; the Claude (Haiku) adapter runs in shadow for comparison when
    | `shadow` is on and an API key is set — its line is logged, never sent. The
    | call is time-boxed and grounded strictly in the stored snapshots.
    |
    */

    'narration' => [
        'model' => env('TRIPCAST_NARRATION_MODEL', 'claude-haiku-4-5'),
        'api_key' => env('ANTHROPIC_API_KEY'),
        'timeout' => max(1, (int) env('TRIPCAST_NARRATION_TIMEOUT', 5)),
        'shadow' => (bool) env('TRIPCAST_NARRATION_SHADOW', false),

        // "Notable" thresholds for a day-over-day change worth a line.
        'notable' => [
            'precip' => max(1, (int) env('TRIPCAST_NARRATION_PRECIP_DELTA', 25)), // percentage points
            'temp' => max(1, (int) env('TRIPCAST_NARRATION_TEMP_DELTA', 10)),     // degrees (owner's unit)
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Affiliate promo slot (AD-18, FR-17)
    |--------------------------------------------------------------------------
    |
    | One calm, weather-keyed Amazon recommendation below the forecast for
    | free-tier digests. The catalog below is a PLACEHOLDER — edit the products,
    | images, and ASINs freely with no code change. Affiliate links are plain
    | tagged URLs (no SDK); the associate tag is appended by the adapter. Each
    | item's `slug` is its stable attribution key (promo_events, Story 5.4).
    |
    */

    'promo' => [
        // Which PromoProvider adapter is bound (AD-18): 'database' (admin-managed
        // promo_items, Epic 8) or 'affiliate' (the config catalog — code-free
        // rollback). The DB adapter falls back to the config catalog while
        // promo_items is empty, so switching is safe before seeding.
        'provider' => env('PROMO_PROVIDER', 'database'),

        'amazon_tag' => env('AMAZON_ASSOCIATE_TAG', 'tripcast0c-20'),
        'timeout' => max(1, (int) env('TRIPCAST_PROMO_TIMEOUT', 3)),

        // weather-profile slug => list of stub products. Placeholder content.
        'catalog' => [
            'snow' => [
                ['slug' => 'snow-traction-cleats', 'label' => 'Packable ice cleats', 'image' => 'https://placehold.co/120x120?text=Cleats', 'url' => 'https://www.amazon.com/dp/B000PLACEHOLDER1'],
                ['slug' => 'insulated-gloves', 'label' => 'Insulated touchscreen gloves', 'image' => 'https://placehold.co/120x120?text=Gloves', 'url' => 'https://www.amazon.com/dp/B000PLACEHOLDER2'],
            ],
            'hot' => [
                ['slug' => 'packable-sun-hat', 'label' => 'Packable wide-brim sun hat', 'image' => 'https://placehold.co/120x120?text=Sun+Hat', 'url' => 'https://www.amazon.com/dp/B000PLACEHOLDER3'],
                ['slug' => 'mineral-sunscreen', 'label' => 'Travel-size mineral sunscreen', 'image' => 'https://placehold.co/120x120?text=SPF', 'url' => 'https://www.amazon.com/dp/B000PLACEHOLDER4'],
            ],
            'cold-wet' => [
                ['slug' => 'compact-travel-umbrella', 'label' => 'Windproof compact umbrella', 'image' => 'https://placehold.co/120x120?text=Umbrella', 'url' => 'https://www.amazon.com/dp/B000PLACEHOLDER5'],
                ['slug' => 'packable-rain-jacket', 'label' => 'Packable rain shell', 'image' => 'https://placehold.co/120x120?text=Rain', 'url' => 'https://www.amazon.com/dp/B000PLACEHOLDER6'],
            ],
            'cold' => [
                ['slug' => 'merino-base-layer', 'label' => 'Merino wool base layer', 'image' => 'https://placehold.co/120x120?text=Layer', 'url' => 'https://www.amazon.com/dp/B000PLACEHOLDER7'],
            ],
            'mild' => [
                ['slug' => 'packing-cubes', 'label' => 'Compression packing cubes', 'image' => 'https://placehold.co/120x120?text=Cubes', 'url' => 'https://www.amazon.com/dp/B000PLACEHOLDER8'],
            ],
            // Generic fallback when a profile has no match (AD-18).
            'travel-essentials' => [
                ['slug' => 'universal-adapter', 'label' => 'Universal travel adapter', 'image' => 'https://placehold.co/120x120?text=Adapter', 'url' => 'https://www.amazon.com/dp/B000PLACEHOLDER9'],
                ['slug' => 'travel-power-bank', 'label' => 'Compact travel power bank', 'image' => 'https://placehold.co/120x120?text=Power', 'url' => 'https://www.amazon.com/dp/B000PLACEHOLDER10'],
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Physical postal address (CAN-SPAM / CASL footer seam)
    |--------------------------------------------------------------------------
    |
    | A stable physical mailing address rendered in the digest footer when set.
    | The End-trip / Unsubscribe / Feedback links and List-Unsubscribe headers
    | are Story 2.5/2.6 — only the postal-address line is wired here.
    |
    */

    'postal_address' => env('TRIPCAST_POSTAL_ADDRESS'),

    /*
    |--------------------------------------------------------------------------
    | Unsubscribe mailto target (List-Unsubscribe header)
    |--------------------------------------------------------------------------
    |
    | The `mailto:` arm of the RFC 8058 List-Unsubscribe header (paired with the
    | signed HTTPS one-click target). Defaults to the configured From address.
    | DORMANT since 2026-07-02 (Story 9.9): its only consumer was DigestMail's
    | removed custom header (MailerSend plan gate #MS42235). Kept for the
    | re-enable path recorded in deferred-work.md.
    |
    */

    'unsubscribe_mailto' => env('TRIPCAST_UNSUBSCRIBE_MAILTO', env('MAIL_FROM_ADDRESS')),

    /*
    |--------------------------------------------------------------------------
    | Daily-run liveness heartbeat (AD-14)
    |--------------------------------------------------------------------------
    |
    | The daily command pings an external dead-man's-switch monitor on finish:
    | `{url}` on a healthy run, `{url}/fail` on an unhealthy one. A missed ping
    | (cron/queue/Redis/host down) is the monitor's own alert. A null URL
    | disables pinging — local/dev/test is a silent no-op. A ping failure never
    | breaks the product run.
    |
    */

    'heartbeat' => [
        'url' => env('TRIPCAST_HEARTBEAT_URL'),
        'timeout' => max(1, (int) env('TRIPCAST_HEARTBEAT_TIMEOUT', 5)),
    ],

];
