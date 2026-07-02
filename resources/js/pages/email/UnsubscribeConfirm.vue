<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';

const props = defineProps<{
    postUrl: string;
}>();

// GET only confirms; the POST sets the account-level opt-out, so scanners and
// prefetch never unsubscribe anyone. The signed URL is reused for the POST.
const form = useForm({});

const unsubscribe = () => form.post(props.postUrl);
</script>

<template>
    <Head title="Unsubscribe" />

    <div class="flex flex-col gap-6 text-center">
        <div class="space-y-2">
            <h1 class="text-title text-ink">Unsubscribe from all emails?</h1>
            <p class="text-body text-ink-secondary">
                This stops every tripcast email for your account — for all of
                your trips.
            </p>
        </div>

        <form @submit.prevent="unsubscribe">
            <Button
                type="submit"
                class="h-11 text-base"
                :disabled="form.processing"
            >
                Unsubscribe
            </Button>
        </form>
    </div>
</template>
