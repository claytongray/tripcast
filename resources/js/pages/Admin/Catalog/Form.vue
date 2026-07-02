<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { index, store, update } from '@/routes/admin/promo-items';

type PromoItemRow = {
    id: number;
    slug: string;
    label: string;
    image_url: string;
    url: string;
    merchant: string;
    weather_profile: string;
    is_active: boolean;
    featured_from: string | null;
    featured_to: string | null;
    sort_order: number;
};

const props = defineProps<{
    item: PromoItemRow | null;
    slugLocked: boolean;
    profiles: string[];
    merchants: string[];
}>();

const isEdit = props.item !== null;

const form = useForm({
    slug: props.item?.slug ?? '',
    label: props.item?.label ?? '',
    image_url: props.item?.image_url ?? '',
    url: props.item?.url ?? '',
    merchant: props.item?.merchant ?? props.merchants[0] ?? 'amazon',
    weather_profile: props.item?.weather_profile ?? props.profiles[0] ?? '',
    is_active: props.item?.is_active ?? true,
    sort_order: props.item?.sort_order ?? 0,
    featured_from: props.item?.featured_from ?? '',
    featured_to: props.item?.featured_to ?? '',
});

function submit(): void {
    form.clearErrors();

    if (isEdit && props.item) {
        form.put(update(props.item.id).url);

        return;
    }

    form.post(store().url);
}
</script>

<template>
    <Head :title="isEdit ? 'Admin — edit item' : 'Admin — new item'" />

    <main class="mx-auto flex max-w-2xl flex-col gap-8 px-6 py-12">
        <div class="space-y-1">
            <h1 class="text-title text-ink">{{ isEdit ? 'Edit item' : 'New item' }}</h1>
            <p class="text-body text-ink-secondary">
                A sponsored product served in the digest promo slot.
            </p>
        </div>

        <form class="space-y-6" @submit.prevent="submit">
            <div class="space-y-2">
                <Label for="slug">Slug</Label>
                <Input id="slug" v-model="form.slug" :disabled="slugLocked" autocomplete="off" />
                <p v-if="slugLocked" class="text-meta text-ink-secondary">
                    The slug is the permanent attribution key — it can't change once set.
                </p>
                <InputError :message="form.errors.slug" />
            </div>

            <div class="space-y-2">
                <Label for="label">Label</Label>
                <Input id="label" v-model="form.label" />
                <InputError :message="form.errors.label" />
            </div>

            <div class="space-y-2">
                <Label for="image_url">Image URL</Label>
                <Input id="image_url" v-model="form.image_url" type="url" placeholder="https://…" />
                <InputError :message="form.errors.image_url" />
            </div>

            <div class="space-y-2">
                <Label for="url">Product URL</Label>
                <Input id="url" v-model="form.url" type="url" placeholder="https://…" />
                <p class="text-meta text-ink-secondary">
                    Amazon links get the associate tag appended at send; other merchants are used
                    verbatim.
                </p>
                <InputError :message="form.errors.url" />
            </div>

            <div class="grid gap-6 sm:grid-cols-2">
                <div class="space-y-2">
                    <Label for="merchant">Merchant</Label>
                    <select
                        id="merchant"
                        v-model="form.merchant"
                        class="h-10 w-full rounded-sm border border-hairline bg-surface-raised px-3 text-body text-ink focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                    >
                        <option v-for="m in merchants" :key="m" :value="m">{{ m }}</option>
                    </select>
                    <InputError :message="form.errors.merchant" />
                </div>

                <div class="space-y-2">
                    <Label for="weather_profile">Weather profile</Label>
                    <select
                        id="weather_profile"
                        v-model="form.weather_profile"
                        class="h-10 w-full rounded-sm border border-hairline bg-surface-raised px-3 text-body text-ink focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                    >
                        <option v-for="p in profiles" :key="p" :value="p">{{ p }}</option>
                    </select>
                    <InputError :message="form.errors.weather_profile" />
                </div>
            </div>

            <div class="grid gap-6 sm:grid-cols-2">
                <div class="space-y-2">
                    <Label for="featured_from">Featured from</Label>
                    <Input id="featured_from" v-model="form.featured_from" type="date" />
                    <InputError :message="form.errors.featured_from" />
                </div>

                <div class="space-y-2">
                    <Label for="featured_to">Featured to</Label>
                    <Input id="featured_to" v-model="form.featured_to" type="date" />
                    <p class="text-meta text-ink-secondary">Leave blank to pin indefinitely.</p>
                    <InputError :message="form.errors.featured_to" />
                </div>
            </div>

            <div class="grid gap-6 sm:grid-cols-2">
                <div class="space-y-2">
                    <Label for="sort_order">Sort order</Label>
                    <Input id="sort_order" v-model="form.sort_order" type="number" min="0" />
                    <InputError :message="form.errors.sort_order" />
                </div>

                <div class="space-y-2">
                    <Label for="is_active">Status</Label>
                    <label class="flex h-10 items-center gap-2 text-body text-ink">
                        <input
                            id="is_active"
                            v-model="form.is_active"
                            type="checkbox"
                            class="size-4 rounded-sm border-hairline text-brand focus-visible:ring-2 focus-visible:ring-ring"
                        />
                        Active (shown in digests)
                    </label>
                    <InputError :message="form.errors.is_active" />
                </div>
            </div>

            <div class="flex items-center gap-3">
                <Button type="submit" :disabled="form.processing">
                    {{ isEdit ? 'Save item' : 'Add item' }}
                </Button>
                <Button as-child variant="ghost" type="button">
                    <Link :href="index().url">Cancel</Link>
                </Button>
            </div>
        </form>
    </main>
</template>
