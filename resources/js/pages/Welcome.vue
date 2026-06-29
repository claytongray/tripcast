<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { dashboard } from '@/routes';

// Placeholder landing for Story 1.1. The real landing hero + inline
// trip-setup form is Story 1.2.
</script>

<template>
    <Head title="Welcome" />

    <div class="mx-auto flex min-h-screen max-w-2xl flex-col justify-center gap-6 p-6">
        <div class="space-y-2">
            <h1 class="text-3xl font-semibold">tripcast</h1>
            <p class="text-muted-foreground">
                The weather app you never have to open.
            </p>
        </div>

        <nav class="flex gap-4">
            <Link
                v-if="$page.props.auth.user"
                :href="dashboard()"
                class="font-medium text-[#2563A6] hover:underline"
            >
                Go to dashboard
            </Link>
            <a
                v-else
                href="/login"
                class="font-medium text-[#2563A6] hover:underline"
            >
                Sign in
            </a>
        </nav>
    </div>
</template>
