<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import { dashboard } from '@/routes';

const props = defineProps<{
    destination: string;
    firstForecastDate: string;
}>();

const WEEKDAYS = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
const MONTHS = [
    'January',
    'February',
    'March',
    'April',
    'May',
    'June',
    'July',
    'August',
    'September',
    'October',
    'November',
    'December',
];

// Format a naive Y-m-d as an absolute date without timezone drift.
const forecastDate = computed(() => {
    const [y, m, d] = props.firstForecastDate.split('-').map(Number);
    const weekday = WEEKDAYS[new Date(Date.UTC(y, m - 1, d)).getUTCDay()];

    return `${weekday}, ${MONTHS[m - 1]} ${d}`;
});
</script>

<template>
    <Head title="Trip added" />

    <main class="mx-auto flex max-w-xl flex-col items-center gap-6 px-6 py-20 text-center">
        <div class="space-y-3">
            <h1 class="text-display text-ink">You're all set.</h1>
            <p class="text-subtitle text-ink-secondary">
                We're watching {{ destination }}.
            </p>
        </div>

        <p class="text-body text-ink">
            Your first forecast goes out <span class="font-medium">{{ forecastDate }}</span
            >.
        </p>

        <Button as-child>
            <Link :href="dashboard()">View your trips</Link>
        </Button>
    </main>
</template>
