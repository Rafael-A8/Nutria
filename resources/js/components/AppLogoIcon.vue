<script setup lang="ts">
import type { HTMLAttributes } from 'vue';

defineOptions({
    inheritAttrs: false,
});

type Props = {
    className?: HTMLAttributes['class'];
};

defineProps<Props>();
</script>

<template>
    <svg
        xmlns="http://www.w3.org/2000/svg"
        viewBox="0 0 32 32"
        :class="className"
        v-bind="$attrs"
    >
        <!-- Folha estilizada — Nutria -->
        <path
            fill="currentColor"
            d="M26 4C18 4 12 8 8 14c-3 5-3.5 11-2 16 .2.6.9.8 1.4.4C12 26 16 20 18 14c.3-.8.5-1.6.7-2.4C20.5 8 23 5.5 26 4.8c.7-.2 1.1-.9.8-1.5A1 1 0 0 0 26 4z"
        />
        <path
            fill="currentColor"
            opacity="0.6"
            d="M7.5 28.5c-.3 0-.5-.1-.7-.3-.3-.4-.2-.9.1-1.2C11 23.5 14 18 15.5 12c.1-.5.6-.8 1.1-.7.5.1.8.6.7 1.1C15.7 19 12.5 24.5 8.2 28.2c-.2.2-.5.3-.7.3z"
        />
    </svg>
</template>
