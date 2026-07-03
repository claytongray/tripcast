<script setup lang="ts">
import { Head } from '@inertiajs/vue3';

defineProps<{
    email: string;
}>();
</script>

<template>
    <Head title="Sample on its way" />

    <div class="flex flex-col gap-6 text-center">
        <div class="space-y-2">
            <h1 class="text-title text-ink">Your sample is on its way</h1>
            <p class="text-body text-ink-secondary">
                We just sent a sample tripcast to {{ email }}. It shows exactly what your daily
                forecast will look like once your trip moves into the forecast window.
            </p>
        </div>
    </div>
</template>
