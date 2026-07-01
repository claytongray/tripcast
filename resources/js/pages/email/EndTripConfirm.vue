<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';

const props = defineProps<{
    place: string;
    postUrl: string;
}>();

// The emailed link only lands here (GET) — the change happens on this POST, so
// mail scanners and prefetch never end a trip. The signed URL is reused for the POST.
const form = useForm({});

const endTrip = () => form.post(props.postUrl);
</script>

<template>
    <Head title="End this trip" />

    <div class="flex flex-col gap-6 text-center">
        <div class="space-y-2">
            <h1 class="text-title text-ink">End this trip?</h1>
            <p class="text-body text-ink-secondary">
                We'll stop your daily forecasts for {{ props.place }}.
            </p>
        </div>

        <form @submit.prevent="endTrip">
            <Button
                type="submit"
                class="h-11 text-base"
                :disabled="form.processing"
            >
                End this trip
            </Button>
        </form>
    </div>
</template>
