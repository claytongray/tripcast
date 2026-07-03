<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import { toast } from 'vue-sonner';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { store } from '@/routes/feedback';

// Reusable feedback form (Story 10.1): inline on the dashboard, wrapped in a
// dialog from the nav. Sent-state is per-visit only, like the sample card.
const props = defineProps<{
    source: 'dashboard' | 'nav';
}>();

const emit = defineEmits<{
    sent: [];
}>();

const form = useForm({
    message: '',
    source: props.source,
});

const sent = ref(false);

function submitFeedback(): void {
    form.post(store().url, {
        preserveScroll: true,
        onSuccess: () => {
            sent.value = true;
            form.reset('message');
            toast.success('Sent — thank you. Notes like this shape tripcast.');
            emit('sent');
        },
    });
}
</script>

<template>
    <div class="space-y-2">
        <h2 class="text-subtitle text-ink">Thoughts? Ideas? Please send them.</h2>
        <p class="text-body text-ink-secondary">
            tripcast is young — what you tell us now genuinely shapes it. We
            read every note.
        </p>

        <p v-if="sent" class="pt-1 text-body text-ink" role="status">
            Thank you — this is genuinely valuable to us.
        </p>

        <form
            v-else
            novalidate
            class="space-y-3 pt-1"
            @submit.prevent="submitFeedback"
        >
            <div class="space-y-1.5">
                <Label class="sr-only" :for="`feedback-message-${source}`">
                    Your feedback
                </Label>
                <Textarea
                    :id="`feedback-message-${source}`"
                    v-model="form.message"
                    rows="3"
                    placeholder="What's working? What's missing?"
                    :aria-invalid="Boolean(form.errors.message)"
                />
                <InputError :message="form.errors.message" />
            </div>
            <Button
                type="submit"
                variant="outline"
                size="sm"
                :disabled="form.processing"
            >
                Send feedback
            </Button>
        </form>
    </div>
</template>
