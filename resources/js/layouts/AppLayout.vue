<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { home, logout } from '@/routes';

// Minimal authenticated shell for Story 1.1: a calm top bar + logout.
// The full trip-management dashboard is Epic 3 (Story 3.1).
</script>

<template>
    <div class="min-h-screen bg-background text-foreground">
        <header class="border-b border-hairline">
            <div
                class="mx-auto flex max-w-3xl items-center justify-between gap-4 px-6 py-4"
            >
                <Link
                    :href="home()"
                    class="rounded-sm text-title font-semibold focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background focus-visible:outline-none"
                >
                    tripcast
                </Link>
                <Link
                    :href="logout()"
                    method="post"
                    as="button"
                    type="button"
                    class="inline-flex h-11 items-center rounded-sm px-3 text-body font-medium text-brand hover:text-brand-hover focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background focus-visible:outline-none"
                >
                    Log out
                </Link>
            </div>
        </header>

        <slot />
    </div>
</template>
