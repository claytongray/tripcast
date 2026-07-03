<script setup lang="ts">
import { onUnmounted, ref } from 'vue';
import FeedbackForm from '@/components/FeedbackForm.vue';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';

// Nav-opened feedback modal (Story 10.1): wraps the shared form and closes
// itself a beat after the thank-you shows, so the moment still registers.
defineProps<{
    open: boolean;
}>();

const emit = defineEmits<{
    'update:open': [value: boolean];
}>();

const closeTimer = ref<ReturnType<typeof setTimeout> | null>(null);

function scheduleClose(): void {
    closeTimer.value = setTimeout(() => emit('update:open', false), 1800);
}

onUnmounted(() => {
    if (closeTimer.value !== null) {
        clearTimeout(closeTimer.value);
    }
});
</script>

<template>
    <Dialog :open="open" @update:open="(value: boolean) => emit('update:open', value)">
        <DialogContent>
            <DialogHeader class="sr-only">
                <DialogTitle>Send feedback</DialogTitle>
                <DialogDescription>
                    Tell us what's working and what's missing.
                </DialogDescription>
            </DialogHeader>
            <FeedbackForm source="nav" @sent="scheduleClose" />
        </DialogContent>
    </Dialog>
</template>
