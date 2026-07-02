<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { computed } from 'vue';
import TrendChart from '@/components/admin/TrendChart.vue';
import WindowSwitcher from '@/components/admin/WindowSwitcher.vue';
import { emails } from '@/routes/admin';
import type { TrendSeries } from '@/types/metrics';

type LivenessSnapshot = {
    healthy: boolean;
    due: number;
    dispatched: number;
    duration_ms: number;
    error: string | null;
    ran_at: string;
};

type Projection = {
    tomorrow: {
        date: string;
        count: number;
        destinations: { destination: string; count: number }[];
    };
    forward: { dates: string[]; counts: number[] };
};

const props = defineProps<{
    window: number;
    windows: number[];
    dates: string[];
    sends: TrendSeries[];
    totals: {
        sent: number;
        failed: number;
        total: number;
        success_rate: number | null;
    };
    failures_by_reason: { weather: number; delivery: number; other: number };
    stuck_sending: number;
    liveness: LivenessSnapshot | null;
    projection: Projection;
}>();

const forwardSeries = computed<TrendSeries[]>(() => [
    { label: 'Projected sends', data: props.projection.forward.counts },
]);

const successLabel = computed(() =>
    props.totals.success_rate === null ? '—' : `${props.totals.success_rate}%`,
);

const ranAtLabel = computed(() =>
    props.liveness ? new Date(props.liveness.ran_at).toLocaleString() : '',
);
</script>

<template>
    <Head title="Admin — emails" />

    <main class="mx-auto flex max-w-5xl flex-col gap-6 px-6 py-12">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div class="space-y-1">
                <h1 class="text-title text-ink">Emails</h1>
                <p class="text-body text-ink-secondary">
                    Send health and daily-run liveness. Read-only.
                </p>
            </div>
            <WindowSwitcher
                :window="window"
                :windows="windows"
                :href-for="(days) => emails({ query: { days } }).url"
            />
        </div>

        <!-- Projected: what's coming (from the cadence authority, AD-11) -->
        <section
            class="space-y-3 rounded-md border border-hairline bg-surface-raised p-4"
        >
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <p class="text-meta text-ink-secondary">
                    Projected tomorrow · {{ projection.tomorrow.date }}
                </p>
                <p class="text-meta text-ink-secondary">Next 7 days</p>
            </div>
            <p class="text-title text-ink">
                {{ projection.tomorrow.count }} digests
            </p>

            <div
                v-if="projection.tomorrow.destinations.length > 0"
                class="flex flex-wrap gap-x-4 gap-y-1 text-meta text-ink-secondary"
            >
                <span
                    v-for="d in projection.tomorrow.destinations"
                    :key="d.destination"
                >
                    {{ d.destination }}
                    <span class="text-ink">{{ d.count }}</span>
                </span>
            </div>
            <p v-else class="text-meta text-ink-secondary">
                No sends projected tomorrow.
            </p>

            <TrendChart
                title="Projected sends / day"
                :labels="projection.forward.dates"
                :series="forwardSeries"
            />
        </section>

        <!-- Send-health cards -->
        <section class="grid grid-cols-2 gap-4 lg:grid-cols-4">
            <div
                class="rounded-md border border-hairline bg-surface-raised p-4"
            >
                <p class="text-meta text-ink-secondary">Sent-vs-failed rate</p>
                <p class="mt-1 text-title text-ink">{{ successLabel }}</p>
            </div>
            <div
                class="rounded-md border border-hairline bg-surface-raised p-4"
            >
                <p class="text-meta text-ink-secondary">Sent</p>
                <p class="mt-1 text-title text-positive">{{ totals.sent }}</p>
            </div>
            <div
                class="rounded-md border border-hairline bg-surface-raised p-4"
            >
                <p class="text-meta text-ink-secondary">Failed</p>
                <p
                    class="mt-1 text-title"
                    :class="totals.failed > 0 ? 'text-destructive' : 'text-ink'"
                >
                    {{ totals.failed }}
                </p>
            </div>
            <div
                class="rounded-md border border-hairline bg-surface-raised p-4"
            >
                <p class="text-meta text-ink-secondary">Stuck sending</p>
                <p
                    class="mt-1 text-title"
                    :class="stuck_sending > 0 ? 'text-destructive' : 'text-ink'"
                >
                    {{ stuck_sending }}
                </p>
            </div>
        </section>

        <!-- Failures by reason -->
        <section
            class="rounded-md border border-hairline bg-surface-raised p-4"
        >
            <p class="text-meta text-ink-secondary">
                Failures by reason (this window)
            </p>
            <div class="mt-2 flex flex-wrap gap-x-6 gap-y-1 text-body text-ink">
                <span
                    >Weather
                    <span class="text-ink-secondary">{{
                        failures_by_reason.weather
                    }}</span></span
                >
                <span
                    >Delivery
                    <span class="text-ink-secondary">{{
                        failures_by_reason.delivery
                    }}</span></span
                >
                <span
                    >Other
                    <span class="text-ink-secondary">{{
                        failures_by_reason.other
                    }}</span></span
                >
            </div>
        </section>

        <TrendChart
            title="Sends & failures / day"
            :labels="dates"
            :series="sends"
        />

        <!-- Daily-run liveness -->
        <section
            class="rounded-md border border-hairline bg-surface-raised p-4"
        >
            <p class="text-meta text-ink-secondary">Daily run</p>
            <template v-if="liveness">
                <div class="mt-1 flex flex-wrap items-baseline gap-x-4 gap-y-1">
                    <span
                        class="text-subtitle"
                        :class="
                            liveness.healthy
                                ? 'text-positive'
                                : 'text-destructive'
                        "
                    >
                        {{ liveness.healthy ? 'Healthy' : 'Unhealthy' }}
                    </span>
                    <span class="text-body text-ink">
                        {{ liveness.dispatched }} dispatched of
                        {{ liveness.due }} due
                    </span>
                    <span class="text-meta text-ink-secondary"
                        >{{ liveness.duration_ms }} ms · {{ ranAtLabel }}</span
                    >
                </div>
                <p
                    v-if="liveness.error"
                    class="mt-1 text-meta text-destructive"
                >
                    {{ liveness.error }}
                </p>
            </template>
            <p v-else class="mt-1 text-body text-ink-secondary">
                No daily run recorded yet.
            </p>
        </section>

        <!-- Opens/bounces are not trackable on the current mail driver -->
        <section class="rounded-md border border-dashed border-hairline p-4">
            <p class="text-meta text-ink-secondary">Opens &amp; bounces</p>
            <p class="mt-1 text-body text-ink-secondary">
                Deferred — not tracked on the current mail driver (needs an
                ESP).
            </p>
        </section>
    </main>
</template>
