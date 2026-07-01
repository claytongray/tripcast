<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { dashboard, login, logout } from '@/routes';
import { store as sampleStore } from '@/routes/sample';
import { store } from '@/routes/trip-setup';

// Seeded from the session so "Edit destination" returns here with entries intact (FR-1).
const props = defineProps<{
    pendingTrip?: {
        destination?: string;
        departure_date?: string;
        return_date?: string;
        temperature_unit?: 'fahrenheit' | 'celsius';
    } | null;
}>();

const form = useForm({
    destination: props.pendingTrip?.destination ?? '',
    departure_date: props.pendingTrip?.departure_date ?? '',
    return_date: props.pendingTrip?.return_date ?? '',
    temperature_unit: props.pendingTrip?.temperature_unit ?? 'fahrenheit',
});

const temperatureUnits = [
    { value: 'fahrenheit', label: '°F' },
    { value: 'celsius', label: '°C' },
] as const;

const submit = () => form.submit(store());

const showSample = ref(false);
const sampleSent = ref<string | null>(null);
const sampleForm = useForm({ email: '' });

function openSample(): void {
    sampleForm.clearErrors();
    sampleForm.reset();
    sampleSent.value = null;
    showSample.value = true;
}

function submitSample(): void {
    sampleForm.submit(sampleStore(), {
        preserveScroll: true,
        onSuccess: () => {
            sampleSent.value = sampleForm.email;
            sampleForm.reset();
        },
    });
}
</script>

<template>
    <Head title="tripcast — the weather app you never have to open" />

    <div class="flex min-h-svh flex-col bg-surface-wash">
        <header class="flex items-center justify-end gap-4 px-4 py-4 md:px-6">
            <template v-if="$page.props.auth.user">
                <Link
                    :href="dashboard()"
                    class="text-meta font-medium text-brand hover:text-brand-hover"
                >
                    Dashboard
                </Link>
                <Link
                    :href="logout()"
                    method="post"
                    as="button"
                    type="button"
                    class="text-meta font-medium text-ink-secondary hover:text-ink"
                >
                    Log out
                </Link>
            </template>
            <Link
                v-else
                :href="login()"
                class="text-meta font-medium text-brand hover:text-brand-hover"
            >
                Sign in
            </Link>
        </header>

        <main
            class="flex flex-1 flex-col items-center justify-center px-4 pb-16"
        >
            <div class="w-full max-w-[720px] space-y-8">
                <div class="space-y-3 text-center">
                    <h1 class="text-display text-ink">tripcast</h1>
                    <p class="text-subtitle text-ink-secondary">
                        The weather app you never have to open.
                    </p>
                </div>

                <form
                    novalidate
                    aria-label="Set up a trip"
                    class="space-y-5 rounded-lg border border-hairline bg-surface-raised p-6 md:p-8"
                    @submit.prevent="submit"
                >
                    <div class="space-y-2">
                        <Label for="destination">Where are you headed?</Label>
                        <Input
                            id="destination"
                            v-model="form.destination"
                            type="text"
                            name="destination"
                            autofocus
                            autocomplete="off"
                            placeholder="Edinburgh"
                            :aria-invalid="Boolean(form.errors.destination)"
                            aria-describedby="destination-error"
                        />
                        <InputError
                            id="destination-error"
                            :message="form.errors.destination"
                        />
                    </div>

                    <div class="grid gap-5 sm:grid-cols-2">
                        <div class="space-y-2">
                            <Label for="departure_date">Departure</Label>
                            <Input
                                id="departure_date"
                                v-model="form.departure_date"
                                type="date"
                                name="departure_date"
                                :aria-invalid="
                                    Boolean(form.errors.departure_date)
                                "
                                aria-describedby="departure_date-error"
                            />
                            <InputError
                                id="departure_date-error"
                                :message="form.errors.departure_date"
                            />
                        </div>

                        <div class="space-y-2">
                            <Label for="return_date">Return</Label>
                            <Input
                                id="return_date"
                                v-model="form.return_date"
                                type="date"
                                name="return_date"
                                :aria-invalid="Boolean(form.errors.return_date)"
                                aria-describedby="return_date-error"
                            />
                            <InputError
                                id="return_date-error"
                                :message="form.errors.return_date"
                            />
                        </div>
                    </div>

                    <fieldset class="space-y-2">
                        <legend class="text-meta font-medium text-ink">
                            Temperature
                        </legend>
                        <div
                            class="inline-flex rounded-lg border border-hairline p-1"
                            role="group"
                            aria-label="Temperature unit"
                        >
                            <button
                                v-for="unit in temperatureUnits"
                                :key="unit.value"
                                type="button"
                                class="rounded-md px-4 py-1.5 text-meta font-medium transition-colors"
                                :class="
                                    form.temperature_unit === unit.value
                                        ? 'bg-brand text-white'
                                        : 'text-ink-secondary hover:text-ink'
                                "
                                :aria-pressed="form.temperature_unit === unit.value"
                                @click="form.temperature_unit = unit.value"
                            >
                                {{ unit.label }}
                            </button>
                        </div>
                    </fieldset>

                    <Button
                        type="submit"
                        class="h-11 w-full text-base"
                        :disabled="form.processing"
                    >
                        {{
                            form.processing
                                ? 'Finding that place…'
                                : 'Start watching this trip'
                        }}
                    </Button>
                </form>

                <p class="text-center text-meta text-ink-secondary">
                    Not ready yet?
                    <button
                        type="button"
                        class="font-medium text-brand hover:text-brand-hover"
                        @click="openSample"
                    >
                        Send me a sample
                    </button>
                </p>
            </div>
        </main>

        <Dialog
            :open="showSample"
            @update:open="(open: boolean) => { if (!open) showSample = false; }"
        >
            <DialogContent>
                <template v-if="sampleSent === null">
                    <DialogHeader>
                        <DialogTitle>See a sample tripcast</DialogTitle>
                        <DialogDescription>
                            Enter your email and we'll send a sample forecast straight to your
                            inbox.
                        </DialogDescription>
                    </DialogHeader>
                    <form class="space-y-4" @submit.prevent="submitSample">
                        <div class="space-y-2">
                            <Label for="sample-email">Email</Label>
                            <Input
                                id="sample-email"
                                v-model="sampleForm.email"
                                type="email"
                                name="email"
                                placeholder="you@example.com"
                                :aria-invalid="Boolean(sampleForm.errors.email)"
                                aria-describedby="sample-email-error"
                            />
                            <InputError id="sample-email-error" :message="sampleForm.errors.email" />
                        </div>
                        <DialogFooter class="gap-2">
                            <Button
                                type="button"
                                variant="ghost"
                                @click="showSample = false"
                            >
                                Cancel
                            </Button>
                            <Button type="submit" :disabled="sampleForm.processing">
                                {{ sampleForm.processing ? 'Sending…' : 'Send my sample' }}
                            </Button>
                        </DialogFooter>
                    </form>
                </template>
                <template v-else>
                    <DialogHeader>
                        <DialogTitle>Your sample is on its way.</DialogTitle>
                        <DialogDescription>
                            Check {{ sampleSent }} — the email has a link to create your own when
                            you're ready.
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button type="button" @click="showSample = false">Done</Button>
                    </DialogFooter>
                </template>
            </DialogContent>
        </Dialog>
    </div>
</template>
