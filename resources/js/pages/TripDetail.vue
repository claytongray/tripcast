<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { home } from '@/routes';

// Passive confirm of the geocoded place (Story 1.3). Email capture is Story 1.4.
defineProps<{
    pendingTrip: {
        destination: string;
        departure_date: string;
        return_date: string;
        canonical_place_name: string;
        latitude: number;
        longitude: number;
    };
}>();
</script>

<template>
    <Head title="Confirm your trip" />

    <main
        class="mx-auto flex min-h-svh max-w-[560px] flex-col justify-center gap-4 px-4 py-12"
    >
        <h1 class="text-title text-ink">
            Watching {{ pendingTrip.canonical_place_name }}.
        </h1>
        <p class="text-body text-ink-secondary">
            {{ pendingTrip.departure_date }} – {{ pendingTrip.return_date }}
        </p>
        <p class="text-body text-ink-secondary">
            Not right?
            <Link
                :href="home()"
                class="font-medium text-brand hover:text-brand-hover"
            >
                Edit destination
            </Link>
        </p>
        <p class="text-meta text-ink-secondary">
            Email capture arrives in the next story.
        </p>
    </main>
</template>
