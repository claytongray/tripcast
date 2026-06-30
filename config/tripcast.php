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
