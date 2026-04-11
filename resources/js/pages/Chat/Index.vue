<script setup lang="ts">
import { nextTick, onMounted, ref, watch } from 'vue';
import { Head, usePage } from '@inertiajs/vue3';
import { ChevronDown, ImagePlus, Mic, SendHorizontal, Square, Utensils } from 'lucide-vue-next';
import { sendAudioMessage, sendMessage } from '@/actions/App/Http/Controllers/ChatController';
import AppLayout from '@/layouts/AppLayout.vue';
import { Button } from '@/components/ui/button';
import MarkdownMessage from '@/components/MarkdownMessage.vue';
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
const textareaRef = ref<HTMLTextAreaElement | null>(null);
const isAtBottom = ref(true);

const imageInputRef = ref<HTMLInputElement | null>(null);
const selectedImages = ref<{ file: File; preview: string }[]>([]);

function resizeTextarea(): void {
    const el = textareaRef.value;
    if (!el) return;
    const maxHeight = 200;
    el.style.height = 'auto';
    const targetHeight = Math.min(el.scrollHeight, maxHeight);
    requestAnimationFrame(() => {
        if (!textareaRef.value) return;
        textareaRef.value.style.height = targetHeight + 'px';
        textareaRef.value.style.overflowY = targetHeight >= maxHeight ? 'auto' : 'hidden';
    });
}

function triggerImagePicker(): void {
    imageInputRef.value?.click();
}

function handleImageSelect(event: Event): void {
    const input = event.target as HTMLInputElement;
    if (!input.files) return;

    for (const file of Array.from(input.files)) {
        if (file.type.startsWith('image/')) {
            selectedImages.value.push({
                file,
                preview: URL.createObjectURL(file),
            });
        }
    }

    input.value = '';
}

function removeImage(index: number): void {
    URL.revokeObjectURL(selectedImages.value[index].preview);
    selectedImages.value.splice(index, 1);
}

watch(messageInput, () => nextTick(resizeTextarea));

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
        isAtBottom.value = true;
    }
}

function handleMessagesScroll(): void {
    const el = messagesContainer.value;
    if (!el) return;
    isAtBottom.value = el.scrollHeight - el.scrollTop - el.clientHeight < 80;
}

onMounted(() => void scrollToBottom());

