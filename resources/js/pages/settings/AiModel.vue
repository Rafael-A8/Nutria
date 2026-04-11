<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import AiModelTabs from '@/components/AiModelTabs.vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';
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
    customInstructions: string;
}>();

const breadcrumbItems: BreadcrumbItem[] = [
    {
        title: 'Modelo IA',
        href: '/settings/ai-model',
    },
];

const form = useForm({
    custom_instructions: props.customInstructions ?? '',
});

const saved = ref(false);
let saveTimeout: ReturnType<typeof setTimeout>;

function saveInstructions() {
    form.patch('/settings/ai-model/instructions', {
        preserveScroll: true,
        onSuccess: () => {
            saved.value = true;
            clearTimeout(saveTimeout);
            saveTimeout = setTimeout(() => (saved.value = false), 2500);
        },
    });
}

const maxChars = 1000;
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbItems">
        <Head title="Modelo IA" />

        <h1 class="sr-only">Modelo IA</h1>

        <SettingsLayout>
            <div class="space-y-8">
                <div class="space-y-6">
                    <Heading
                        variant="small"
                        title="Modelo de IA"
                        description="Escolha o modelo de inteligência artificial usado pelo assistente"
                    />
                    <AiModelTabs :current-model="currentModel" :available-models="availableModels" />
                </div>

                <div class="border-t pt-8">
                    <Heading
                        variant="small"
                        title="Instruções personalizadas"
                        description="Conte à Nutri como ela deve te tratar e o que é importante sobre você"
                    />

                    <div class="mt-3 space-y-2">
                        <p class="text-muted-foreground text-xs leading-relaxed">
                            Aqui você pode escrever sobre você, suas preferências e restrições alimentares. A Nutri vai usar essas informações para personalizar todas as respostas e sugestões.
                        </p>

                        <div class="rounded-lg border border-dashed border-neutral-300 bg-neutral-50 p-3 dark:border-neutral-700 dark:bg-neutral-900">
                            <p class="text-muted-foreground mb-1 text-xs font-medium">Exemplos do que você pode escrever:</p>
                            <ul class="text-muted-foreground list-inside list-disc space-y-0.5 text-xs">
                                <li>Sou vegetariano / vegano</li>
                                <li>Tenho intolerância à lactose</li>
                                <li>Sou diabético tipo 2</li>
                                <li>Prefiro respostas curtas e diretas</li>
                                <li>Sou do Nordeste e como muito cuscuz e tapioca</li>
                                <li>Estou em período de amamentação</li>
                                <li>Me chame de apelido (ex: Rafa)</li>
                            </ul>
                        </div>

                        <Textarea
                            v-model="form.custom_instructions"
                            rows="4"
                            placeholder="Ex: Sou vegetariano, tenho intolerância à lactose e prefiro respostas curtas..."
                            class="resize-y"
                            :maxlength="maxChars"
                        />

                        <div class="flex items-center justify-between">
                            <span class="text-muted-foreground text-xs">
                                {{ form.custom_instructions?.length ?? 0 }} / {{ maxChars }} caracteres
                            </span>

                            <div class="flex items-center gap-2">
                                <Transition
                                    enter-active-class="transition-opacity duration-200"
                                    enter-from-class="opacity-0"
                                    leave-active-class="transition-opacity duration-200"
                                    leave-to-class="opacity-0"
                                >
                                    <span v-if="saved" class="text-xs text-green-600 dark:text-green-400">
                                        Salvo!
                                    </span>
                                </Transition>

                                <Button
                                    size="sm"
                                    :disabled="form.processing || !form.isDirty"
                                    @click="saveInstructions"
                                >
                                    Salvar
                                </Button>
                            </div>
                        </div>

                        <p v-if="form.errors.custom_instructions" class="text-xs text-red-500">
                            {{ form.errors.custom_instructions }}
                        </p>
                    </div>
                </div>
            </div>
        </SettingsLayout>
    </AppLayout>
</template>
