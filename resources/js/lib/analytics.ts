import { router } from '@inertiajs/vue3';

/**
 * Google Analytics 4 wiring for the Inertia SPA.
 *
 * The gtag.js tag (resources/views/app.blade.php) is only present in
 * environments with a Measurement ID configured, so `window.gtag` is undefined
 * in local/dev — every call here is a safe no-op there.
 *
 * Two concerns:
 *   1. Page views — gtag's automatic page_view is suppressed (send_page_view:
 *      false), so we send one per real navigation: once on boot, then again on
 *      each client-side Inertia visit (which never reloads the document).
 *   2. Key events — controllers flash an `analytics` payload; we fire it once
 *      when the `flash` event delivers it.
 */

type GtagParams = Record<string, unknown>;

declare global {
    interface Window {
        gtag?: (...args: unknown[]) => void;
        dataLayer?: unknown[];
    }
}

function gtag(...args: unknown[]): void {
    if (typeof window.gtag === 'function') {
        window.gtag(...args);
    }
}

function trackPageView(path: string): void {
    gtag('event', 'page_view', {
        page_path: path,
        page_location: window.location.origin + path,
        page_title: document.title,
    });
}

export function initializeAnalytics(): void {
    // Initial full-document load: Inertia's `navigate` event does not fire on
    // boot, so send the first page view here.
    trackPageView(window.location.pathname + window.location.search);

    // Client-side (SPA) navigations, including browser back/forward.
    router.on('navigate', (event) => {
        const url = (event as CustomEvent).detail?.page?.url as string | undefined;
        trackPageView(url ?? window.location.pathname + window.location.search);
    });

    // Server-signalled key events (conversions) ride Inertia flash data.
    router.on('flash', (event) => {
        const analytics = (event as CustomEvent).detail?.flash?.analytics as
            | { event: string; params?: GtagParams }
            | undefined;

        if (analytics?.event) {
            gtag('event', analytics.event, analytics.params ?? {});
        }
    });
}
