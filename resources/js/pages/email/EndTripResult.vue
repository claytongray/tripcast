<script setup lang="ts">
import { Head } from '@inertiajs/vue3';

defineProps<{
    place: string;
}>();
</script>

<template>
    <Head title="Trip ended" />

    <div class="flex flex-col gap-6 text-center">
        <div class="space-y-2">
            <h1 class="text-title text-ink">Your tripcast is wrapped</h1>
            <p class="text-body text-ink-secondary">
                Your forecasts for {{ place }} have stopped — safe travels.
            </p>
        </div>
    </div>
</template>
