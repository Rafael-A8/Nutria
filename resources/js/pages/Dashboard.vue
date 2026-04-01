<script setup lang="ts">
import { nextTick, ref } from 'vue';
import { Head, usePage } from '@inertiajs/vue3';
import { SendHorizontal, Target, Utensils } from 'lucide-vue-next';
import { sendMessage } from '@/actions/App/Http/Controllers/ChatController';
import AppLayout from '@/layouts/AppLayout.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import type { BreadcrumbItem, User } from '@/types';

interface ChatMessage {
    role: 'user' | 'nutritionist';
    content: string;
}

const page = usePage();
const user = page.props.auth.user as User;
const dailyGoal = user.profile?.daily_calorie_goal ?? 2000;

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Chat Nutricional', href: '/dashboard' }];

const messages = ref<ChatMessage[]>([
    {
        role: 'nutritionist',
        content: `Olá, ${user.name}! 💚 Sou a Nutri, sua assistente nutricional.\n\nMe conte o que você comeu hoje ou peça um resumo das suas calorias. Estou aqui para ajudar!`,
    },
]);

const messageInput = ref('');
const isLoading = ref(false);
const messagesContainer = ref<HTMLDivElement | null>(null);

function getCsrfToken(): string {
    return decodeURIComponent(
        document.cookie
            .split('; ')
            .find((row) => row.startsWith('XSRF-TOKEN='))
            ?.split('=')
            .slice(1)
            .join('=') ?? '',
    );
}

async function scrollToBottom(): Promise<void> {
    await nextTick();
    if (messagesContainer.value) {
        messagesContainer.value.scrollTop = messagesContainer.value.scrollHeight;
    }
}

async function handleSend(): Promise<void> {
    const text = messageInput.value.trim();
    if (!text || isLoading.value) return;

    messages.value.push({ role: 'user', content: text });
    messageInput.value = '';
    isLoading.value = true;
    await scrollToBottom();

    try {
        const res = await fetch(sendMessage.url(), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-XSRF-TOKEN': getCsrfToken(),
            },
            body: JSON.stringify({ message: text }),
        });

        if (!res.ok) {
            throw new Error(`HTTP ${res.status}`);
        }

        const data = (await res.json()) as { reply: string };
        messages.value.push({ role: 'nutritionist', content: data.reply });
    } catch {
        messages.value.push({
            role: 'nutritionist',
            content: 'Ops! Não consegui processar sua mensagem agora. Tente novamente em instantes.',
        });
    } finally {
        isLoading.value = false;
        await scrollToBottom();
    }
}

function handleKeydown(event: KeyboardEvent): void {
    if (event.key === 'Enter' && !event.shiftKey) {
        event.preventDefault();
        void handleSend();
    }
}
</script>

<template>
    <Head title="Chat Nutricional" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-col overflow-hidden">
            <!-- Cabeçalho com meta calórica -->
            <div class="flex shrink-0 items-center justify-between border-b bg-background px-4 py-3">
                <div class="flex items-center gap-2">
                    <Utensils class="size-4 text-primary" />
                    <h1 class="text-sm font-semibold">Diário Nutricional</h1>
                </div>
                <div class="flex items-center gap-1.5 rounded-full bg-muted px-3 py-1 text-xs font-medium text-muted-foreground">
                    <Target class="size-3 text-primary" />
                    <span>Meta: {{ dailyGoal }} kcal/dia</span>
                </div>
            </div>

            <!-- Área de mensagens rolável -->
            <div ref="messagesContainer" class="flex-1 space-y-3 overflow-y-auto px-4 py-4">
                <div
                    v-for="(message, index) in messages"
                    :key="index"
                    class="flex"
                    :class="message.role === 'user' ? 'justify-end' : 'justify-start'"
                >
                    <!-- Avatar da Nutri -->
                    <div
                        v-if="message.role === 'nutritionist'"
                        class="mr-2 flex size-7 shrink-0 items-center justify-center self-end rounded-full bg-primary text-xs font-bold text-primary-foreground"
                    >
                        N
                    </div>

                    <div
                        class="max-w-[82%] rounded-2xl px-4 py-2.5 text-sm leading-relaxed"
                        :class="
                            message.role === 'user'
                                ? 'rounded-br-sm bg-primary text-primary-foreground'
                                : 'rounded-bl-sm bg-muted text-foreground'
                        "
                    >
                        <p class="whitespace-pre-wrap">{{ message.content }}</p>
                    </div>
                </div>

                <!-- Indicador "Nutri pensando..." -->
                <div v-if="isLoading" class="flex justify-start">
                    <div class="mr-2 flex size-7 shrink-0 items-center justify-center self-end rounded-full bg-primary text-xs font-bold text-primary-foreground">
                        N
                    </div>
                    <div class="flex items-center gap-1.5 rounded-2xl rounded-bl-sm bg-muted px-4 py-3">
                        <span class="size-2 animate-bounce rounded-full bg-muted-foreground/60 [animation-delay:-0.3s]" />
                        <span class="size-2 animate-bounce rounded-full bg-muted-foreground/60 [animation-delay:-0.15s]" />
                        <span class="size-2 animate-bounce rounded-full bg-muted-foreground/60" />
                    </div>
                </div>
            </div>

            <!-- Barra de input fixa na base -->
            <div class="shrink-0 border-t bg-background px-4 py-3">
                <form class="flex items-center gap-2" @submit.prevent="handleSend">
                    <Input
                        v-model="messageInput"
                        placeholder="O que você comeu hoje?"
                        class="flex-1 rounded-full bg-muted px-4"
                        :disabled="isLoading"
                        autocomplete="off"
                        @keydown="handleKeydown"
                    />
                    <Button
                        type="submit"
                        size="icon"
                        class="shrink-0 rounded-full"
                        :disabled="isLoading || !messageInput.trim()"
                    >
                        <SendHorizontal class="size-4" />
                    </Button>
                </form>
            </div>
        </div>
    </AppLayout>
</template>
