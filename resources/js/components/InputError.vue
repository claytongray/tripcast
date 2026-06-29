<script setup lang="ts">
defineProps<{
    message?: string;
}>();
</script>

<template>
    <div v-show="message">
        <p class="text-meta text-ink-secondary" role="alert" aria-live="polite">
            {{ message }}
        </p>
    </div>
</template>
