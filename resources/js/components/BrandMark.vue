<script lang="ts">
// Module scope: survives Inertia navigations, resets on a hard page load.
// Only ever touched on the client — on the SSR server this module persists
// across requests, so reading/writing it there would suppress the draw-in
// for every visitor after the first.
let hasPlayedDrawIn = false;
</script>

<script setup lang="ts">
type Props = {
    animate?: boolean;
};

const props = defineProps<Props>();

// Decided once, synchronously, at setup so server and hydrating client agree:
// SSR always renders the animating state (a hard load is exactly when the
// draw-in should play); the client claims the flag during hydration, so a
// layout remounted by an Inertia navigation renders fully drawn instead.
const shouldAnimate = (() => {
    if (!props.animate) {
        return false;
    }

    if (import.meta.env.SSR) {
        return true;
    }

    if (hasPlayedDrawIn) {
        return false;
    }

    hasPlayedDrawIn = true;

    return true;
})();
</script>

<template>
    <svg
        xmlns="http://www.w3.org/2000/svg"
        viewBox="0 0 64 64"
        fill="none"
        stroke-linecap="round"
        stroke-linejoin="round"
        aria-hidden="true"
        class="text-brand"
        :class="{ 'brand-draw': shouldAnimate }"
    >
        <circle
            class="mark-sun"
            cx="32"
            cy="32"
            r="11"
            stroke="#E0993D"
            stroke-width="3.4"
            pathLength="1"
        />
        <path
            class="mark-ray mark-ray-1"
            d="M32 10 V16"
            stroke="#E0993D"
            stroke-width="3.4"
        />
        <path
            class="mark-ray mark-ray-2"
            d="M16 16 L20 20"
            stroke="#E0993D"
            stroke-width="3.4"
        />
        <path
            class="mark-ray mark-ray-3"
            d="M48 16 L44 20"
            stroke="#E0993D"
            stroke-width="3.4"
        />
        <path
            class="mark-ray mark-ray-4"
            d="M9 32 H13"
            stroke="#E0993D"
            stroke-width="3.4"
        />
        <path
            class="mark-ray mark-ray-5"
            d="M51 32 H55"
            stroke="#E0993D"
            stroke-width="3.4"
        />
        <path
            class="mark-wave"
            d="M8 49 q8 -5 16 0 t16 0 t16 0"
            stroke="currentColor"
            stroke-width="3.4"
            pathLength="1"
        />
    </svg>
</template>

<style scoped>
/* Keyframes animate FROM hidden TO the element's natural state, so the
   static render (no .brand-draw) and the post-animation state are identical:
   fully drawn. `backwards` fill holds the hidden state through each delay. */
@keyframes draw-stroke {
    from {
        stroke-dashoffset: 1;
    }
    to {
        stroke-dashoffset: 0;
    }
}

@keyframes ray-pop {
    from {
        opacity: 0;
        transform: scale(0.6);
    }
    to {
        opacity: 1;
        transform: scale(1);
    }
}

.brand-draw .mark-wave {
    stroke-dasharray: 1;
    animation: draw-stroke 500ms ease-out backwards;
}

.brand-draw .mark-sun {
    stroke-dasharray: 1;
    animation: draw-stroke 400ms ease-out 150ms backwards;
}

.brand-draw .mark-ray {
    transform-box: fill-box;
    transform-origin: center;
    animation: ray-pop 300ms ease-out backwards;
}

.brand-draw .mark-ray-1 {
    animation-delay: 480ms;
}
.brand-draw .mark-ray-2 {
    animation-delay: 530ms;
}
.brand-draw .mark-ray-3 {
    animation-delay: 580ms;
}
.brand-draw .mark-ray-4 {
    animation-delay: 630ms;
}
.brand-draw .mark-ray-5 {
    animation-delay: 680ms;
}

@media (prefers-reduced-motion: reduce) {
    .brand-draw .mark-wave,
    .brand-draw .mark-sun,
    .brand-draw .mark-ray {
        animation: none;
    }
}
</style>
