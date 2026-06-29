<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';

const props = defineProps<{
    token: string;
}>();

// The emailed link only lands here (GET) — the token is consumed by this
// CSRF-protected POST, so scanners and prefetch never burn it.
const form = useForm({});

const signIn = () => form.post(`/auth/magic/${props.token}`);
</script>

<template>
    <Head title="Sign in" />

    <div class="flex flex-col gap-6 text-center">
        <div class="space-y-2">
            <h1 class="text-xl font-medium">You're almost in</h1>
            <p class="text-sm text-muted-foreground">
                Tap below to finish signing in to tripcast.
            </p>
        </div>

        <form @submit.prevent="signIn">
            <Button
                type="submit"
                class="h-11 text-base"
                :disabled="form.processing"
            >
                Sign in
            </Button>
        </form>
    </div>
</template>
