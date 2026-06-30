<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { home } from '@/routes';
import { edit as settingsEdit } from '@/routes/settings';

// Calm authenticated shell: a top bar linking home + account settings.
// Log out now lives on the settings page (Spec A).
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
                    :href="settingsEdit()"
                    class="inline-flex h-11 items-center rounded-sm px-3 text-body font-medium text-brand hover:text-brand-hover focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background focus-visible:outline-none"
                >
                    Settings
                </Link>
            </div>
        </header>

        <slot />
    </div>
</template>
