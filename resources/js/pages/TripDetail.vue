<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { home } from '@/routes';
import { store } from '@/routes/trip';

// Confirm the geocoded place (Story 1.3) and capture the email to get started
// (Story 1.4). For a new (logged-out) visitor the email is the hero action; the
// resolved place is shown as supporting confirmation.
const props = defineProps<{
    pendingTrip: {
        destination: string;
        departure_date: string;
        return_date: string;
        canonical_place_name: string;
        latitude: number;
        longitude: number;
    };
}>();

const form = useForm({ email: '' });

const submit = () => form.submit(store());

// Friendly, timezone-safe date range from naive Y-m-d strings (e.g. "Jul 30 – Aug 4, 2026").
function formatDate(value: string): string {
    const [year, month, day] = value.split('-').map(Number);

    return new Date(year, month - 1, day).toLocaleDateString('en-US', {
        month: 'short',
        day: 'numeric',
    });
}

const dateRange = `${formatDate(props.pendingTrip.departure_date)} – ${formatDate(props.pendingTrip.return_date)}, ${props.pendingTrip.return_date.slice(0, 4)}`;
</script>

<template>
    <Head title="Start watching your trip" />

    <main
        class="mx-auto flex min-h-svh max-w-[560px] flex-col justify-center gap-6 px-4 py-12"
    >
        <div class="space-y-1">
            <h1 class="text-title text-ink">
                Start watching {{ pendingTrip.canonical_place_name }}
            </h1>
            <p class="text-meta text-ink-secondary">
                {{ dateRange }} ·
                <Link
                    :href="home()"
                    class="font-medium text-brand hover:text-brand-hover"
                >
                    Edit destination
                </Link>
            </p>
        </div>

        <form
            novalidate
            class="space-y-4 rounded-lg border border-hairline bg-surface-raised p-6"
            @submit.prevent="submit"
        >
            <p class="text-body text-ink-secondary">
                Enter your email and we'll send a one-tap sign-in link — no
                password, ever.
            </p>

            <div class="space-y-2">
                <Label for="email">Email</Label>
                <Input
                    id="email"
                    v-model="form.email"
                    type="email"
                    name="email"
                    autofocus
                    autocomplete="email"
                    placeholder="you@example.com"
                    :aria-invalid="Boolean(form.errors.email)"
                    aria-describedby="email-error"
                />
                <InputError id="email-error" :message="form.errors.email" />
            </div>

            <Button
                type="submit"
                class="h-11 w-full text-base"
                :disabled="form.processing"
            >
                {{ form.processing ? 'Sending your link…' : 'Email me a link' }}
            </Button>
        </form>
    </main>
</template>
