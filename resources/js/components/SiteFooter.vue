<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { privacy, terms } from '@/routes';

// Quiet site footer (FR-24): legal links only. Mounted once per layout
// (AppLayout, AuthSimpleLayout) and inline on the layout-less pages
// (Landing, TripDetail) — one component, no per-page copies.
</script>

<template>
    <footer class="border-t border-hairline">
        <div
            class="mx-auto flex max-w-3xl items-center justify-center gap-1 px-6 py-4"
        >
            <Link
                :href="privacy()"
                class="inline-flex h-11 items-center rounded-sm px-2 text-meta text-ink-secondary underline-offset-2 hover:text-ink hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background focus-visible:outline-none"
            >
                Privacy
            </Link>
            <span class="text-meta text-ink-secondary" aria-hidden="true"
                >·</span
            >
            <Link
                :href="terms()"
                class="inline-flex h-11 items-center rounded-sm px-2 text-meta text-ink-secondary underline-offset-2 hover:text-ink hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background focus-visible:outline-none"
            >
                Terms
            </Link>
        </div>
    </footer>
</template>
