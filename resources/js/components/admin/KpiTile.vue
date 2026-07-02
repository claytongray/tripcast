<script setup lang="ts">
import type { ChartData, ChartOptions } from 'chart.js';
import { computed } from 'vue';
import { Line } from 'vue-chartjs';
import '@/lib/chart';

// A single KPI: a big number, an optional period-over-period delta, and an
// optional bare sparkline. Presentational — Story 7.3+ supplies the data.
const props = withDefaults(
    defineProps<{
        label: string;
        value: number | string;
        delta?: number | null;
        // Unit for the delta: '%' for count KPIs (relative change), 'pp' for rate
        // KPIs (percentage-point change) — they are not the same thing.
        deltaSuffix?: string;
        series?: number[];
    }>(),
    { delta: null, deltaSuffix: '%', series: () => [] },
);

const deltaClass = computed(() => {
    if (props.delta === null || props.delta === 0) {
        return 'text-ink-secondary';
    }

    return props.delta > 0 ? 'text-positive' : 'text-destructive';
});

const deltaLabel = computed(() => {
    if (props.delta === null) {
        return '—';
    }

    const sign = props.delta > 0 ? '+' : '';

    return `${sign}${props.delta}${props.deltaSuffix}`;
});

const hasSparkline = computed(() => props.series.length > 0);

const sparklineData = computed<ChartData<'line'>>(() => ({
    labels: props.series.map((_, index) => index),
    datasets: [
        {
            data: props.series,
            borderColor: '#2563A6',
            borderWidth: 1.5,
            fill: false,
            tension: 0.3,
            pointRadius: 0,
        },
    ],
}));

// A bare trend glyph: no axes, grid, legend, tooltip, or interaction.
const sparklineOptions: ChartOptions<'line'> = {
    responsive: true,
    maintainAspectRatio: false,
    animation: false,
    plugins: { legend: { display: false }, tooltip: { enabled: false } },
    scales: { x: { display: false }, y: { display: false } },
    elements: { line: { borderJoinStyle: 'round' } },
};
</script>

<template>
    <div class="rounded-md border border-hairline bg-surface-raised p-4">
        <p class="text-meta text-ink-secondary">{{ label }}</p>
        <div class="mt-1 flex items-end justify-between gap-3">
            <div class="flex items-baseline gap-2">
                <span class="text-title text-ink">{{ value }}</span>
                <span class="text-meta" :class="deltaClass">{{
                    deltaLabel
                }}</span>
            </div>
            <div v-if="hasSparkline" class="h-8 w-20 shrink-0">
                <Line :data="sparklineData" :options="sparklineOptions" />
            </div>
        </div>
    </div>
</template>
