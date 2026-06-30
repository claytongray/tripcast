<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import { toast } from 'vue-sonner';
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
import { destroy, pause, resume, store } from '@/routes/trips';

type TripStatus = 'active' | 'paused' | 'completed';

interface TripCard {
    id: number;
    destination: string;
    departure_date: string;
    return_date: string;
    status: TripStatus;
    days_until_departure: number;
}

const props = defineProps<{
    upcomingTrips: TripCard[];
    pastTrips: TripCard[];
}>();

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
    active: { label: 'Active', class: 'border-transparent bg-surface-wash text-brand' },
    paused: { label: 'Paused', class: 'border-hairline bg-transparent text-ink-secondary' },
    completed: { label: 'Completed', class: 'border-transparent bg-surface-wash text-ink-secondary' },
};

const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

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
    setStatus(trip, 'paused', pause(trip.id), "Couldn't pause that trip. Please try again.");
}

function resumeTrip(trip: TripCard): void {
    setStatus(trip, 'active', resume(trip.id), "Couldn't resume that trip. Please try again.");
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
const form = useForm({ destination: '', departure_date: '', return_date: '' });

function openAddPanel(): void {
    form.clearErrors();
    showAddPanel.value = true;
}

function submitAdd(): void {
    form.submit(store());
}
</script>

<template>
    <Head title="Your trips" />

    <main class="mx-auto flex max-w-3xl flex-col gap-8 px-6 py-12">
        <div class="flex items-start justify-between gap-4">
            <div class="space-y-1">
                <h1 class="text-title text-ink">Your trips</h1>
                <p class="text-body text-ink-secondary">
                    Tripcast watches these for you and emails a forecast each morning.
                </p>
            </div>
            <Button v-if="!showAddPanel" variant="outline" size="sm" @click="openAddPanel">
                Add a trip
            </Button>
        </div>

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
            <form class="space-y-4" @submit.prevent="submitAdd">
                <div class="space-y-1.5">
                    <Label for="add-destination">Destination</Label>
                    <Input
                        id="add-destination"
                        v-model="form.destination"
                        type="text"
                        placeholder="Edinburgh, UK"
                        autocomplete="off"
                    />
                    <InputError :message="form.errors.destination" />
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="space-y-1.5">
                        <Label for="add-departure">Departure</Label>
                        <Input id="add-departure" v-model="form.departure_date" type="date" />
                        <InputError :message="form.errors.departure_date" />
                    </div>
                    <div class="space-y-1.5">
                        <Label for="add-return">Return</Label>
                        <Input id="add-return" v-model="form.return_date" type="date" />
                        <InputError :message="form.errors.return_date" />
                    </div>
                </div>
                <div class="flex items-center gap-2">
                    <Button type="submit" :disabled="form.processing">Add trip</Button>
                    <Button type="button" variant="ghost" @click="showAddPanel = false">
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
            <h2 class="text-meta font-medium tracking-wide text-ink-secondary uppercase">
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
                            <span class="text-subtitle text-ink">{{ trip.destination }}</span>
                            <Badge :class="pill[trip.status].class">
                                {{ pill[trip.status].label }}
                            </Badge>
                        </div>
                        <p class="text-meta text-ink-secondary">
                            {{ dateRange(trip) }} · {{ countdown(trip.days_until_departure) }}
                        </p>
                        <p v-if="trip.status === 'paused'" class="text-meta text-ink-secondary">
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
            <h2 class="text-meta font-medium tracking-wide text-ink-secondary uppercase">
                Past trips
            </h2>
            <ul class="space-y-3">
                <li
                    v-for="trip in past"
                    :key="trip.id"
                    class="flex items-center justify-between gap-3 rounded-md border border-hairline bg-surface-wash p-5"
                >
                    <div class="space-y-1">
                        <span class="text-subtitle text-ink-secondary">{{ trip.destination }}</span>
                        <p class="text-meta text-ink-secondary">{{ dateRange(trip) }}</p>
                    </div>
                    <Badge :class="pill[trip.status].class">{{ pill[trip.status].label }}</Badge>
                </li>
            </ul>
        </section>
    </main>

    <!-- One calm delete confirmation (UX-DR15) -->
    <Dialog
        :open="deleteTarget !== null"
        @update:open="(open: boolean) => { if (!open) deleteTarget = null; }"
    >
        <DialogContent>
            <DialogHeader>
                <DialogTitle>Remove this trip?</DialogTitle>
                <DialogDescription>
                    Stop watching {{ deleteTarget?.destination }} and remove it? This can't be
                    undone.
                </DialogDescription>
            </DialogHeader>
            <DialogFooter class="gap-2">
                <Button variant="ghost" @click="deleteTarget = null">Keep it.</Button>
                <Button variant="destructive" @click="confirmDelete">Remove trip</Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
