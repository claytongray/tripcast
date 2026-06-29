<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { home } from '@/routes';
import { store } from '@/routes/trip';

// One cohesive card: confirm the geocoded place (Story 1.3) and capture the
// email to get started (Story 1.4). The email is the hero action for a new,
// logged-out visitor; the place + dates sit as quiet confirmation.
const props = defineProps<{
    pendingTrip: {
        destination: string;
        departure_date: string;
        return_date: string;
        canonical_place_name: string;
        latitude: number;
        longitude: number;
    };
    forecastStartLabel: string;
}>();

const form = useForm({ email: '' });

const submit = () => form.submit(store());

// Friendly, timezone-safe range from naive Y-m-d strings (e.g. "Jul 30 – Aug 4, 2026").
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
    <Head title="Start your tripcast" />

    <main
        class="flex min-h-svh flex-col items-center justify-center bg-surface-wash px-4 py-12 md:py-20"
    >
        <article
            class="w-full max-w-[520px] space-y-5 rounded-lg border border-hairline bg-surface-raised p-6 md:p-8"
        >
            <p
                class="flex items-center gap-2 text-meta font-medium text-ink-secondary"
            >
                <span
                    class="inline-block size-2 rounded-full bg-sunrise"
                    aria-hidden="true"
                ></span>
                You're almost there
            </p>

            <div class="space-y-1">
                <h1 class="text-title text-ink">
                    Where should we send your tripcast for
                    {{ pendingTrip.canonical_place_name }}?
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

            <form novalidate class="space-y-3" @submit.prevent="submit">
                <p class="text-body text-ink-secondary">
                    Enter your email to start receiving your tripcast.
                </p>

                <div class="space-y-2">
                    <Label for="email" class="sr-only">Email address</Label>
                    <Input
                        id="email"
                        v-model="form.email"
                        type="email"
                        name="email"
                        autofocus
                        autocomplete="email"
                        placeholder="you@example.com"
                        :aria-invalid="Boolean(form.errors.email)"
                        aria-describedby="email-error email-help"
                    />
                    <InputError id="email-error" :message="form.errors.email" />
                    <p id="email-help" class="text-meta text-ink-secondary">
                        It starts {{ forecastStartLabel }} and runs through your
                        trip — so you're ready to pack, and ready for the day.
                    </p>
                </div>

                <Button
                    type="submit"
                    class="h-11 w-full text-base"
                    :disabled="form.processing"
                >
                    {{
                        form.processing
                            ? 'Sending your link…'
                            : 'Email me a link'
                    }}
                </Button>
            </form>
        </article>
    </main>
</template>
