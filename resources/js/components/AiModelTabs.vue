<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { Brain, Zap } from 'lucide-vue-next';
import type { LucideIcon } from 'lucide-vue-next';
import { computed } from 'vue';

interface AiModelOption {
    value: string;
    label: string;
    description: string;
    icon: string;
}

const props = defineProps<{
    currentModel: string;
    availableModels: AiModelOption[];
}>();

const iconMap: Record<string, LucideIcon> = {
    brain: Brain,
    zap: Zap,
};

const currentDescription = computed(
    () =>
        props.availableModels.find((m) => m.value === props.currentModel)
            ?.description,
);

function getIcon(icon: string): LucideIcon {
    return iconMap[icon] ?? Brain;
}

function updateModel(value: string) {
    router.patch(
        '/settings/ai-model',
        { preferred_ai_model: value },
        {
            preserveScroll: true,
        },
    );
}
</script>

<template>
    <div class="space-y-3">
        <div
            class="inline-flex gap-1 rounded-lg bg-neutral-100 p-1 dark:bg-neutral-800"
        >
            <button
                v-for="model in availableModels"
                :key="model.value"
                @click="updateModel(model.value)"
                :class="[
                    'flex items-center rounded-md px-3.5 py-1.5 transition-colors',
                    currentModel === model.value
                        ? 'bg-white shadow-xs dark:bg-neutral-700 dark:text-neutral-100'
                        : 'text-neutral-500 hover:bg-neutral-200/60 hover:text-black dark:text-neutral-400 dark:hover:bg-neutral-700/60',
                ]"
            >
                <component :is="getIcon(model.icon)" class="-ml-1 h-4 w-4" />
                <span class="ml-1.5 text-sm">{{ model.label }}</span>
            </button>
        </div>
        <p class="text-xs text-muted-foreground">
            {{ currentDescription }}
        </p>
    </div>
</template>
