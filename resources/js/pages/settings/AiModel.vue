<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import AiModelTabs from '@/components/AiModelTabs.vue';
import Heading from '@/components/Heading.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import type { BreadcrumbItem } from '@/types';

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

const breadcrumbItems: BreadcrumbItem[] = [
    {
        title: 'Modelo IA',
        href: '/settings/ai-model',
    },
];
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbItems">
        <Head title="Modelo IA" />

        <h1 class="sr-only">Modelo IA</h1>

        <SettingsLayout>
            <div class="space-y-6">
                <Heading
                    variant="small"
                    title="Modelo de IA"
                    description="Escolha o modelo de inteligência artificial usado pelo assistente"
                />
                <AiModelTabs :current-model="currentModel" :available-models="availableModels" />
            </div>
        </SettingsLayout>
    </AppLayout>
</template>
