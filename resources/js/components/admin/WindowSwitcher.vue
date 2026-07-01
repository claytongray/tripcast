<script setup lang="ts">
import { Link } from '@inertiajs/vue3';

// The shared 7/30/90-day window switcher used across the admin sections. Each
// section passes `hrefFor` to build the link for a given day count via its own
// Wayfinder route helper.
defineProps<{
    window: number;
    windows: number[];
    hrefFor: (days: number) => string;
}>();
</script>

<template>
    <nav aria-label="Window" class="flex gap-1 rounded-md border border-hairline p-1">
        <Link
            v-for="w in windows"
            :key="w"
            :href="hrefFor(w)"
            :aria-current="w === window ? 'page' : undefined"
            class="inline-flex h-9 items-center rounded-sm px-3 text-meta font-medium focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
            :class="w === window ? 'bg-surface-wash text-brand' : 'text-ink-secondary hover:text-ink'"
        >
            {{ w }}d
        </Link>
    </nav>
</template>
