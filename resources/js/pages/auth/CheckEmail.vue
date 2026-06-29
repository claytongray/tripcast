<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { store } from '@/routes/login';

const props = defineProps<{
    email: string;
    ttlMinutes: number;
}>();

const form = useForm({ email: props.email });

const resend = () => form.submit(store());
</script>

<template>
    <Head title="Check your email" />

    <div class="flex flex-col gap-6 text-center">
        <div class="space-y-2">
            <h1 class="text-xl font-medium">Check your inbox</h1>
            <p class="text-sm text-muted-foreground">
                We sent a link to
                <span class="font-medium text-foreground">{{ email }}</span>. It
                expires in {{ ttlMinutes }} minutes.
            </p>
        </div>

        <form @submit.prevent="resend">
            <Button type="submit" variant="outline" :disabled="form.processing">
                Resend link
            </Button>
        </form>
    </div>
</template>
