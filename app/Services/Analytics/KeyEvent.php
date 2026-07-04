<?php

namespace App\Services\Analytics;

use Inertia\Inertia;

/**
 * Google Analytics 4 key events (conversions).
 *
 * Each call flashes an `analytics` payload onto the Inertia response. The
 * client listener (resources/js/lib/analytics.ts) reads it from the global
 * `flash` event and calls `gtag('event', …)` exactly once on the next page.
 * Centralizing the event names here keeps them consistent across the several
 * controllers that fire them, and keeps the browser-only gtag concern out of
 * the Vue components.
 */
final class KeyEvent
{
    public const TRIP_CREATED = 'trip_created';

    public const LOGIN_LINK_REQUESTED = 'login_link_requested';

    public const SIGN_UP = 'sign_up';

    public const LOGIN = 'login';

    public const SAMPLE_REQUESTED = 'sample_requested';

    public const FEEDBACK_SUBMITTED = 'feedback_submitted';

    /**
     * Flash a key event so the client fires it on the next Inertia page load.
     *
     * @param  array<string, scalar>  $params  GA4 event parameters (dimensions).
     */
    public static function flash(string $name, array $params = []): void
    {
        Inertia::flash('analytics', [
            'event' => $name,
            'params' => (object) $params,
        ]);
    }
}
