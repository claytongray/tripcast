<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { Calendar } from '@lucide/vue';
import { ref } from 'vue';
import BrandMark from '@/components/BrandMark.vue';
import DestinationAutocomplete from '@/components/DestinationAutocomplete.vue';
import InputError from '@/components/InputError.vue';
import SiteFooter from '@/components/SiteFooter.vue';
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
import { todayInEasternTime } from '@/lib/date';
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
    // Autocomplete selection (FR-22): typed text clears the id (stale-id
    // guard); a picked suggestion sets text + id together via onPlaceSelect.
    place_id: '',
    session_token: '',
});

function onDestinationType(value: string): void {
    form.destination = value;
    form.place_id = '';
}

function onPlaceSelect(
    suggestion: { place_id: string; label: string },
    sessionToken: string | null,
): void {
    form.destination = suggestion.label;
    form.place_id = suggestion.place_id;
    form.session_token = sessionToken ?? '';
}

const temperatureUnits = [
    { value: 'fahrenheit', label: '°F' },
    { value: 'celsius', label: '°C' },
] as const;

const submit = () => form.submit(store());

// Native min affordance (FR-23): mirrors the server's America/New_York
// validation anchor; the FormRequest rule remains the authority.
const todayEt = todayInEasternTime();

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
                    <h1
                        class="flex items-center justify-center gap-3 text-display text-ink"
                    >
                        <BrandMark animate class="size-10 md:size-12" />
                        <span>tripcast</span>
                    </h1>
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
                        <DestinationAutocomplete
                            id="destination"
                            :model-value="form.destination"
                            name="destination"
                            placeholder="Edinburgh"
                            :aria-invalid="Boolean(form.errors.destination)"
                            aria-describedby="destination-error"
                            @update:model-value="onDestinationType"
                            @select="onPlaceSelect"
                        />
                        <InputError
                            id="destination-error"
                            :message="form.errors.destination"
                        />
                    </div>

                    <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                        <div class="space-y-2">
                            <Label for="departure_date">Departure</Label>
                            <div class="relative">
                                <Input
                                    id="departure_date"
                                    v-model="form.departure_date"
                                    type="date"
                                    name="departure_date"
                                    class="pr-10"
                                    :min="todayEt"
                                    placeholder="mm/dd/yyyy"
                                    :data-empty="
                                        form.departure_date ? 'false' : 'true'
                                    "
                                    :aria-invalid="
                                        Boolean(form.errors.departure_date)
                                    "
                                    aria-describedby="departure_date-error"
                                />
                                <Calendar
                                    class="pointer-events-none absolute top-1/2 right-3 size-4 -translate-y-1/2 text-ink-secondary"
                                    aria-hidden="true"
                                />
                            </div>
                            <InputError
                                id="departure_date-error"
                                :message="form.errors.departure_date"
                            />
                        </div>

                        <div class="space-y-2">
                            <Label for="return_date">Return</Label>
                            <div class="relative">
                                <Input
                                    id="return_date"
                                    v-model="form.return_date"
                                    type="date"
                                    name="return_date"
                                    class="pr-10"
                                    :min="form.departure_date || todayEt"
                                    placeholder="mm/dd/yyyy"
                                    :data-empty="
                                        form.return_date ? 'false' : 'true'
                                    "
                                    :aria-invalid="
                                        Boolean(form.errors.return_date)
                                    "
                                    aria-describedby="return_date-error"
                                />
                                <Calendar
                                    class="pointer-events-none absolute top-1/2 right-3 size-4 -translate-y-1/2 text-ink-secondary"
                                    aria-hidden="true"
                                />
                            </div>
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
                                :aria-pressed="
                                    form.temperature_unit === unit.value
                                "
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
                                : 'Create my tripcast'
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

        <!-- Below-the-fold explainer (FR-24): one lean section, copy driven by
             the fresh-eyes comprehension audit — what arrives, where, how often,
             when it starts/stops, that it's free, and what one looks like. -->
        <section
            aria-labelledby="explainer-heading"
            class="border-t border-hairline bg-surface"
        >
            <div class="mx-auto max-w-[720px] space-y-10 px-4 py-16">
                <div class="space-y-3">
                    <h2 id="explainer-heading" class="text-title text-ink">
                        What's a tripcast?
                    </h2>
                    <p class="text-body text-ink-secondary">
                        A tripcast is a short weather email for one trip. Tell
                        us where you're going and when, and we watch the
                        forecast so you don't have to. It's free, and there's no
                        app to open.
                    </p>
                </div>

                <ol class="space-y-5">
                    <li class="flex gap-4">
                        <span
                            class="flex size-7 shrink-0 items-center justify-center rounded-full bg-surface-wash text-meta font-medium text-ink"
                            aria-hidden="true"
                            >1</span
                        >
                        <p class="text-body text-ink-secondary">
                            <span class="font-medium text-ink"
                                >Enter your trip.</span
                            >
                            A destination, your dates, and your email — no
                            password, that's the whole setup.
                        </p>
                    </li>
                    <li class="flex gap-4">
                        <span
                            class="flex size-7 shrink-0 items-center justify-center rounded-full bg-surface-wash text-meta font-medium text-ink"
                            aria-hidden="true"
                            >2</span
                        >
                        <p class="text-body text-ink-secondary">
                            <span class="font-medium text-ink"
                                >Get one calm email each morning.</span
                            >
                            Starting 7 days before departure: your destination's
                            7-day forecast, refreshed daily, straight to your
                            inbox.
                        </p>
                    </li>
                    <li class="flex gap-4">
                        <span
                            class="flex size-7 shrink-0 items-center justify-center rounded-full bg-surface-wash text-meta font-medium text-ink"
                            aria-hidden="true"
                            >3</span
                        >
                        <p class="text-body text-ink-secondary">
                            <span class="font-medium text-ink"
                                >It stops by itself.</span
                            >
                            The morning after you're home, the emails end. Every
                            one has a one-click unsubscribe.
                        </p>
                    </li>
                </ol>

                <figure class="space-y-3">
                    <figcaption class="text-body text-ink-secondary">
                        Here's what lands in your inbox:
                    </figcaption>
                    <img
                        src="/images/digest-sample.png"
                        alt="A tripcast email for Edinburgh: seven days of forecast — highs, lows, conditions, and rain chance — in one morning email"
                        width="1200"
                        height="2107"
                        loading="lazy"
                        class="w-full max-w-[480px] rounded-lg border border-hairline"
                    />
                </figure>

                <p class="text-body text-ink-secondary">
                    Want to see one in your own inbox first?
                    <button
                        type="button"
                        class="font-medium text-brand hover:text-brand-hover"
                        @click="openSample"
                    >
                        Send me a sample
                    </button>
                </p>
            </div>
        </section>

        <SiteFooter />

        <Dialog
            :open="showSample"
            @update:open="
                (open: boolean) => {
                    if (!open) showSample = false;
                }
            "
        >
            <DialogContent>
                <template v-if="sampleSent === null">
                    <DialogHeader>
                        <DialogTitle>See a sample tripcast</DialogTitle>
                        <DialogDescription>
                            Enter your email and we'll send a sample forecast
                            straight to your inbox.
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
                            <InputError
                                id="sample-email-error"
                                :message="sampleForm.errors.email"
                            />
                        </div>
                        <DialogFooter class="gap-2">
                            <Button
                                type="button"
                                variant="ghost"
                                @click="showSample = false"
                            >
                                Cancel
                            </Button>
                            <Button
                                type="submit"
                                :disabled="sampleForm.processing"
                            >
                                {{
                                    sampleForm.processing
                                        ? 'Sending…'
                                        : 'Send my sample'
                                }}
                            </Button>
                        </DialogFooter>
                    </form>
                </template>
                <template v-else>
                    <DialogHeader>
                        <DialogTitle>Your sample is on its way.</DialogTitle>
                        <DialogDescription>
                            Check {{ sampleSent }} — the email has a link to
                            create your own when you're ready.
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button type="button" @click="showSample = false"
                            >Done</Button
                        >
                    </DialogFooter>
                </template>
            </DialogContent>
        </Dialog>
    </div>
</template>
