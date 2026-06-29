<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { store } from '@/routes/login';

const props = defineProps<{
    email: string | null;
}>();

const form = useForm({ email: props.email ?? '' });

const resend = () => form.submit(store());
</script>

<template>
    <Head title="Link expired" />

    <div class="flex flex-col gap-6 text-center">
        <div class="space-y-2">
            <h1 class="text-xl font-medium">That link expired</h1>
            <p class="text-sm text-muted-foreground">
                Want a fresh one? We'll send a new link.
            </p>
        </div>

        <form novalidate class="flex flex-col gap-4" @submit.prevent="resend">
            <div v-if="!props.email" class="grid gap-2 text-left">
                <Label for="email">Email</Label>
                <Input
                    id="email"
                    v-model="form.email"
                    type="email"
                    name="email"
                    required
                    autocomplete="email"
                    placeholder="you@example.com"
                    :aria-invalid="Boolean(form.errors.email)"
                    aria-describedby="email-error"
                />
                <InputError id="email-error" :message="form.errors.email" />
            </div>

            <Button
                type="submit"
                class="h-11 text-base"
                :disabled="form.processing"
            >
                Send a fresh link
            </Button>
        </form>
    </div>
</template>
