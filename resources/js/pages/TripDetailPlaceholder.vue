<script setup lang="ts">
import { Head } from '@inertiajs/vue3';

// Placeholder for the trip-detail / geocoding confirm step (Story 1.3).
// Story 1.2 only carries the entered trip into the session and lands here.
defineProps<{
    pendingTrip: {
        destination: string;
        departure_date: string;
        return_date: string;
    };
}>();
</script>

<template>
    <Head title="Confirm your trip" />

    <main
        class="mx-auto flex min-h-svh max-w-[560px] flex-col justify-center gap-4 px-4 py-12"
    >
        <h1 class="text-title text-ink">Got it.</h1>
        <p class="text-body text-ink-secondary">
            We'll confirm {{ pendingTrip.destination }} ({{
                pendingTrip.departure_date
            }}
            – {{ pendingTrip.return_date }}) in the next step.
        </p>
        <p class="text-meta text-ink-secondary">
            Place confirmation and email capture arrive in the next stories.
        </p>
    </main>
</template>
