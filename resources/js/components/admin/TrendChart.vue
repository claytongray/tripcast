<script setup lang="ts">
import type { ChartData, ChartOptions } from 'chart.js';
import { computed } from 'vue';
import { Line } from 'vue-chartjs';
import type { TrendSeries } from '@/types/metrics';
import '@/lib/chart';

// A calm, full-width, mobile-legible trend chart. Presentational — Story 7.3+
// supplies labels + one or two series (e.g. sent vs failed).
const props = defineProps<{
    title: string;
    labels: string[];
    series: TrendSeries[];
}>();

// Brand first, a muted red for a second series (e.g. failures).
const palette = ['#2563A6', '#DC2626'];

const chartData = computed<ChartData<'line'>>(() => ({
    labels: props.labels,
    datasets: props.series.map((set, index) => ({
        label: set.label,
        data: set.data,
        borderColor: palette[index % palette.length],
        backgroundColor: palette[index % palette.length],
        borderWidth: 2,
        fill: false,
        tension: 0.3,
        pointRadius: 0,
        pointHoverRadius: 3,
    })),
}));

const chartOptions = computed<ChartOptions<'line'>>(() => ({
    responsive: true,
    maintainAspectRatio: false,
    interaction: { mode: 'index', intersect: false },
    plugins: {
        legend: { display: props.series.length > 1, position: 'bottom' },
        tooltip: { enabled: true },
    },
    scales: {
        x: {
            grid: { display: false },
            ticks: { maxRotation: 0, autoSkip: true, maxTicksLimit: 6 },
        },
        y: {
            beginAtZero: true,
            ticks: { precision: 0, maxTicksLimit: 5 },
        },
    },
}));
</script>

<template>
    <div class="rounded-md border border-hairline bg-surface-raised p-4">
        <p class="text-meta text-ink-secondary">{{ title }}</p>
        <div class="mt-3 h-56">
            <Line :data="chartData" :options="chartOptions" />
        </div>
    </div>
</template>
