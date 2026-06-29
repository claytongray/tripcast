<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { home } from '@/routes';
import { store } from '@/routes/trip';

// Passive confirm of the geocoded place (Story 1.3) + email capture (Story 1.4).
defineProps<{
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
</script>

<template>
    <Head title="Confirm your trip" />

    <main
        class="mx-auto flex min-h-svh max-w-[560px] flex-col justify-center gap-6 px-4 py-12"
    >
        <div class="space-y-2">
            <h1 class="text-title text-ink">
                Watching {{ pendingTrip.canonical_place_name }}.
            </h1>
            <p class="text-body text-ink-secondary">
                {{ pendingTrip.departure_date }} – {{ pendingTrip.return_date }}
            </p>
            <p class="text-body text-ink-secondary">
                Not right?
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
            <div class="space-y-2">
                <Label for="email">Your email</Label>
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
