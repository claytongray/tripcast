<script setup lang="ts">
import { onUnmounted, ref, watch } from 'vue';
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
const props = defineProps<{
    open: boolean;
}>();

const emit = defineEmits<{
    'update:open': [value: boolean];
}>();

const closeTimer = ref<ReturnType<typeof setTimeout> | null>(null);

function clearCloseTimer(): void {
    if (closeTimer.value !== null) {
        clearTimeout(closeTimer.value);
        closeTimer.value = null;
    }
}

function scheduleClose(): void {
    clearCloseTimer();
    closeTimer.value = setTimeout(() => emit('update:open', false), 1800);
}

// The component stays mounted in AppLayout across open/close, so a timer armed
// by a send must not survive into a reopened dialog and slam it shut.
watch(
    () => props.open,
    (open) => {
        if (open) {
            clearCloseTimer();
        }
    },
);

onUnmounted(clearCloseTimer);
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
