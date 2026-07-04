<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { send as sendTripDigest } from '@/routes/admin/trips/digest';

type TripStatus = 'active' | 'paused' | 'completed';
type SendStatus = 'sending' | 'sent' | 'failed';

interface EmailLogRow {
    send_date: string;
    status: SendStatus;
    failure_reason: string | null;
}

interface AdminSendRow {
    recipient: 'owner' | 'admin';
    status: SendStatus;
    created_at: string | null;
}

interface AdminTrip {
    id: number;
    owner: string;
    destination_raw: string;
    canonical_place_name: string;
    departure_date: string;
    return_date: string;
    status: TripStatus;
    owner_confirmed: boolean;
    owner_opted_out: boolean;
    latestSnapshot: { send_date: string; status: SendStatus } | null;
    emailLogs: EmailLogRow[];
    adminSends: AdminSendRow[];
}

defineProps<{ trips: AdminTrip[] }>();

const statusPill: Record<TripStatus, string> = {
    active: 'border-transparent bg-surface-wash text-brand',
    paused: 'border-hairline bg-transparent text-ink-secondary',
    completed: 'border-transparent bg-surface-wash text-ink-secondary',
};

const sendPill: Record<SendStatus, string> = {
    sent: 'text-positive',
    failed: 'text-destructive',
    sending: 'text-ink-secondary',
};

const page = usePage();
const flashStatus = computed(() => page.props.flash?.status as string | null);
const flashError = computed(() => page.props.flash?.error as string | null);
const sending = ref<number | null>(null);

function post(trip: AdminTrip, recipient: 'owner' | 'admin') {
    sending.value = trip.id;
    router.post(
        sendTripDigest(trip.id).url,
        { recipient },
        {
            preserveScroll: true,
            onFinish: () => {
                sending.value = null;
            },
        },
    );
}

function sendToMe(trip: AdminTrip) {
    post(trip, 'admin');
}

function sendToOwner(trip: AdminTrip) {
    if (window.confirm(`Send a real digest to ${trip.owner}?`)) {
        post(trip, 'owner');
    }
}

function ownerReason(trip: AdminTrip): string | null {
    if (!trip.owner_confirmed) {
        return 'Owner has not confirmed their email';
    }

    if (trip.owner_opted_out) {
        return 'Owner has opted out of all email';
    }

    return null;
}
</script>

<template>
    <Head title="Admin — monitoring" />

    <main class="mx-auto flex max-w-5xl flex-col gap-6 px-6 py-12">
        <div class="space-y-1">
            <h1 class="text-title text-ink">Monitoring</h1>
            <p class="text-body text-ink-secondary">
                Every trip and send across all users. Read-only.
            </p>
        </div>

        <p
            v-if="flashStatus"
            class="rounded-md border border-transparent bg-surface-wash p-3 text-body text-positive"
        >
            {{ flashStatus }}
        </p>
        <p
            v-if="flashError"
            class="rounded-md border border-transparent bg-surface-wash p-3 text-body text-destructive"
        >
            {{ flashError }}
        </p>

        <p
            v-if="trips.length === 0"
            class="rounded-md border border-hairline bg-surface-raised p-6 text-body text-ink-secondary"
        >
            No trips yet.
        </p>

        <section
            v-for="trip in trips"
            :key="trip.id"
            class="space-y-3 rounded-md border border-hairline bg-surface-raised p-5"
        >
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="space-y-1">
                    <div class="flex items-center gap-2">
                        <span class="text-subtitle text-ink">{{
                            trip.canonical_place_name
                        }}</span>
                        <Badge :class="statusPill[trip.status]">{{
                            trip.status
                        }}</Badge>
                    </div>
                    <p class="text-meta text-ink-secondary">
                        “{{ trip.destination_raw }}” ·
                        {{ trip.departure_date }} →
                        {{ trip.return_date }}
                    </p>
                    <p class="text-meta text-ink-secondary">
                        Owner: {{ trip.owner }}
                    </p>
                </div>
                <p class="text-meta text-ink-secondary">
                    <template v-if="trip.latestSnapshot">
                        Last send: {{ trip.latestSnapshot.send_date }}
                        <span :class="sendPill[trip.latestSnapshot.status]">
                            ({{ trip.latestSnapshot.status }})
                        </span>
                    </template>
                    <template v-else>No sends yet</template>
                </p>
            </div>

            <div class="flex items-center gap-2">
                <div class="flex items-stretch">
                    <Button
                        variant="outline"
                        class="rounded-r-none"
                        :disabled="sending === trip.id"
                        @click="sendToMe(trip)"
                    >
                        Send to me
                    </Button>
                    <DropdownMenu>
                        <DropdownMenuTrigger as-child>
                            <Button
                                variant="outline"
                                class="rounded-l-none border-l-0 px-2"
                                :disabled="sending === trip.id"
                                aria-label="More send options"
                            >
                                ▾
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end">
                            <DropdownMenuItem
                                :disabled="ownerReason(trip) !== null"
                                @select="sendToOwner(trip)"
                            >
                                Send to owner
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>
                </div>
                <span
                    v-if="ownerReason(trip)"
                    class="text-meta text-ink-secondary"
                >
                    {{ ownerReason(trip) }}
                </span>
            </div>

            <table v-if="trip.emailLogs.length > 0" class="w-full text-meta">
                <thead>
                    <tr
                        class="border-b border-hairline text-left text-ink-secondary"
                    >
                        <th class="py-1 pr-4 font-medium">Send date</th>
                        <th class="py-1 pr-4 font-medium">Status</th>
                        <th class="py-1 font-medium">Reason</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="(log, index) in trip.emailLogs"
                        :key="index"
                        class="border-b border-hairline/60 last:border-0"
                    >
                        <td class="py-1 pr-4 text-ink">{{ log.send_date }}</td>
                        <td class="py-1 pr-4" :class="sendPill[log.status]">
                            {{ log.status }}
                        </td>
                        <td class="py-1 text-ink-secondary">
                            {{ log.failure_reason ?? '—' }}
                        </td>
                    </tr>
                </tbody>
            </table>
            <p v-else class="text-meta text-ink-secondary">No sends yet.</p>

            <p
                v-if="trip.adminSends.length > 0"
                class="text-meta text-ink-secondary"
            >
                Admin sends:
                <span
                    v-for="(s, i) in trip.adminSends"
                    :key="i"
                    :class="sendPill[s.status]"
                >
                    {{ s.recipient }} ({{ s.status }}){{
                        i < trip.adminSends.length - 1 ? ', ' : ''
                    }}
                </span>
            </p>
        </section>
    </main>
</template>
