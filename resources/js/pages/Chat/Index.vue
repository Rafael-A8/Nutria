<script setup lang="ts">
import { nextTick, onMounted, ref } from 'vue';
import { Head, usePage } from '@inertiajs/vue3';
import { Mic, SendHorizontal, Square, Utensils } from 'lucide-vue-next';
import { sendAudioMessage, sendMessage } from '@/actions/App/Http/Controllers/ChatController';
import AppLayout from '@/layouts/AppLayout.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import type { BreadcrumbItem, ChatMessage, User } from '@/types';

interface DisplayMessage {
    id?: number;
    role: 'user' | 'assistant';
    content: string;
    audioPath?: string | null;
}

const props = defineProps<{
    chatMessages: ChatMessage[];
}>();

const page = usePage();
const user = page.props.auth.user as User;

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Chat Nutricional', href: '/chat' }];

const messages = ref<DisplayMessage[]>([]);

if (props.chatMessages.length > 0) {
    messages.value = props.chatMessages.map((m) => ({
        id: m.id,
        role: m.role,
        content: m.content,
        audioPath: m.audio_path,
    }));
} else {
    messages.value = [
        {
            role: 'assistant',
            content: `Olá, ${user.name}! 💚 Sou a Nutri, sua assistente nutricional.\n\nMe conte o que você comeu hoje ou peça um resumo das suas calorias. Estou aqui para ajudar!`,
        },
    ];
}

const messageInput = ref('');
const isLoading = ref(false);
const messagesContainer = ref<HTMLDivElement | null>(null);

type RecordingState = 'idle' | 'recording' | 'sending';
const recordingState = ref<RecordingState>('idle');
const recordingDuration = ref(0);
let mediaRecorder: MediaRecorder | null = null;
let audioChunks: Blob[] = [];
let recordingTimer: ReturnType<typeof setInterval> | null = null;

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

