<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { store } from '@/routes/trip-setup';

const form = useForm({
    destination: '',
    departure_date: '',
    return_date: '',
});

const submit = () => form.submit(store());
</script>

<template>
    <Head title="tripcast — the weather app you never have to open" />

    <main
        class="flex min-h-svh flex-col items-center justify-center bg-surface-wash px-4 py-12 md:py-20"
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
                            :aria-invalid="Boolean(form.errors.departure_date)"
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
        </div>
    </main>
</template>
