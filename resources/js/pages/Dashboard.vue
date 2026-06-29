<script setup lang="ts">
import { Head } from '@inertiajs/vue3';

// Placeholder authenticated landing for Story 1.1. The real dashboard
// (trip management) is Epic 3, Story 3.1.
</script>

<template>
    <Head title="Dashboard" />

    <main class="mx-auto flex max-w-3xl flex-col gap-4 px-6 py-16">
        <p
            v-if="$page.props.flash.status"
            class="rounded-md border border-hairline bg-surface-wash p-4 text-body text-ink"
            role="status"
        >
            {{ $page.props.flash.status }}
        </p>

        <h1 class="text-display">You're in.</h1>
        <p class="text-body text-ink-secondary">
            Your trips will live here. Nothing to do yet — we'll be in your
            inbox each morning once a trip is set up.
        </p>
    </main>
</template>
