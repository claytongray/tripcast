<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { Calendar } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import DestinationAutocomplete from '@/components/DestinationAutocomplete.vue';
import FeedbackForm from '@/components/FeedbackForm.vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
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
import { self as sampleSelf } from '@/routes/sample';
import { destroy, pause, resume, store } from '@/routes/trips';

type TripStatus = 'active' | 'paused' | 'completed';

interface TripCard {
    id: number;
    destination: string;
    departure_date: string;
    return_date: string;
    status: TripStatus;
    days_until_departure: number;
    // Next-send status (Spec B), all server-computed on the send clock.
    next_send_date: string | null;
    days_until_send: number | null;
    is_sending: boolean;
}

const props = defineProps<{
    upcomingTrips: TripCard[];
    pastTrips: TripCard[];
    maxActiveTrips: number;
    activeTripCount: number;
}>();

// Free-tier cap (AD-15): at the limit we hide the add affordance and show a calm
// note — no upsell, no billing surface.
const atTripLimit = computed(
    () => props.activeTripCount >= props.maxActiveTrips,
);

// Local copies drive the optimistic UI; server props are the source of truth and
// resync these whenever the page reloads after a mutation.
const upcoming = ref<TripCard[]>(props.upcomingTrips.map((t) => ({ ...t })));
const past = ref<TripCard[]>(props.pastTrips.map((t) => ({ ...t })));

watch(
    () => props.upcomingTrips,
    (v) => (upcoming.value = v.map((t) => ({ ...t }))),
);
watch(
    () => props.pastTrips,
    (v) => (past.value = v.map((t) => ({ ...t }))),
);

const pill: Record<TripStatus, { label: string; class: string }> = {
    active: {
        label: 'Active',
        class: 'border-transparent bg-surface-wash text-brand',
    },
    paused: {
        label: 'Paused',
        class: 'border-hairline bg-transparent text-ink-secondary',
    },
    completed: {
        label: 'Completed',
        class: 'border-transparent bg-surface-wash text-ink-secondary',
    },
};

const MONTHS = [
    'Jan',
    'Feb',
    'Mar',
    'Apr',
    'May',
    'Jun',
    'Jul',
    'Aug',
    'Sep',
    'Oct',
    'Nov',
    'Dec',
];

function formatDay(date: string): string {
    const [, m, d] = date.split('-').map(Number);

    return `${MONTHS[m - 1]} ${d}`;
}

function dateRange(trip: TripCard): string {
    return `${formatDay(trip.departure_date)} – ${formatDay(trip.return_date)}`;
}

function countdown(days: number): string {
    if (days > 1) {
        return `${days} days until departure`;
    }

    if (days === 1) {
        return '1 day until departure';
    }

    if (days === 0) {
        return 'Departs today';
    }

    return 'Trip in progress';
}

// The next-send line beneath the dates. Sending now → a calm "this/tomorrow
// morning"; still before the window → an upfront count + date; otherwise nothing
// (paused/ended trips carry their own note).
function nextSendLine(trip: TripCard): string | null {
    if (trip.is_sending) {
        if (trip.days_until_send === 0) {
            return 'Next forecast this morning';
        }

        return 'Next forecast tomorrow morning';
    }

    if (trip.next_send_date !== null && trip.days_until_send !== null) {
        const noun = trip.days_until_send === 1 ? 'day' : 'days';

        return `First forecast in ${trip.days_until_send} ${noun} · ${formatDay(trip.next_send_date)}`;
    }

    return null;
}

function setStatus(
    trip: TripCard,
    status: TripStatus,
    route: { url: string },
    errorMessage: string,
): void {
    const previous = trip.status;
    trip.status = status; // optimistic
    router.patch(
        route.url,
        {},
        {
            preserveScroll: true,
            onError: () => {
                trip.status = previous;
                toast.error(errorMessage);
            },
        },
    );
}

