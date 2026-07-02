<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { toast } from 'vue-sonner';
import { Button } from '@/components/ui/button';
import { logout } from '@/routes';
import { update as updateSettings } from '@/routes/settings';

type TemperatureUnit = 'fahrenheit' | 'celsius';

const props = defineProps<{
    email: string;
    temperatureUnit: TemperatureUnit;
}>();

// Local copy drives the optimistic toggle; the server prop is the source of truth.
const unit = ref<TemperatureUnit>(props.temperatureUnit);

const units: { value: TemperatureUnit; label: string }[] = [
    { value: 'fahrenheit', label: 'Fahrenheit' },
    { value: 'celsius', label: 'Celsius' },
];

// Auto-save: flip optimistically, persist, revert on failure.
function selectUnit(next: TemperatureUnit): void {
    if (next === unit.value) {
        return;
    }

    const previous = unit.value;
    unit.value = next;

    router.patch(
        updateSettings().url,
        { temperature_unit: next },
        {
            preserveScroll: true,
            onSuccess: () => toast.success('Your preferences are saved.'),
            onError: () => {
                unit.value = previous;
                toast.error("Couldn't save that. Please try again.");
            },
        },
    );
}
</script>

<template>
    <Head title="Settings" />

    <main class="mx-auto flex max-w-2xl flex-col gap-8 px-6 py-12">
        <div class="space-y-1">
            <h1 class="text-title text-ink">Settings</h1>
            <p class="text-body text-ink-secondary">
                Manage your account and preferences.
            </p>
        </div>

        <!-- Account -->
        <section
            class="space-y-4 rounded-md border border-hairline bg-surface-raised p-5"
        >
            <h2 class="text-subtitle text-ink">Account</h2>
            <div class="space-y-1">
                <p
                    class="text-meta font-medium tracking-wide text-ink-secondary uppercase"
                >
                    Email
                </p>
                <p class="text-body text-ink">{{ email }}</p>
                <p class="text-meta text-ink-secondary">
                    Your email can't be changed yet.
                </p>
            </div>
        </section>

        <!-- Preferences -->
        <section
            class="space-y-4 rounded-md border border-hairline bg-surface-raised p-5"
        >
            <h2 class="text-subtitle text-ink">Preferences</h2>
            <div class="space-y-2">
                <p
                    class="text-meta font-medium tracking-wide text-ink-secondary uppercase"
                >
                    Temperature unit
                </p>
                <div
                    class="inline-flex rounded-md border border-hairline p-0.5"
                    role="group"
                    aria-label="Temperature unit"
                >
                    <button
                        v-for="option in units"
                        :key="option.value"
                        type="button"
                        :aria-pressed="unit === option.value"
                        class="rounded-sm px-4 py-1.5 text-body font-medium transition-colors focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                        :class="
                            unit === option.value
                                ? 'bg-surface-wash text-brand'
                                : 'text-ink-secondary hover:text-ink'
                        "
                        @click="selectUnit(option.value)"
                    >
                        {{ option.label }}
                    </button>
                </div>
                <p class="text-meta text-ink-secondary">
                    The unit your daily forecast emails use.
                </p>
            </div>
        </section>

        <!-- Log out -->
        <section
            class="space-y-4 rounded-md border border-hairline bg-surface-raised p-5"
        >
            <h2 class="text-subtitle text-ink">Log out</h2>
            <p class="text-meta text-ink-secondary">
                Sign out of tripcast on this device.
            </p>
            <Button as-child variant="outline" size="sm">
                <Link :href="logout()" method="post" as="button" type="button"
                    >Log out</Link
                >
            </Button>
        </section>
    </main>
</template>
