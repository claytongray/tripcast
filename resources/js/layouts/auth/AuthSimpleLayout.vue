<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import BrandMark from '@/components/BrandMark.vue';
import SiteFooter from '@/components/SiteFooter.vue';
import { home } from '@/routes';

defineProps<{
    title?: string;
    description?: string;
}>();
</script>

<template>
    <div class="flex min-h-svh flex-col bg-background">
        <main
            class="flex flex-1 flex-col items-center justify-center gap-6 p-6 md:p-10"
        >
            <div class="w-full max-w-sm">
                <div class="flex flex-col gap-8">
                    <div class="flex flex-col items-center gap-4">
                        <Link
                            :href="home()"
                            class="flex flex-col items-center gap-2 font-medium"
                        >
                            <div
                                class="mb-1 flex h-9 w-9 items-center justify-center rounded-md"
                            >
                                <BrandMark animate class="size-9" />
                            </div>
                            <span class="sr-only">{{ title }}</span>
                        </Link>
                        <div
                            v-if="title || description"
                            class="space-y-2 text-center"
                        >
                            <h1 v-if="title" class="text-title text-ink">
                                {{ title }}
                            </h1>
                            <p
                                v-if="description"
                                class="text-center text-body text-ink-secondary"
                            >
                                {{ description }}
                            </p>
                        </div>
                    </div>
                    <slot />
                </div>
            </div>
        </main>

        <SiteFooter />
    </div>
</template>
