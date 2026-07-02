<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import BrandMark from '@/components/BrandMark.vue';
import SiteFooter from '@/components/SiteFooter.vue';
import { Toaster } from '@/components/ui/sonner';
import { home } from '@/routes';
import { overview as adminOverview } from '@/routes/admin';
import { edit as settingsEdit } from '@/routes/settings';

// Calm authenticated shell: a top bar linking home + account settings.
// Log out now lives on the settings page (Spec A).

// The "Admin" entry into the observability panel (Epic 7) shows only for admins;
// the routes are Gate-guarded regardless, this just keeps the link out of sight.
const page = usePage();
const isAdmin = computed(() => page.props.auth.user?.is_admin === true);
</script>

<template>
    <div class="flex min-h-screen flex-col bg-background text-foreground">
        <header class="border-b border-hairline">
            <div
                class="mx-auto flex max-w-3xl items-center justify-between gap-4 px-6 py-4"
            >
                <Link
                    :href="home()"
                    class="inline-flex items-center gap-2 rounded-sm text-title font-semibold focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background focus-visible:outline-none"
                >
                    <BrandMark animate class="size-5" />
                    tripcast
                </Link>
                <div class="flex items-center gap-1">
                    <Link
                        v-if="isAdmin"
                        :href="adminOverview()"
                        class="inline-flex h-11 items-center rounded-sm px-3 text-body font-medium text-brand hover:text-brand-hover focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background focus-visible:outline-none"
                    >
                        Admin
                    </Link>
                    <Link
                        :href="settingsEdit()"
                        class="inline-flex h-11 items-center rounded-sm px-3 text-body font-medium text-brand hover:text-brand-hover focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background focus-visible:outline-none"
                    >
                        Settings
                    </Link>
                </div>
            </div>
        </header>

        <div class="flex-1">
            <slot />
        </div>

        <SiteFooter />

        <!-- vue-sonner renders nothing without a mounted Toaster — every
             toast.success/error in Dashboard and Settings depends on this. -->
        <Toaster position="top-center" />
    </div>
</template>
