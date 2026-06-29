<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { dashboard } from '@/routes';

// Placeholder landing for Story 1.1. The real landing hero + inline
// trip-setup form is Story 1.2.
</script>

<template>
    <Head title="Welcome" />

    <main
        class="mx-auto flex min-h-screen max-w-2xl flex-col justify-center gap-6 bg-background p-6 text-foreground"
    >
        <div class="space-y-2">
            <h1 class="text-display">tripcast</h1>
            <p class="text-subtitle text-ink-secondary">
                The weather app you never have to open.
            </p>
        </div>

        <nav class="flex gap-4">
            <Link
                v-if="$page.props.auth.user"
                :href="dashboard()"
                class="text-body font-medium text-brand hover:text-brand-hover"
            >
                Go to dashboard
            </Link>
            <a
                v-else
                href="/login"
                class="text-body font-medium text-brand hover:text-brand-hover"
            >
                Sign in
            </a>
        </nav>
    </main>
</template>
