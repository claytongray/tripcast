<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { home } from '@/routes';
import { store } from '@/routes/login';

const form = useForm({ email: '' });

const submit = () => form.submit(store());
</script>

<template>
    <Head title="Sign in" />

    <form novalidate class="flex flex-col gap-6" @submit.prevent="submit">
        <div class="space-y-2 text-center">
            <h1 class="text-title text-ink">Sign in to tripcast</h1>
            <p class="text-body text-ink-secondary">
                We'll email you a one-time link — no password.
            </p>
        </div>

        <div class="grid gap-2">
            <Label for="email">Email</Label>
            <Input
                id="email"
                v-model="form.email"
                type="email"
                name="email"
                required
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
            class="h-11 text-base"
            :disabled="form.processing"
        >
            Email me a link
        </Button>

        <div class="space-y-5 text-center text-body text-ink-secondary">
            <p>
                New to tripcast?
                <TextLink :href="home()"
                    >Create your first trip &rarr;</TextLink
                >
            </p>
            <p>
                <TextLink :href="home()">&larr; Back to home</TextLink>
            </p>
        </div>
    </form>
</template>
