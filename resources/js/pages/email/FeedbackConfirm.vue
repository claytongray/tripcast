<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';

const props = defineProps<{
    place: string;
    reaction: string;
    reactionLabel: string;
    postUrl: string;
}>();

// GET only confirms; the reaction is recorded on this POST, so mail scanners and
// prefetch never record feedback. The signed URL is reused for the POST.
const form = useForm({});

const sendFeedback = () => form.post(props.postUrl);
</script>

<template>
    <Head title="Send feedback" />

    <div class="flex flex-col gap-6 text-center">
        <div class="space-y-2">
            <h1 class="text-title text-ink">
                Mark today's forecast as {{ props.reactionLabel }}?
            </h1>
            <p class="text-body text-ink-secondary">
                One tap for {{ props.place }} — no login, no survey.
            </p>
        </div>

        <form @submit.prevent="sendFeedback">
            <Button
                type="submit"
                class="h-11 text-base"
                :disabled="form.processing"
            >
                Send feedback
            </Button>
        </form>
    </div>
</template>