function pauseTrip(trip: TripCard): void {
    setStatus(
        trip,
        'paused',
        pause(trip.id),
        "Couldn't pause that trip. Please try again.",
    );
}

function resumeTrip(trip: TripCard): void {
    setStatus(
        trip,
        'active',
        resume(trip.id),
        "Couldn't resume that trip. Please try again.",
    );
}

// Delete confirmation — exactly one calm dialog (UX-DR15).
const deleteTarget = ref<TripCard | null>(null);

function confirmDelete(): void {
    const trip = deleteTarget.value;
    deleteTarget.value = null;

    if (!trip) {
        return;
    }

    upcoming.value = upcoming.value.filter((t) => t.id !== trip.id); // optimistic
    router.delete(destroy(trip.id).url, {
        preserveScroll: true,
        onError: () => {
            upcoming.value = props.upcomingTrips.map((t) => ({ ...t })); // rollback
            toast.error("Couldn't remove that trip. Please try again.");
        },
    });
}

// Inline add-trip panel (Story 3.2). On success Inertia follows the redirect to
// the shared TripAdded success screen, so no local reset is needed.
const showAddPanel = ref(false);
const form = useForm({
    destination: '',
    departure_date: '',
    return_date: '',
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

// Native min affordance (FR-23): mirrors the server's America/New_York
// validation anchor; the FormRequest rule remains the authority.
const todayEt = todayInEasternTime();

function openAddPanel(): void {
    form.clearErrors();
    showAddPanel.value = true;
}

function submitAdd(): void {
    form.submit(store());
}

// "Send a sample" card (spec 2026-07-02): posts to the authenticated sample
// endpoint; sent-state is per-visit only, by design.
const sampleSending = ref(false);
const sampleSent = ref(false);

function sendSample(): void {
    sampleSending.value = true;
    router.post(
        sampleSelf().url,
        {},
        {
            preserveScroll: true,
            onSuccess: () => {
                sampleSent.value = true;
                toast.success('Sample on its way — check your inbox.');
            },
            onError: (errors) => {
                toast.error(
                    errors.sample ??
                        "Couldn't send the sample. Please try again.",
                );
            },
            onFinish: () => {
                sampleSending.value = false;
            },
        },
    );
}
</script>

<template>
    <Head title="Your trips" />

    <main class="mx-auto flex max-w-3xl flex-col gap-8 px-6 py-12">
        <div class="flex items-start justify-between gap-4">
            <div class="space-y-1">
                <h1 class="text-title text-ink">Your trips</h1>
                <p class="text-body text-ink-secondary">
                    We email you a forecast for each one every morning.
                </p>
            </div>
            <Button
                v-if="!showAddPanel && !atTripLimit"
                variant="outline"
                size="sm"
                @click="openAddPanel"
            >
                Add a trip
            </Button>
        </div>

        <!-- Free-tier cap (AD-15): calm limit note, no upsell -->
        <p
            v-if="atTripLimit"
            class="rounded-md border border-hairline bg-surface-wash p-4 text-meta text-ink-secondary"
        >
            You're at your plan's trip limit ({{ maxActiveTrips }}). Pause or
            remove one to add another.
        </p>

        <p
            v-if="$page.props.flash.status"
            class="rounded-md border border-hairline bg-surface-wash p-4 text-body text-ink"
            role="status"
        >
            {{ $page.props.flash.status }}
        </p>

        <!-- Inline add-trip panel (Story 3.2) -->
        <section
            v-if="showAddPanel"
            class="space-y-4 rounded-md border border-hairline bg-surface-raised p-5"
        >
            <h2 class="text-subtitle text-ink">Add a trip</h2>
            <form novalidate class="space-y-4" @submit.prevent="submitAdd">
                <div class="space-y-1.5">
                    <Label for="add-destination">Destination</Label>
                    <DestinationAutocomplete
                        id="add-destination"
                        :model-value="form.destination"
                        placeholder="Edinburgh, UK"
                        :aria-invalid="Boolean(form.errors.destination)"
                        @update:model-value="onDestinationType"
                        @select="onPlaceSelect"
                    />
                    <InputError :message="form.errors.destination" />
                </div>
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div class="space-y-1.5">
                        <Label for="add-departure">Departure</Label>
                        <div class="relative">
                            <Input
                                id="add-departure"
                                v-model="form.departure_date"
                                type="date"
                                class="pr-10"
                                :min="todayEt"
                                placeholder="mm/dd/yyyy"
                                :data-empty="
                                    form.departure_date ? 'false' : 'true'
                                "
                                :aria-invalid="
                                    Boolean(form.errors.departure_date)
                                "
                                aria-describedby="add-departure-error"
                            />
                            <Calendar
                                class="pointer-events-none absolute top-1/2 right-3 size-4 -translate-y-1/2 text-ink-secondary"
                                aria-hidden="true"
                            />
                        </div>
                        <InputError
                            id="add-departure-error"
                            :message="form.errors.departure_date"
                        />
                    </div>
                    <div class="space-y-1.5">
                        <Label for="add-return">Return</Label>
                        <div class="relative">
                            <Input
                                id="add-return"
                                v-model="form.return_date"
                                type="date"
                                class="pr-10"
                                :min="form.departure_date || todayEt"
                                placeholder="mm/dd/yyyy"
                                :data-empty="
                                    form.return_date ? 'false' : 'true'
                                "
                                :aria-invalid="Boolean(form.errors.return_date)"
                                aria-describedby="add-return-error"
                            />
                            <Calendar
                                class="pointer-events-none absolute top-1/2 right-3 size-4 -translate-y-1/2 text-ink-secondary"
                                aria-hidden="true"
                            />
                        </div>
                        <InputError
                            id="add-return-error"
                            :message="form.errors.return_date"
                        />
                    </div>
                </div>
                <div class="flex items-center gap-2">
                    <Button type="submit" :disabled="form.processing"
                        >Add trip</Button
                    >
                    <Button
                        type="button"
                        variant="ghost"
                        @click="showAddPanel = false"
                    >
                        Cancel
                    </Button>
                </div>
            </form>
        </section>

        <!-- Empty state -->
        <section
            v-if="upcoming.length === 0 && past.length === 0 && !showAddPanel"
            class="flex flex-col items-center gap-4 rounded-md border border-hairline bg-surface-raised px-6 py-16 text-center"
        >
            <p class="text-subtitle text-ink">No trips yet — add your first.</p>
            <Button @click="openAddPanel">Add a trip</Button>
        </section>

        <!-- Upcoming -->
        <section v-if="upcoming.length > 0" class="space-y-3">
            <h2
                class="text-meta font-medium tracking-wide text-ink-secondary uppercase"
            >
                Upcoming
            </h2>
            <ul class="space-y-3">
                <li
                    v-for="trip in upcoming"
                    :key="trip.id"
                    class="flex flex-col gap-3 rounded-md border border-hairline bg-surface-raised p-5 sm:flex-row sm:items-center sm:justify-between"
                >
                    <div class="space-y-1">
                        <div class="flex items-center gap-2">
                            <span class="text-subtitle text-ink">{{
                                trip.destination
                            }}</span>
                            <Badge :class="pill[trip.status].class">
                                {{ pill[trip.status].label }}
                            </Badge>
                        </div>
                        <p class="text-meta text-ink-secondary">
                            {{ dateRange(trip) }} ·
                            {{ countdown(trip.days_until_departure) }}
                        </p>
                        <!-- Next-send line, with the sending beacon riding alongside it.
                             nextSendLine() always returns a non-null string when is_sending
                             is true, so the beacon never renders on a hidden line. -->
                        <p
                            v-if="nextSendLine(trip)"
                            class="flex items-center gap-1.5 text-meta"
                            :class="
                                trip.is_sending
                                    ? 'text-positive'
                                    : 'text-ink-secondary'
                            "
                        >
                            <!-- Beacon: this trip is sending at the next 9am (Spec B) -->
                            <span
                                v-if="trip.is_sending"
                                class="relative flex h-2 w-2"
                                aria-hidden="true"
                            >
                                <span
                                    class="absolute inline-flex h-full w-full animate-ping rounded-full bg-positive opacity-75"
                                />
                                <span
                                    class="relative inline-flex h-2 w-2 rounded-full bg-positive"
                                />
                            </span>
                            {{ nextSendLine(trip) }}
                        </p>
                        <p
                            v-if="trip.status === 'paused'"
                            class="text-meta text-ink-secondary"
                        >
                            Paused — no emails until you resume.
                        </p>
                    </div>

                    <div class="flex shrink-0 items-center gap-2">
                        <Button
                            v-if="trip.status === 'active'"
                            variant="outline"
                            size="sm"
                            @click="pauseTrip(trip)"
                        >
                            Pause
                        </Button>
                        <Button
                            v-else-if="trip.status === 'paused'"
                            variant="outline"
                            size="sm"
                            @click="resumeTrip(trip)"
                        >
                            Resume
                        </Button>
                        <Button
                            variant="ghost"
                            size="sm"
                            class="text-ink-secondary hover:text-destructive"
                            @click="deleteTarget = trip"
                        >
                            Delete
                        </Button>
                    </div>
                </li>
            </ul>
        </section>

        <!-- Past / completed (read-only) -->
        <section v-if="past.length > 0" class="space-y-3">
            <h2
                class="text-meta font-medium tracking-wide text-ink-secondary uppercase"
            >
                Past trips
            </h2>
            <ul class="space-y-3">
                <li
                    v-for="trip in past"
                    :key="trip.id"
                    class="flex items-center justify-between gap-3 rounded-md border border-hairline bg-surface-wash p-5"
                >
                    <div class="space-y-1">
                        <span class="text-subtitle text-ink-secondary">{{
                            trip.destination
                        }}</span>
                        <p class="text-meta text-ink-secondary">
                            {{ dateRange(trip) }}
                        </p>
                    </div>
                    <Badge :class="pill[trip.status].class">{{
                        pill[trip.status].label
                    }}</Badge>
                </li>
            </ul>
        </section>

        <!-- Feedback card (Story 10.1): always visible, between the trip lists
             and the sample card -->
        <section
            class="rounded-md border border-hairline bg-surface-raised p-5"
        >
            <FeedbackForm source="dashboard" />
        </section>

        <!-- "Send a sample" card: always visible, below the trip lists -->
        <section
            class="space-y-2 rounded-md border border-hairline bg-surface-raised p-5"
        >
            <h2 class="text-subtitle text-ink">Want to see one now?</h2>
            <p class="text-body text-ink-secondary">
                We'll email you a sample tripcast for Reykjavik, Iceland so you
                can get a preview of what your trips will look like.
            </p>
            <Button
                variant="outline"
                size="sm"
                class="mt-2"
                :disabled="sampleSending || sampleSent"
                @click="sendSample"
            >
                {{
                    sampleSent ? 'Sent — check your inbox' : 'Send me a sample'
                }}
            </Button>
        </section>
    </main>

    <!-- One calm delete confirmation (UX-DR15) -->
    <Dialog
        :open="deleteTarget !== null"
        @update:open="
            (open: boolean) => {
                if (!open) deleteTarget = null;
            }
        "
    >
        <DialogContent>
            <DialogHeader>
                <DialogTitle>Remove this trip?</DialogTitle>
                <DialogDescription>
                    Remove {{ deleteTarget?.destination }} from your trips? This
                    can't be undone.
                </DialogDescription>
            </DialogHeader>
            <DialogFooter class="gap-2">
                <Button variant="ghost" @click="deleteTarget = null"
                    >Keep it.</Button
                >
                <Button variant="destructive" @click="confirmDelete"
                    >Remove trip</Button
                >
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
