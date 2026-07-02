<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { home } from '@/routes';
</script>

<template>
    <Head title="Privacy" />

    <main class="mx-auto max-w-xl px-6 py-16">
        <Link
            :href="home()"
            class="text-meta text-ink-secondary underline underline-offset-2 hover:text-ink"
        >
            &larr; back to tripcast
        </Link>

        <div class="mt-8 space-y-3">
            <h1 class="text-display text-ink">Privacy</h1>
            <p class="text-meta text-ink-secondary">Last updated: July 1, 2026</p>
        </div>

        <div class="mt-10 space-y-8">
            <section class="space-y-3">
                <h2 class="text-title text-ink">What we store</h2>
                <p class="text-body text-ink-secondary">
                    tripcast keeps exactly two kinds of things: your email
                    address, and your trips — the destination you typed, the
                    place name and coordinates it resolved to, and your travel
                    dates.
                </p>
                <p class="text-body text-ink-secondary">
                    There is never a password. You sign in with a link we email
                    you, so there's no password for us to store or lose.
                </p>
            </section>

            <section class="space-y-3">
                <h2 class="text-title text-ink">Forecast history</h2>
                <p class="text-body text-ink-secondary">
                    Each daily email saves a copy of that day's forecast so we
                    can tell you how it's changing. Those forecast snapshots
                    are deleted about 30 days after your trip's return date.
                </p>
            </section>

            <section class="space-y-3">
                <h2 class="text-title text-ink">Stopping is one click</h2>
                <p class="text-body text-ink-secondary">
                    Every email footer has an unsubscribe link that stops all
                    tripcast email in one click — no login, no questions. You
                    can also end a single trip from the same footer and keep
                    the rest.
                </p>
            </section>

            <section class="space-y-3">
                <h2 class="text-title text-ink">What we don't do</h2>
                <p class="text-body text-ink-secondary">
                    We don't sell your data, and there are no ad trackers. The
                    only third parties that see anything are the services that
                    run the product: email delivery, place lookup, and the
                    weather forecast itself.
                </p>
            </section>

            <section class="space-y-3">
                <h2 class="text-title text-ink">A note on the beta</h2>
                <p class="text-body text-ink-secondary">
                    tripcast is a small personal beta. There's no formal
                    GDPR/CCPA program yet; if you'd like your data removed,
                    reply to any tripcast email and we'll take care of it.
                </p>
            </section>
        </div>
    </main>
</template>
