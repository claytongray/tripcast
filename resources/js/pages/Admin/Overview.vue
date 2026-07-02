<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { computed } from 'vue';
import KpiTile from '@/components/admin/KpiTile.vue';
import TrendChart from '@/components/admin/TrendChart.vue';
import WindowSwitcher from '@/components/admin/WindowSwitcher.vue';
import { overview } from '@/routes/admin';
import type { OverviewPayload } from '@/types/overview';

const props = defineProps<OverviewPayload>();

const pct = (value: number): string => `${value}%`;

const mixTotal = computed(() => {
    const mix = props.kpis.status_mix;

    return mix.active + mix.paused + mix.completed;
});

const mixWidth = (count: number): string =>
    mixTotal.value > 0 ? `${(count / mixTotal.value) * 100}%` : '0%';

const successLabel = computed(() =>
    props.kpis.sends_today.success_rate === null ? '—' : `${props.kpis.sends_today.success_rate}%`,
);
</script>

<template>
    <Head title="Admin — overview" />

    <main class="mx-auto flex max-w-5xl flex-col gap-6 px-6 py-12">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div class="space-y-1">
                <h1 class="text-title text-ink">Overview</h1>
                <p class="text-body text-ink-secondary">Product health at a glance.</p>
            </div>
            <WindowSwitcher
                :window="window"
                :windows="windows"
                :href-for="(days) => overview({ query: { days } }).url"
            />
        </div>

        <!-- KPI tiles -->
        <section class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <KpiTile
                label="Signups"
                :value="kpis.signups.value"
                :delta="kpis.signups.delta_pct"
                :series="kpis.signups.series"
            />
            <KpiTile
                label="Confirmation rate"
                :value="pct(kpis.confirmation_rate.value)"
                :delta="kpis.confirmation_rate.delta_pp"
                delta-suffix="pp"
                :series="kpis.confirmation_rate.series"
            />
            <KpiTile
                label="Trips created"
                :value="kpis.trips_created.value"
                :delta="kpis.trips_created.delta_pct"
                :series="kpis.trips_created.series"
            />

            <!-- Active-trip status mix (point-in-time distribution) -->
            <div class="rounded-md border border-hairline bg-surface-raised p-4">
                <p class="text-meta text-ink-secondary">Active-trip status mix</p>
                <div class="mt-1 flex items-baseline gap-3 text-body text-ink">
                    <span><span class="text-title">{{ kpis.status_mix.active }}</span> active</span>
                    <span class="text-ink-secondary">{{ kpis.status_mix.paused }} paused</span>
                    <span class="text-ink-secondary">{{ kpis.status_mix.completed }} completed</span>
                </div>
                <div class="mt-3 flex h-2 overflow-hidden rounded-full bg-surface-wash">
                    <div class="bg-brand" :style="{ width: mixWidth(kpis.status_mix.active) }" />
                    <div class="bg-ink-secondary/50" :style="{ width: mixWidth(kpis.status_mix.paused) }" />
                    <div class="bg-ink-secondary/25" :style="{ width: mixWidth(kpis.status_mix.completed) }" />
                </div>
            </div>

            <!-- Sends today + success rate -->
            <div class="rounded-md border border-hairline bg-surface-raised p-4">
                <p class="text-meta text-ink-secondary">Sends today</p>
                <div class="mt-1 flex items-end justify-between gap-3">
                    <div class="flex items-baseline gap-2">
                        <span class="text-title text-ink">{{ kpis.sends_today.total }}</span>
                        <span class="text-meta text-ink-secondary">
                            {{ kpis.sends_today.sent }} sent · {{ kpis.sends_today.failed }} failed
                        </span>
                    </div>
                    <span class="text-meta text-ink-secondary">{{ successLabel }} ok</span>
                </div>
            </div>

            <KpiTile
                :label="`Promo CTR (${kpis.promo_ctr.clicks}/${kpis.promo_ctr.impressions})`"
                :value="pct(kpis.promo_ctr.value)"
                :delta="kpis.promo_ctr.delta_pp"
                delta-suffix="pp"
                :series="kpis.promo_ctr.series"
            />
            <KpiTile
                label="Sample requests"
                :value="kpis.sample_requests.value"
                :delta="kpis.sample_requests.delta_pct"
                :series="kpis.sample_requests.series"
            />
        </section>

        <!-- Trend charts -->
        <section class="flex flex-col gap-4">
            <TrendChart title="Signups / day" :labels="dates" :series="charts.signups" />
            <TrendChart title="Sends & failures / day" :labels="dates" :series="charts.sends" />
            <TrendChart title="CTR / day" :labels="dates" :series="charts.ctr" />
            <TrendChart title="Sample requests / day" :labels="dates" :series="charts.samples" />
        </section>
    </main>
</template>
