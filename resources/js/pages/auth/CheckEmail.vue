<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import { store } from '@/routes/login';

const props = withDefaults(
    defineProps<{
        email: string;
        ttlMinutes: number;
        intent?: 'signup' | 'login';
    }>(),
    { intent: 'login' },
);

// A new signup must click the link to *start* their tripcast (it confirms their
// email and activates the trip); a returning user clicks to sign in.
const action = computed(() =>
    props.intent === 'signup' ? 'to start your tripcast' : 'to sign in',
);

const form = useForm({ email: props.email });

const resend = () => form.submit(store());
</script>

<template>
    <Head title="Check your email" />

    <div class="flex flex-col gap-6 text-center">
        <div class="space-y-2">
            <h1 class="text-title text-ink">Check your inbox</h1>
            <p class="text-body text-ink-secondary">
                Click the link we sent to
                <span class="font-medium text-foreground">{{ email }}</span>
                {{ action }}. It expires in {{ ttlMinutes }} minutes.
            </p>
        </div>

        <form @submit.prevent="resend">
            <Button
                type="submit"
                variant="outline"
                class="h-11 text-base"
                :disabled="form.processing"
            >
                Resend link
            </Button>
        </form>
    </div>
</template>
