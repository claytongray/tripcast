<script setup lang="ts">
import {
    ComboboxAnchor,
    ComboboxContent,
    ComboboxInput,
    ComboboxItem,
    ComboboxRoot,
    ComboboxViewport,
} from 'reka-ui';
import { ref, watch } from 'vue';
import { useDestinationAutocomplete } from '@/composables/useDestinationAutocomplete';
import type { PlaceSuggestionItem } from '@/composables/useDestinationAutocomplete';
import { cn } from '@/lib/utils';

// Accessible destination autocomplete (FR-22): reka-ui provides the WAI-ARIA
// combobox semantics (role, aria-expanded, listbox, aria-activedescendant,
// arrow/Enter/Escape). Suggestions are an overlay, never a constraint — with
// zero suggestions this behaves exactly like the plain Input it replaces.
//
// Event contract (stale-id guard): `update:modelValue` fires ONLY for user
// typing; a picked suggestion fires ONLY `select`. The parent clears its
// place_id on typing and sets text+id together on select — the two signals
// never overlap, so a selection can't clobber its own id.
const props = defineProps<{
    modelValue: string;
    id: string;
    name?: string;
    placeholder?: string;
    ariaInvalid?: boolean;
    ariaDescribedby?: string;
}>();

const emit = defineEmits<{
    'update:modelValue': [value: string];
    select: [suggestion: PlaceSuggestionItem, sessionToken: string | null];
}>();

const query = ref(props.modelValue);
watch(
    () => props.modelValue,
    (value) => (query.value = value),
);

const { suggestions, sessionToken, clear, suppressSearchFor } =
    useDestinationAutocomplete(query);

const open = ref(false);
watch(suggestions, (list) => (open.value = list.length > 0));

function onType(value: string): void {
    emit('update:modelValue', value);
}

function onSelect(suggestion: PlaceSuggestionItem | null | undefined): void {
    if (!suggestion) {
        return;
    }

    // The parent echoes the chosen label back into modelValue; suppress the
    // search it would trigger so the dropdown stays closed after selection.
    suppressSearchFor(suggestion.label);
    clear();
    open.value = false;
    emit('select', suggestion, sessionToken.value);
}
</script>

<template>
    <ComboboxRoot
        :ignore-filter="true"
        :reset-search-term-on-blur="false"
        :reset-search-term-on-select="false"
        :open="open"
        @update:open="(value: boolean) => (open = value)"
        @update:model-value="
            (value) => onSelect(value as PlaceSuggestionItem | null)
        "
    >
        <ComboboxAnchor class="relative w-full">
            <ComboboxInput
                :id="id"
                :model-value="modelValue"
                type="text"
                :name="name"
                autocomplete="off"
                :placeholder="placeholder"
                :aria-invalid="ariaInvalid"
                :aria-describedby="ariaDescribedby"
                :class="
                    cn(
                        'h-11 w-full min-w-0 rounded-sm border border-input bg-card px-3 py-2 text-base transition-[color,box-shadow] outline-none selection:bg-primary selection:text-primary-foreground placeholder:text-muted-foreground',
                        'focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background',
                        'aria-invalid:border-destructive',
                    )
                "
                @update:model-value="(value) => onType(String(value))"
            />
            <ComboboxContent
                v-if="suggestions.length > 0"
                class="absolute top-full right-0 left-0 z-20 mt-1 overflow-hidden rounded-md border border-hairline bg-surface-raised shadow-md"
            >
                <ComboboxViewport class="max-h-64 p-1">
                    <ComboboxItem
                        v-for="suggestion in suggestions"
                        :key="suggestion.place_id"
                        :value="suggestion"
                        class="cursor-pointer rounded-sm px-3 py-2.5 text-body text-ink data-highlighted:bg-surface-wash data-highlighted:outline-none"
                    >
                        {{ suggestion.label }}
                    </ComboboxItem>
                </ComboboxViewport>
            </ComboboxContent>
        </ComboboxAnchor>
    </ComboboxRoot>
</template>
