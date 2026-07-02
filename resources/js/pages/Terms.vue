<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { home } from '@/routes';
</script>

<template>
    <Head title="Terms" />

    <main class="mx-auto max-w-xl px-6 py-16">
        <Link
            :href="home()"
            class="text-meta text-ink-secondary underline underline-offset-2 hover:text-ink"
        >
            &larr; back to tripcast
        </Link>

        <div class="mt-8 space-y-3">
            <h1 class="text-display text-ink">Terms</h1>
            <p class="text-meta text-ink-secondary">
                Last updated: July 1, 2026
            </p>
        </div>

        <div class="mt-10 space-y-8">
            <section class="space-y-3">
                <h2 class="text-title text-ink">A free beta</h2>
                <p class="text-body text-ink-secondary">
                    tripcast is a free beta, provided as-is, with no warranty of
                    any kind. It may change, pause, or wind down while it's in
                    beta — if that happens, your daily emails simply stop.
                </p>
            </section>

            <section class="space-y-3">
                <h2 class="text-title text-ink">About the forecasts</h2>
                <p class="text-body text-ink-secondary">
                    Forecasts come from a third-party weather provider, and
                    forecasts can be wrong. tripcast is for packing decisions
                    and peace of mind — please don't rely on it for
                    safety-critical plans.
                </p>
            </section>

            <section class="space-y-3">
                <h2 class="text-title text-ink">Affiliate links</h2>
                <p class="text-body text-ink-secondary">
                    Some emails include one product recommendation. As an Amazon
                    Associate, tripcast earns from qualifying purchases.
                </p>
            </section>

            <section class="space-y-3">
                <h2 class="text-title text-ink">Questions</h2>
                <p class="text-body text-ink-secondary">
                    Reply to any tripcast email and a human will read it.
                </p>
            </section>
        </div>
    </main>
</template>
