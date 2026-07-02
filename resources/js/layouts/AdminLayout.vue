<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { home } from '@/routes';
import { overview, users, emails, promos, samples, monitoring } from '@/routes/admin';
import { index as catalog } from '@/routes/admin/promo-items';
import { edit as settingsEdit } from '@/routes/settings';

// Phone-first admin shell (Epic 7): a calm top bar plus a horizontally
// scrollable tab strip so all six sections stay reachable at ~360px width.
const tabs = [
    { label: 'Overview', href: overview(), path: '/admin/overview' },
    { label: 'Users', href: users(), path: '/admin/users' },
    { label: 'Emails', href: emails(), path: '/admin/emails' },
    { label: 'Promos', href: promos(), path: '/admin/promos' },
    { label: 'Catalog', href: catalog(), path: '/admin/promo-items' },
    { label: 'Samples', href: samples(), path: '/admin/samples' },
    { label: 'Monitoring', href: monitoring(), path: '/admin/monitoring' },
] as const;

// Active section derived from the current path (not the route() helper), so the
// indicator tracks real navigation including redirects.
const page = usePage();
const currentPath = computed(() => page.url.split('?')[0]);
const isActive = (path: string): boolean => currentPath.value.startsWith(path);
</script>

<template>
    <div class="min-h-screen bg-background text-foreground">
        <header class="border-b border-hairline">
            <div
                class="mx-auto flex max-w-5xl items-center justify-between gap-4 px-6 py-4"
            >
                <Link
                    :href="home()"
                    class="rounded-sm text-title font-semibold focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background focus-visible:outline-none"
                >
                    tripcast
                </Link>
                <Link
                    :href="settingsEdit()"
                    class="inline-flex h-11 items-center rounded-sm px-3 text-body font-medium text-brand hover:text-brand-hover focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background focus-visible:outline-none"
                >
                    Settings
                </Link>
            </div>

            <nav
                aria-label="Admin sections"
                class="mx-auto max-w-5xl overflow-x-auto whitespace-nowrap px-6"
            >
                <ul class="flex gap-1">
                    <li v-for="tab in tabs" :key="tab.path">
                        <Link
                            :href="tab.href"
                            :aria-current="isActive(tab.path) ? 'page' : undefined"
                            class="inline-flex h-11 items-center border-b-2 px-3 text-body font-medium focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background focus-visible:outline-none"
                            :class="
                                isActive(tab.path)
                                    ? 'border-brand text-brand'
                                    : 'border-transparent text-ink-secondary hover:text-ink'
                            "
                        >
                            {{ tab.label }}
                        </Link>
                    </li>
                </ul>
            </nav>
        </header>

        <slot />
    </div>
</template>
