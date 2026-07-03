<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import WindowSwitcher from '@/components/admin/WindowSwitcher.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { weatherProfileLabel } from '@/lib/weatherProfiles';
import { create, destroy, edit, index } from '@/routes/admin/promo-items';

type PromoItemRow = {
    id: number;
    slug: string;
    label: string;
    image_url: string | null;
    description: string | null;
    url: string;
    merchant: string;
    weather_profile: string;
    is_active: boolean;
    featured_from: string | null;
    featured_to: string | null;
    sort_order: number;
    impressions: number;
    clicks: number;
    ctr: number;
};

defineProps<{
    items: PromoItemRow[];
    profiles: string[];
    merchants: string[];
    window: number;
    windows: number[];
}>();

// One calm retire confirmation (UX-DR15) — soft-delete, never force.
const retireTarget = ref<PromoItemRow | null>(null);

function confirmRetire(): void {
    const item = retireTarget.value;
    retireTarget.value = null;

    if (!item) {
        return;
    }

    router.delete(destroy(item.id).url, { preserveScroll: true });
}

function featuredWindow(item: PromoItemRow): string {
    if (!item.featured_from) {
        return '—';
    }

    return `${item.featured_from} → ${item.featured_to ?? 'open'}`;
}
</script>

<template>
    <Head title="Admin — catalog" />

    <main class="mx-auto flex max-w-5xl flex-col gap-6 px-6 py-12">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div class="space-y-1">
                <h1 class="text-title text-ink">Catalog</h1>
                <p class="text-body text-ink-secondary">
                    Sponsored products, grouped by weather profile. Managed live
                    — no deploy.
                </p>
            </div>
            <div class="flex items-center gap-3">
                <WindowSwitcher
                    :window="window"
                    :windows="windows"
                    :href-for="(days) => index({ query: { days } }).url"
                />
                <Button as-child size="sm">
                    <Link :href="create().url">Add item</Link>
                </Button>
            </div>
        </div>

        <div class="overflow-x-auto rounded-md border border-hairline">
            <table class="w-full min-w-[920px] text-meta">
                <thead>
                    <tr
                        class="border-b border-hairline text-left text-ink-secondary"
                    >
                        <th class="px-4 py-2 font-medium">Label</th>
                        <th class="px-4 py-2 font-medium">Slug</th>
                        <th class="px-4 py-2 font-medium">Profile</th>
                        <th class="px-4 py-2 font-medium">Merchant</th>
                        <th class="px-4 py-2 font-medium">Featured</th>
                        <th class="px-4 py-2 font-medium">Sort</th>
                        <th class="px-4 py-2 font-medium">Impr.</th>
                        <th class="px-4 py-2 font-medium">Clicks</th>
                        <th class="px-4 py-2 font-medium">CTR</th>
                        <th class="px-4 py-2 font-medium">Status</th>
                        <th class="px-4 py-2 font-medium">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="item in items"
                        :key="item.id"
                        class="border-b border-hairline/60 last:border-0"
                    >
                        <td class="px-4 py-2 text-ink">{{ item.label }}</td>
                        <td class="px-4 py-2 font-mono text-ink-secondary">
                            {{ item.slug }}
                        </td>
                        <td class="px-4 py-2 text-ink-secondary">
                            {{ weatherProfileLabel(item.weather_profile) }}
                        </td>
                        <td class="px-4 py-2 text-ink-secondary">
                            {{ item.merchant }}
                        </td>
                        <td class="px-4 py-2 text-ink-secondary">
                            {{ featuredWindow(item) }}
                        </td>
                        <td class="px-4 py-2 text-ink-secondary">
                            {{ item.sort_order }}
                        </td>
                        <td class="px-4 py-2 text-ink-secondary">
                            {{ item.impressions }}
                        </td>
                        <td class="px-4 py-2 text-ink-secondary">
                            {{ item.clicks }}
                        </td>
                        <td class="px-4 py-2 text-brand">{{ item.ctr }}%</td>
                        <td class="px-4 py-2">
                            <span
                                class="inline-flex items-center rounded-full px-2 py-0.5 text-meta"
                                :class="
                                    item.is_active
                                        ? 'bg-surface-wash text-brand'
                                        : 'bg-surface-wash text-ink-secondary'
                                "
                            >
                                {{ item.is_active ? 'Active' : 'Inactive' }}
                            </span>
                        </td>
                        <td class="px-4 py-2">
                            <div class="flex items-center gap-2">
                                <Button as-child variant="ghost" size="sm">
                                    <Link :href="edit(item.id).url">Edit</Link>
                                </Button>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    @click="retireTarget = item"
                                >
                                    Retire
                                </Button>
                            </div>
                        </td>
                    </tr>
                    <tr v-if="items.length === 0">
                        <td
                            colspan="11"
                            class="px-4 py-6 text-center text-ink-secondary"
                        >
                            No catalog items yet — add your first.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </main>

    <!-- One calm retire confirmation (UX-DR15) -->
    <Dialog
        :open="retireTarget !== null"
        @update:open="
            (open: boolean) => {
                if (!open) retireTarget = null;
            }
        "
    >
        <DialogContent>
            <DialogHeader>
                <DialogTitle>Retire this item?</DialogTitle>
                <DialogDescription>
                    “{{ retireTarget?.label }}” leaves the catalog and stops
                    appearing in digests. Existing click links keep working, and
                    you can restore it later.
                </DialogDescription>
            </DialogHeader>
            <DialogFooter>
                <Button variant="ghost" @click="retireTarget = null"
                    >Keep it</Button
                >
                <Button variant="destructive" @click="confirmRetire"
                    >Retire item</Button
                >
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