onMounted(() => void scrollToBottom());

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

        if (!res.ok) throw new Error(`HTTP ${res.status}`);

        const data = (await res.json()) as { reply: string };
        messages.value.push({ role: 'assistant', content: data.reply });
    } catch {
        messages.value.push({
            role: 'assistant',
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

async function toggleRecording(): Promise<void> {
    if (recordingState.value === 'recording') {
        stopRecording();
    } else if (recordingState.value === 'idle') {
        await startRecording();
    }
}

async function startRecording(): Promise<void> {
    try {
        const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
        mediaRecorder = new MediaRecorder(stream, { mimeType: 'audio/webm' });
        audioChunks = [];

        mediaRecorder.ondataavailable = (e) => {
            if (e.data.size > 0) audioChunks.push(e.data);
        };

        mediaRecorder.onstop = () => {
            stream.getTracks().forEach((t) => t.stop());
            void sendAudioRecording();
        };

        mediaRecorder.start();
        recordingState.value = 'recording';
        recordingDuration.value = 0;
        recordingTimer = setInterval(() => recordingDuration.value++, 1000);
    } catch {
        // User denied microphone permission or browser doesn't support it
    }
}

function stopRecording(): void {
    if (mediaRecorder && mediaRecorder.state === 'recording') {
        mediaRecorder.stop();
        if (recordingTimer) {
            clearInterval(recordingTimer);
            recordingTimer = null;
        }
    }
}

async function sendAudioRecording(): Promise<void> {
    if (audioChunks.length === 0) {
        recordingState.value = 'idle';
        return;
    }

    recordingState.value = 'sending';
    isLoading.value = true;

    const blob = new Blob(audioChunks, { type: 'audio/webm' });
    const formData = new FormData();
    formData.append('audio', blob, 'recording.webm');

    const tempIndex = messages.value.length;
    messages.value.push({ role: 'user', content: '🎤 Enviando áudio...' });
    await scrollToBottom();

    try {
        const res = await fetch(sendAudioMessage.url(), {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'X-XSRF-TOKEN': getCsrfToken(),
            },
            body: formData,
        });

        if (!res.ok) throw new Error(`HTTP ${res.status}`);

        const data = (await res.json()) as { reply: string; transcription: string };
        messages.value[tempIndex] = { role: 'user', content: data.transcription };
        messages.value.push({ role: 'assistant', content: data.reply });
    } catch {
        messages.value[tempIndex] = { role: 'user', content: '❌ Erro ao enviar áudio.' };
    } finally {
        recordingState.value = 'idle';
        isLoading.value = false;
        await scrollToBottom();
    }
}

function formatDuration(seconds: number): string {
    const m = Math.floor(seconds / 60)
        .toString()
        .padStart(2, '0');
    const s = (seconds % 60).toString().padStart(2, '0');
    return `${m}:${s}`;
}

function getAudioUrl(messageId: number): string {
    return `/chat/audio/${messageId}`;
}
</script>

<template>
    <Head title="Chat Nutricional" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-col overflow-hidden">
            <!-- Cabeçalho -->
            <div class="flex shrink-0 items-center justify-between border-b bg-background px-4 py-3">
                <div class="flex items-center gap-2">
                    <Utensils class="size-4 text-primary" />
                    <h1 class="text-sm font-semibold">Diário Nutricional</h1>
                </div>
            </div>

            <!-- Área de mensagens -->
            <div ref="messagesContainer" class="flex-1 space-y-3 overflow-y-auto px-4 py-4">
                <div
                    v-for="(message, index) in messages"
                    :key="index"
                    class="flex"
                    :class="message.role === 'user' ? 'justify-end' : 'justify-start'"
                >
                    <!-- Avatar da Nutri -->
                    <div
                        v-if="message.role === 'assistant'"
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

                        <!-- Audio player para mensagens de voz -->
                        <audio
                            v-if="message.audioPath && message.id"
                            :src="getAudioUrl(message.id)"
                            controls
                            class="mt-2 h-8 w-full"
                        />
                    </div>
                </div>

                <!-- Indicador "Nutri pensando..." -->
                <div v-if="isLoading" class="flex justify-start">
                    <div
                        class="mr-2 flex size-7 shrink-0 items-center justify-center self-end rounded-full bg-primary text-xs font-bold text-primary-foreground"
                    >
                        N
                    </div>
                    <div class="flex items-center gap-1.5 rounded-2xl rounded-bl-sm bg-muted px-4 py-3">
                        <span class="size-2 animate-bounce rounded-full bg-muted-foreground/60 [animation-delay:-0.3s]" />
                        <span class="size-2 animate-bounce rounded-full bg-muted-foreground/60 [animation-delay:-0.15s]" />
                        <span class="size-2 animate-bounce rounded-full bg-muted-foreground/60" />
                    </div>
                </div>
            </div>

            <!-- Barra de input -->
            <div class="shrink-0 border-t bg-background px-4 py-3">
                <!-- Indicador de gravação -->
                <div
                    v-if="recordingState === 'recording'"
                    class="mb-3 flex items-center justify-center gap-3 rounded-lg bg-destructive/10 px-4 py-2"
                >
                    <span class="size-2.5 animate-pulse rounded-full bg-destructive" />
                    <span class="text-sm font-medium text-destructive">Gravando {{ formatDuration(recordingDuration) }}</span>
                    <Button
                        size="sm"
                        variant="destructive"
                        class="ml-2 h-7 rounded-full px-3 text-xs"
                        @click="toggleRecording"
                    >
                        <Square class="mr-1 size-3" />
                        Parar
                    </Button>
                </div>

                <form class="flex items-center gap-2" @submit.prevent="handleSend">
                    <Input
                        v-model="messageInput"
                        placeholder="O que você comeu hoje?"
                        class="flex-1 rounded-full bg-muted px-4"
                        :disabled="isLoading || recordingState !== 'idle'"
                        autocomplete="off"
                        @keydown="handleKeydown"
                    />

                    <!-- Botão de microfone -->
                    <Button
                        type="button"
                        size="icon"
                        class="shrink-0 rounded-full"
                        :variant="recordingState === 'recording' ? 'destructive' : 'outline'"
                        :disabled="isLoading || recordingState === 'sending'"
                        @click="toggleRecording"
                    >
                        <Square v-if="recordingState === 'recording'" class="size-4" />
                        <Mic v-else class="size-4" />
                    </Button>

                    <!-- Botão de enviar texto -->
                    <Button
                        type="submit"
                        size="icon"
                        class="shrink-0 rounded-full"
                        :disabled="isLoading || !messageInput.trim() || recordingState !== 'idle'"
                    >
                        <SendHorizontal class="size-4" />
                    </Button>
                </form>
            </div>
        </div>
    </AppLayout>
</template>
