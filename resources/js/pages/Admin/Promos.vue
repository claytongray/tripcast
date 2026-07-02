<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import WindowSwitcher from '@/components/admin/WindowSwitcher.vue';
import { promos } from '@/routes/admin';
import { index as catalog } from '@/routes/admin/promo-items';

type PromoRow = {
    slug: string;
    impressions: number;
    clicks: number;
    ctr: number;
};
type ProfileRow = {
    profile: string;
    impressions: number;
    clicks: number;
    ctr: number;
};

defineProps<{
    window: number;
    windows: number[];
    totals: { impressions: number; clicks: number; ctr: number };
    by_slug: PromoRow[];
    by_profile: ProfileRow[];
}>();
</script>

<template>
    <Head title="Admin — promos" />

    <main class="mx-auto flex max-w-5xl flex-col gap-6 px-6 py-12">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div class="space-y-1">
                <h1 class="text-title text-ink">Promos</h1>
                <p class="text-body text-ink-secondary">
                    Sponsored-link performance. Read-only.
                </p>
                <Link
                    :href="catalog().url"
                    class="inline-flex text-meta font-medium text-brand hover:text-brand-hover"
                >
                    Manage catalog →
                </Link>
            </div>
            <WindowSwitcher
                :window="window"
                :windows="windows"
                :href-for="(days) => promos({ query: { days } }).url"
            />
        </div>

        <!-- Overall -->
        <section class="grid grid-cols-3 gap-4">
            <div
                class="rounded-md border border-hairline bg-surface-raised p-4"
            >
                <p class="text-meta text-ink-secondary">Impressions</p>
                <p class="mt-1 text-title text-ink">{{ totals.impressions }}</p>
            </div>
            <div
                class="rounded-md border border-hairline bg-surface-raised p-4"
            >
                <p class="text-meta text-ink-secondary">Clicks</p>
                <p class="mt-1 text-title text-ink">{{ totals.clicks }}</p>
            </div>
            <div
                class="rounded-md border border-hairline bg-surface-raised p-4"
            >
                <p class="text-meta text-ink-secondary">CTR</p>
                <p class="mt-1 text-title text-brand">{{ totals.ctr }}%</p>
            </div>
        </section>

        <!-- By slug -->
        <section class="space-y-2">
            <h2 class="text-subtitle text-ink">By product</h2>
            <div class="overflow-x-auto rounded-md border border-hairline">
                <table class="w-full min-w-[520px] text-meta">
                    <thead>
                        <tr
                            class="border-b border-hairline text-left text-ink-secondary"
                        >
                            <th class="px-4 py-2 font-medium">Slug</th>
                            <th class="px-4 py-2 font-medium">Impressions</th>
                            <th class="px-4 py-2 font-medium">Clicks</th>
                            <th class="px-4 py-2 font-medium">CTR</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="row in by_slug"
                            :key="row.slug"
                            class="border-b border-hairline/60 last:border-0"
                        >
                            <td class="px-4 py-2 text-ink">{{ row.slug }}</td>
                            <td class="px-4 py-2 text-ink-secondary">
                                {{ row.impressions }}
                            </td>
                            <td class="px-4 py-2 text-ink-secondary">
                                {{ row.clicks }}
                            </td>
                            <td class="px-4 py-2 text-brand">{{ row.ctr }}%</td>
                        </tr>
                        <tr v-if="by_slug.length === 0">
                            <td
                                colspan="4"
                                class="px-4 py-6 text-center text-ink-secondary"
                            >
                                No promo events in this window.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- By weather profile -->
        <section class="space-y-2">
            <h2 class="text-subtitle text-ink">By weather profile</h2>
            <div class="overflow-x-auto rounded-md border border-hairline">
                <table class="w-full min-w-[520px] text-meta">
                    <thead>
                        <tr
                            class="border-b border-hairline text-left text-ink-secondary"
                        >
                            <th class="px-4 py-2 font-medium">Profile</th>
                            <th class="px-4 py-2 font-medium">Impressions</th>
                            <th class="px-4 py-2 font-medium">Clicks</th>
                            <th class="px-4 py-2 font-medium">CTR</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="row in by_profile"
                            :key="row.profile"
                            class="border-b border-hairline/60 last:border-0"
                        >
                            <td class="px-4 py-2 text-ink">
                                {{ row.profile }}
                            </td>
                            <td class="px-4 py-2 text-ink-secondary">
                                {{ row.impressions }}
                            </td>
                            <td class="px-4 py-2 text-ink-secondary">
                                {{ row.clicks }}
                            </td>
                            <td class="px-4 py-2 text-brand">{{ row.ctr }}%</td>
                        </tr>
                        <tr v-if="by_profile.length === 0">
                            <td
                                colspan="4"
                                class="px-4 py-6 text-center text-ink-secondary"
                            >
                                No promo events in this window.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </main>
</template>