async function handleSend(): Promise<void> {
    const text = messageInput.value.trim();
    if (!text || isLoading.value) return;

    messages.value.push({ role: 'user', content: text });
    messageInput.value = '';
    isLoading.value = true;
    await nextTick();
    resizeTextarea();
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
            <div ref="messagesContainer" class="flex-1 space-y-3 overflow-y-auto px-4 py-4" @scroll="handleMessagesScroll">
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
                        <MarkdownMessage v-if="message.role === 'assistant'" :content="message.content" />
                        <p v-else class="whitespace-pre-wrap">{{ message.content }}</p>

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

            <!-- Botão scroll para o final -->
            <div class="relative h-0">
                <Transition
                    enter-active-class="transition-all duration-200"
                    enter-from-class="opacity-0 translate-y-2"
                    leave-active-class="transition-all duration-150"
                    leave-to-class="opacity-0 translate-y-2"
                >
                    <button
                        v-if="!isAtBottom"
                        type="button"
                        class="absolute -top-12 left-1/2 z-10 flex -translate-x-1/2 items-center gap-1.5 rounded-full border border-input bg-background px-3 py-1.5 text-xs font-medium text-muted-foreground shadow-md hover:bg-muted hover:text-foreground"
                        @click="scrollToBottom"
                    >
                        <ChevronDown class="size-3.5" />
                        Ir para o final
                    </button>
                </Transition>
            </div>

            <!-- Barra de input estilo pill -->
            <div class="shrink-0 bg-background px-3 pb-3 pt-2 sm:px-4">
                <form @submit.prevent="handleSend">
                    <div
                        class="border-input bg-muted dark:bg-input/30 rounded-2xl border shadow-xs transition-shadow focus-within:border-ring focus-within:ring-ring/50 focus-within:ring-[3px]"
                    >
                        <!-- Preview de imagens selecionadas -->
                        <div v-if="selectedImages.length > 0" class="flex gap-2 overflow-x-auto px-3 pt-3">
                            <div
                                v-for="(img, index) in selectedImages"
                                :key="index"
                                class="group relative size-16 shrink-0 overflow-hidden rounded-lg"
                            >
                                <img :src="img.preview" :alt="`Imagem ${index + 1}`" class="size-full object-cover" />
                                <button
                                    type="button"
                                    class="absolute inset-0 flex items-center justify-center bg-black/50 opacity-0 transition-opacity group-hover:opacity-100"
                                    @click="removeImage(index)"
                                >
                                    <span class="text-xs font-bold text-white">✕</span>
                                </button>
                            </div>
                        </div>

                        <!-- Indicador de gravação (dentro da pill) -->
                        <div
                            v-if="recordingState === 'recording'"
                            class="flex items-center justify-center gap-3 px-4 py-3"
                        >
                            <span class="size-2.5 animate-pulse rounded-full bg-destructive" />
                            <span class="text-sm font-medium text-destructive">Gravando {{ formatDuration(recordingDuration) }}</span>
                            <Button
                                size="sm"
                                variant="destructive"
                                class="ml-2 h-7 rounded-full px-3 text-xs"
                                type="button"
                                @click="toggleRecording"
                            >
                                <Square class="mr-1 size-3" />
                                Parar
                            </Button>
                        </div>

                        <!-- Textarea -->
                        <textarea
                            v-show="recordingState !== 'recording'"
                            ref="textareaRef"
                            v-model="messageInput"
                            placeholder="O que você comeu hoje?"
                            rows="1"
                            class="placeholder:text-muted-foreground w-full resize-none bg-transparent px-4 pt-3 pb-1 text-sm leading-relaxed outline-none transition-[height] duration-150 ease-out disabled:pointer-events-none disabled:cursor-not-allowed disabled:opacity-50"
                            :disabled="isLoading || recordingState !== 'idle'"
                            autocomplete="off"
                            @keydown="handleKeydown"
                        />

                        <!-- Linha de ações (dentro da pill) -->
                        <div class="flex items-center justify-between px-2 pb-2 pt-1">
                            <!-- Lado esquerdo: anexos -->
                            <div class="flex items-center gap-0.5">
                                <input
                                    ref="imageInputRef"
                                    type="file"
                                    accept="image/*"
                                    multiple
                                    class="hidden"
                                    @change="handleImageSelect"
                                />
                                <Button
                                    type="button"
                                    size="icon"
                                    variant="ghost"
                                    class="size-8 rounded-full text-muted-foreground hover:text-foreground"
                                    :disabled="isLoading || recordingState !== 'idle'"
                                    @click="triggerImagePicker"
                                >
                                    <ImagePlus class="size-4" />
                                </Button>
                            </div>

                            <!-- Lado direito: mic + send -->
                            <div class="flex items-center gap-0.5">
                                <Button
                                    v-if="!messageInput.trim()"
                                    type="button"
                                    size="icon"
                                    variant="ghost"
                                    class="size-8 rounded-full text-muted-foreground hover:text-foreground"
                                    :class="recordingState === 'recording' ? 'text-destructive hover:text-destructive' : ''"
                                    :disabled="isLoading || recordingState === 'sending'"
                                    @click="toggleRecording"
                                >
                                    <Square v-if="recordingState === 'recording'" class="size-4" />
                                    <Mic v-else class="size-4" />
                                </Button>

                                <Button
                                    v-if="messageInput.trim()"
                                    type="submit"
                                    size="icon"
                                    class="size-8 rounded-full"
                                    :disabled="isLoading || recordingState !== 'idle'"
                                >
                                    <SendHorizontal class="size-4" />
                                </Button>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </AppLayout>
</template>
