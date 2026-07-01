<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import { dashboard } from '@/routes';

const props = defineProps<{
    destination: string;
    firstForecastInDays: number;
}>();

// Relative timing for the first forecast, resolved server-side on the ET send
// clock (0 = today, 1 = tomorrow), so it can't drift on the viewer's timezone.
const firstForecastWhen = computed(() => {
    if (props.firstForecastInDays <= 0) {
        return 'today';
    }

    if (props.firstForecastInDays === 1) {
        return 'tomorrow';
    }

    return `in ${props.firstForecastInDays} days`;
});
</script>

<template>
    <Head title="Trip added" />

    <main
        class="mx-auto flex max-w-xl flex-col items-center gap-6 px-6 py-20 text-center"
    >
        <div class="space-y-3">
            <h1 class="text-display text-ink">You're all set.</h1>
            <p class="text-subtitle text-ink-secondary">
                Your tripcast has been created for {{ destination }}.
            </p>
        </div>

        <p class="text-body text-ink">
            You'll receive your first tripcast
            <span class="font-medium">{{ firstForecastWhen }}</span
            >.
        </p>

        <Button as-child>
            <Link :href="dashboard()">View your trips</Link>
        </Button>
    </main>
</template>
