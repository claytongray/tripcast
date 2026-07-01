<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import { users as usersRoute } from '@/routes/admin';
import type { Paginated } from '@/types/pagination';

type AdminUserRow = {
    id: number;
    email: string;
    plan: 'free' | 'ad_free';
    confirmed: boolean;
    created_at: string;
    active_trips_count: number;
    last_login_at: string | null;
    has_sample_request: boolean;
};

const props = defineProps<{
    users: Paginated<AdminUserRow>;
    filters: { search: string };
}>();

// Debounced search — reloads only the list, preserving scroll and the input.
const search = ref(props.filters.search);
let debounce: ReturnType<typeof setTimeout> | undefined;

watch(search, (value) => {
    clearTimeout(debounce);
    debounce = setTimeout(() => {
        router.get(
            usersRoute.url(),
            { search: value },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    }, 300);
});
</script>

<template>
    <Head title="Admin — users" />

    <main class="mx-auto flex max-w-5xl flex-col gap-6 px-6 py-12">
        <div class="space-y-1">
            <h1 class="text-title text-ink">Users</h1>
            <p class="text-body text-ink-secondary">Everyone who has signed up. Read-only.</p>
        </div>

        <input
            v-model="search"
            type="search"
            placeholder="Search by email…"
            class="h-11 w-full max-w-sm rounded-md border border-hairline bg-surface-raised px-3 text-body text-ink placeholder:text-ink-secondary focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
        />

        <div class="overflow-x-auto rounded-md border border-hairline">
            <table class="w-full min-w-[720px] text-meta">
                <thead>
                    <tr class="border-b border-hairline text-left text-ink-secondary">
                        <th class="px-4 py-2 font-medium">Email</th>
                        <th class="px-4 py-2 font-medium">Plan</th>
                        <th class="px-4 py-2 font-medium">Confirmed</th>
                        <th class="px-4 py-2 font-medium">Created</th>
                        <th class="px-4 py-2 font-medium">Active trips</th>
                        <th class="px-4 py-2 font-medium">Last login</th>
                        <th class="px-4 py-2 font-medium">Sample?</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="user in users.data"
                        :key="user.id"
                        class="border-b border-hairline/60 last:border-0"
                    >
                        <td class="px-4 py-2 text-ink">{{ user.email }}</td>
                        <td class="px-4 py-2 text-ink-secondary">{{ user.plan }}</td>
                        <td class="px-4 py-2" :class="user.confirmed ? 'text-positive' : 'text-ink-secondary'">
                            {{ user.confirmed ? '✓' : '—' }}
                        </td>
                        <td class="px-4 py-2 text-ink-secondary">{{ user.created_at }}</td>
                        <td class="px-4 py-2 text-ink">{{ user.active_trips_count }}</td>
                        <td class="px-4 py-2 text-ink-secondary">{{ user.last_login_at ?? '—' }}</td>
                        <td class="px-4 py-2" :class="user.has_sample_request ? 'text-positive' : 'text-ink-secondary'">
                            {{ user.has_sample_request ? '✓' : '—' }}
                        </td>
                    </tr>
                    <tr v-if="users.data.length === 0">
                        <td colspan="7" class="px-4 py-6 text-center text-ink-secondary">
                            No users match.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="flex flex-wrap items-center justify-between gap-3">
            <p v-if="users.total > 0" class="text-meta text-ink-secondary">
                Showing {{ users.from }}–{{ users.to }} of {{ users.total }}
            </p>
            <nav v-if="users.last_page > 1" aria-label="Pagination" class="flex flex-wrap gap-1">
                <template v-for="(link, index) in users.links" :key="index">
                    <Link
                        v-if="link.url"
                        :href="link.url"
                        preserve-scroll
                        preserve-state
                        class="inline-flex h-9 min-w-9 items-center justify-center rounded-sm px-2 text-meta focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                        :class="link.active ? 'bg-surface-wash text-brand' : 'text-ink-secondary hover:text-ink'"
                    >
                        <span v-html="link.label" />
                    </Link>
                    <span
                        v-else
                        class="inline-flex h-9 min-w-9 items-center justify-center px-2 text-meta text-ink-secondary/50"
                        v-html="link.label"
                    />
                </template>
            </nav>
        </div>
    </main>
</template>
