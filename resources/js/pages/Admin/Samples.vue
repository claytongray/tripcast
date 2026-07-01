<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import TrendChart from '@/components/admin/TrendChart.vue';
import { samples } from '@/routes/admin';
import type { TrendSeries } from '@/types/metrics';

type DestinationRow = { destination: string; count: number };

const props = defineProps<{
    window: number;
    windows: number[];
    dates: string[];
    requests: TrendSeries[];
    totals: {
        requests: number;
        requesters: number;
        confirmed_requesters: number;
        conversion_rate: number | null;
    };
    top_destinations: DestinationRow[];
}>();

const conversionLabel = computed(() =>
    props.totals.conversion_rate === null ? '—' : `${props.totals.conversion_rate}%`,
);
</script>

<template>
    <Head title="Admin — samples" />

    <main class="mx-auto flex max-w-5xl flex-col gap-6 px-6 py-12">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div class="space-y-1">
                <h1 class="text-title text-ink">Samples</h1>
                <p class="text-body text-ink-secondary">Sample-request funnel. Read-only.</p>
            </div>
            <nav aria-label="Window" class="flex gap-1 rounded-md border border-hairline p-1">
                <Link
                    v-for="w in windows"
                    :key="w"
                    :href="samples({ query: { days: w } })"
                    :aria-current="w === window ? 'page' : undefined"
                    class="inline-flex h-9 items-center rounded-sm px-3 text-meta font-medium focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                    :class="w === window ? 'bg-surface-wash text-brand' : 'text-ink-secondary hover:text-ink'"
                >
                    {{ w }}d
                </Link>
            </nav>
        </div>

        <!-- Funnel cards -->
        <section class="grid grid-cols-1 gap-4 sm:grid-cols-3">
            <div class="rounded-md border border-hairline bg-surface-raised p-4">
                <p class="text-meta text-ink-secondary">Sample requests</p>
                <p class="mt-1 text-title text-ink">{{ totals.requests }}</p>
            </div>
            <div class="rounded-md border border-hairline bg-surface-raised p-4">
                <p class="text-meta text-ink-secondary">Distinct requesters</p>
                <p class="mt-1 text-title text-ink">{{ totals.requesters }}</p>
            </div>
            <div class="rounded-md border border-hairline bg-surface-raised p-4">
                <p class="text-meta text-ink-secondary">Confirmed conversion</p>
                <p class="mt-1 text-title text-brand">{{ conversionLabel }}</p>
                <p class="text-meta text-ink-secondary">
                    {{ totals.confirmed_requesters }} of {{ totals.requesters }} confirmed
                </p>
            </div>
        </section>

        <TrendChart title="Sample requests / day" :labels="dates" :series="requests" />

        <!-- Top destinations -->
        <section class="space-y-2">
            <h2 class="text-subtitle text-ink">Top destinations</h2>
            <div class="overflow-x-auto rounded-md border border-hairline">
                <table class="w-full min-w-[420px] text-meta">
                    <thead>
                        <tr class="border-b border-hairline text-left text-ink-secondary">
                            <th class="px-4 py-2 font-medium">Destination</th>
                            <th class="px-4 py-2 font-medium">Requests</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="row in top_destinations"
                            :key="row.destination"
                            class="border-b border-hairline/60 last:border-0"
                        >
                            <td class="px-4 py-2 text-ink">{{ row.destination }}</td>
                            <td class="px-4 py-2 text-ink-secondary">{{ row.count }}</td>
                        </tr>
                        <tr v-if="top_destinations.length === 0">
                            <td colspan="2" class="px-4 py-6 text-center text-ink-secondary">
                                No sample requests in this window.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </main>
</template>
