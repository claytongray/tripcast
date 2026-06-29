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
        // Minutes a magic link stays valid after it is issued.
        'ttl_minutes' => (int) env('MAGIC_LINK_TTL_MINUTES', 15),

        // Per-email request throttle.
        'throttle' => [
            'max_attempts' => (int) env('MAGIC_LINK_MAX_ATTEMPTS', 5),
            'decay_minutes' => (int) env('MAGIC_LINK_DECAY_MINUTES', 10),
        ],
    ],

];
