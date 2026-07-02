import { watchDebounced } from '@vueuse/core';
import { ref } from 'vue';
import type { Ref } from 'vue';
import { suggest } from '@/routes/places';

export interface PlaceSuggestionItem {
    place_id: string;
    label: string;
}

/**
 * As-you-type destination suggestions (FR-22) through the server proxy.
 *
 * Failure handling is silence: any non-OK response, network error, or abort
 * leaves the field a plain free-text input — suggestions are an overlay,
 * never a constraint. The session token groups keystrokes for per-session
 * billing; it is submitted with the form (the server's Place Details call
 * terminates the session) and reset for the next typing session.
 */
export function useDestinationAutocomplete(query: Ref<string>) {
    const suggestions = ref<PlaceSuggestionItem[]>([]);
    const sessionToken = ref<string | null>(null);
    let controller: AbortController | null = null;
    let suppressedValue: string | null = null;

    function token(): string {
        sessionToken.value ??= crypto.randomUUID();

        return sessionToken.value;
    }

    function resetSession(): void {
        sessionToken.value = null;
    }

    function clear(): void {
        controller?.abort();
        suggestions.value = [];
    }

    /**
     * Skip the next search for {@link value}. Picking a suggestion writes the
     * chosen label back into the field, which would otherwise re-trigger the
     * debounced search and reopen the dropdown over the input (and burn a
     * Places call). Suppressing that one echo keeps a selection final.
     */
    function suppressSearchFor(value: string): void {
        suppressedValue = value;
    }

    watchDebounced(
        query,
        async (value) => {
            const trimmed = value.trim();

            if (suppressedValue !== null) {
                const suppressed = suppressedValue.trim();
                suppressedValue = null;

                if (trimmed === suppressed) {
                    return;
                }
            }

            if (trimmed.length < 2) {
                clear();

                return;
            }

            controller?.abort();
            controller = new AbortController();

            try {
                const response = await fetch(
                    suggest.url({ query: { q: trimmed, token: token() } }),
                    {
                        signal: controller.signal,
                        headers: { Accept: 'application/json' },
                    },
                );

                if (!response.ok) {
                    suggestions.value = [];

                    return;
                }

                const data = (await response.json()) as {
                    suggestions?: PlaceSuggestionItem[];
                };
                suggestions.value = data.suggestions ?? [];
            } catch {
                // Aborted or failed — degrade silently to plain text (FR-22).
                suggestions.value = [];
            }
        },
        { debounce: 250 },
    );

    return {
        suggestions,
        sessionToken,
        clear,
        resetSession,
        suppressSearchFor,
    };
}
