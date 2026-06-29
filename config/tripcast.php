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

];
