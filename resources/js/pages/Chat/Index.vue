<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import {
    ChevronDown,
    ImagePlus,
    Mic,
    SendHorizontal,
    Square,
} from 'lucide-vue-next';
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue';
import {
    sendAudioMessage,
    sendImageMessage,
    sendMessage,
} from '@/actions/App/Http/Controllers/ChatController';
import MarkdownMessage from '@/components/MarkdownMessage.vue';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItem, ChatMessage, User } from '@/types';

interface DisplayMessage {
    id?: number;
    role: 'user' | 'assistant';
    content: string;
    audioPath?: string | null;
    imagePaths?: string[] | null;
}

const props = defineProps<{
    chatMessages: ChatMessage[];
}>();

const page = usePage();
const user = page.props.auth.user as User;

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Chat Nutricional', href: '/chat' },
];

const messages = ref<DisplayMessage[]>([]);

if (props.chatMessages.length > 0) {
    messages.value = props.chatMessages.map((m) => ({
        id: m.id,
        role: m.role,
        content: m.content,
        audioPath: m.audio_path,
        imagePaths: m.image_paths,
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
const isMobile = ref(false);

function checkMobile(): void {
    isMobile.value = window.matchMedia('(max-width: 768px)').matches;
}

const imageInputRef = ref<HTMLInputElement | null>(null);
const selectedImages = ref<{ file: File; preview: string }[]>([]);

function resizeTextarea(): void {
    const el = textareaRef.value;

    if (!el) {
        return;
    }

    const maxHeight = 200;
    el.style.height = 'auto';
    const targetHeight = Math.min(el.scrollHeight, maxHeight);
    el.style.height = targetHeight + 'px';
    el.style.overflowY = targetHeight >= maxHeight ? 'auto' : 'hidden';

    if (isAtBottom.value) {
        void nextTick(() => {
            if (messagesContainer.value) {
                messagesContainer.value.scrollTop =
                    messagesContainer.value.scrollHeight;
            }
        });
    }
}

function triggerImagePicker(): void {
    imageInputRef.value?.click();
}

function handleImageSelect(event: Event): void {
    const input = event.target as HTMLInputElement;

    if (!input.files) {
        return;
    }

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

async function scrollToBottom(smooth = true): Promise<void> {
    await nextTick();
    await nextTick();

    if (messagesContainer.value) {
        messagesContainer.value.scrollTo({
            top: messagesContainer.value.scrollHeight,
            behavior: smooth ? 'smooth' : 'instant',
        });
        isAtBottom.value = true;
    }
}

function handleMessagesScroll(): void {
    const el = messagesContainer.value;

    if (!el) {
        return;
    }

    isAtBottom.value = el.scrollHeight - el.scrollTop - el.clientHeight < 80;
}

onMounted(() => {
    checkMobile();
    window.addEventListener('resize', checkMobile);
    void scrollToBottom(false);
});

onUnmounted(() => {
    window.removeEventListener('resize', checkMobile);
});

async function handleSend(): Promise<void> {
    const text = messageInput.value.trim();
    const images = [...selectedImages.value];
    const hasImages = images.length > 0;

    if ((!text && !hasImages) || isLoading.value) {
        return;
    }

    const previews = images.map((img) => img.preview);
    messages.value.push({
        role: 'user',
        content:
            text ||
            `Enviou ${images.length} ${images.length === 1 ? 'imagem' : 'imagens'}`,
        imagePaths: hasImages ? previews : null,
    });

    messageInput.value = '';
    selectedImages.value = [];
    isLoading.value = true;
    await nextTick();
    resizeTextarea();
    await scrollToBottom();

    try {
        let data: { reply: string; imagePaths?: string[] };

        if (hasImages) {
            const formData = new FormData();

            if (text) {
                formData.append('message', text);
            }

            images.forEach((img) => formData.append('images[]', img.file));

            const res = await fetch(sendImageMessage.url(), {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'X-XSRF-TOKEN': getCsrfToken(),
                },
                body: formData,
            });

            if (!res.ok) {
                throw new Error(`HTTP ${res.status}`);
            }

            data = (await res.json()) as {
                reply: string;
                imagePaths: string[];
            };

            // Replace previews with stored paths
            const lastUserMsg = [...messages.value]
                .reverse()
                .find((m) => m.role === 'user' && m.imagePaths);

            if (lastUserMsg && data.imagePaths) {
                previews.forEach((p) => URL.revokeObjectURL(p));
                lastUserMsg.imagePaths = data.imagePaths.map(
                    (p) => `/storage/${p}`,
                );
            }
        } else {
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

            data = (await res.json()) as { reply: string };
        }

        messages.value.push({ role: 'assistant', content: data.reply });
    } catch {
        messages.value.push({
            role: 'assistant',
            content:
                'Ops! Não consegui processar sua mensagem agora. Tente novamente em instantes.',
        });
    } finally {
        isLoading.value = false;
        await scrollToBottom();
    }
}

function handleKeydown(event: KeyboardEvent): void {
    if (event.key === 'Enter') {
        if (isMobile.value) {
            return;
        }

        if (!event.shiftKey && hasContent.value) {
            event.preventDefault();
            void handleSend();
        }
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
        const stream = await navigator.mediaDevices.getUserMedia({
            audio: true,
        });
        mediaRecorder = new MediaRecorder(stream, { mimeType: 'audio/webm' });
        audioChunks = [];

        mediaRecorder.ondataavailable = (e) => {
            if (e.data.size > 0) {
                audioChunks.push(e.data);
            }
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

        if (!res.ok) {
            throw new Error(`HTTP ${res.status}`);
        }

        const data = (await res.json()) as {
            reply: string;
            transcription: string;
        };
        messages.value[tempIndex] = {
            role: 'user',
            content: data.transcription,
        };
        messages.value.push({ role: 'assistant', content: data.reply });
    } catch {
        messages.value[tempIndex] = {
            role: 'user',
            content: '❌ Erro ao enviar áudio.',
        };
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

function getImageUrl(path: string): string {
    // Blob previews or already prefixed paths pass through
    if (path.startsWith('blob:') || path.startsWith('/storage/')) {
        return path;
    }

    return `/storage/${path}`;
}

const hasContent = computed(
    () => messageInput.value.trim() || selectedImages.value.length > 0,
);
</script>

<template>
    <Head title="Chat Nutricional" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div
            class="absolute inset-x-0 top-16 bottom-0 flex flex-col overflow-hidden group-has-data-[collapsible=icon]/sidebar-wrapper:top-12"
        >
            <!-- Área de mensagens -->
            <div
                ref="messagesContainer"
                class="scrollbar-hide-mobile flex-1 space-y-6 overflow-y-auto px-4 py-6"
                @scroll="handleMessagesScroll"
            >
                <template v-for="(message, index) in messages" :key="index">
                    <!-- Mensagem do usuário — com card/bolha -->
                    <div
                        v-if="message.role === 'user'"
                        class="flex justify-end"
                    >
                        <div
                            class="max-w-[85%] rounded-2xl rounded-br-sm bg-primary px-4 py-2.5 text-[15px] leading-relaxed text-primary-foreground sm:max-w-[70%]"
                        >
                            <div
                                v-if="
                                    message.imagePaths &&
                                    message.imagePaths.length > 0
                                "
                                class="mb-2 flex flex-wrap gap-1.5"
                                :class="
                                    message.imagePaths.length > 1
                                        ? 'grid grid-cols-2'
                                        : ''
                                "
                            >
                                <img
                                    v-for="(
                                        imgPath, imgIndex
                                    ) in message.imagePaths"
                                    :key="imgIndex"
                                    :src="getImageUrl(imgPath)"
                                    :alt="`Imagem ${imgIndex + 1}`"
                                    class="max-h-48 w-full rounded-lg object-cover"
                                />
                            </div>
                            <p class="whitespace-pre-wrap">
                                {{ message.content }}
                            </p>
                            <audio
                                v-if="message.audioPath && message.id"
                                :src="getAudioUrl(message.id)"
                                controls
                                class="mt-2 h-8 w-full"
                            />
                        </div>
                    </div>

                    <!-- Mensagem da assistente — sem card, texto solto -->
                    <div v-else class="flex items-start gap-3">
                        <div
                            class="mt-0.5 flex size-7 shrink-0 items-center justify-center rounded-full bg-primary text-xs font-bold text-primary-foreground"
                        >
                            N
                        </div>
                        <div
                            class="min-w-0 flex-1 text-[15px] leading-relaxed text-foreground sm:max-w-[85%]"
                        >
                            <div
                                v-if="
                                    message.imagePaths &&
                                    message.imagePaths.length > 0
                                "
                                class="mb-3 flex flex-wrap gap-1.5"
                                :class="
                                    message.imagePaths.length > 1
                                        ? 'grid grid-cols-2'
                                        : ''
                                "
                            >
                                <img
                                    v-for="(
                                        imgPath, imgIndex
                                    ) in message.imagePaths"
                                    :key="imgIndex"
                                    :src="getImageUrl(imgPath)"
                                    :alt="`Imagem ${imgIndex + 1}`"
                                    class="max-h-48 rounded-lg object-cover"
                                    :class="
                                        message.imagePaths.length === 1
                                            ? 'max-w-sm'
                                            : 'w-full'
                                    "
                                />
                            </div>
                            <MarkdownMessage :content="message.content" />
                            <audio
                                v-if="message.audioPath && message.id"
                                :src="getAudioUrl(message.id)"
                                controls
                                class="mt-3 h-8 w-full max-w-sm"
                            />
                        </div>
                    </div>
                </template>

                <!-- Indicador "Nutri pensando..." -->
                <div v-if="isLoading" class="flex items-start gap-3">
                    <div
                        class="mt-0.5 flex size-7 shrink-0 items-center justify-center rounded-full bg-primary text-xs font-bold text-primary-foreground"
                    >
                        N
                    </div>
                    <div class="flex items-center gap-1.5 py-2">
                        <span
                            class="size-2 animate-bounce rounded-full bg-muted-foreground/60 [animation-delay:-0.3s]"
                        />
                        <span
                            class="size-2 animate-bounce rounded-full bg-muted-foreground/60 [animation-delay:-0.15s]"
                        />
                        <span
                            class="size-2 animate-bounce rounded-full bg-muted-foreground/60"
                        />
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
                        class="absolute -top-12 left-1/2 z-10 flex size-9 -translate-x-1/2 items-center justify-center rounded-full border border-input bg-background shadow-lg hover:bg-muted md:size-auto md:gap-1.5 md:px-3 md:py-1.5 md:shadow-md"
                        @click="scrollToBottom"
                    >
                        <ChevronDown
                            class="size-4 text-muted-foreground md:size-3.5"
                        />
                        <span
                            class="sr-only md:not-sr-only md:text-xs md:font-medium md:text-muted-foreground"
                            >Ir para o final</span
                        >
                    </button>
                </Transition>
            </div>

            <!-- Barra de input estilo pill -->
            <div
                class="shrink-0 bg-background px-3 pt-2 pb-[max(0.75rem,env(safe-area-inset-bottom))] sm:px-4"
            >
                <form @submit.prevent="handleSend">
                    <div
                        class="rounded-2xl border border-input bg-muted shadow-xs transition-shadow focus-within:border-ring focus-within:ring-[3px] focus-within:ring-ring/50 dark:bg-input/30"
                    >
                        <!-- Preview de imagens selecionadas -->
                        <div
                            v-if="selectedImages.length > 0"
                            class="flex gap-2 overflow-x-auto px-3 pt-3"
                        >
                            <div
                                v-for="(img, index) in selectedImages"
                                :key="index"
                                class="group relative size-16 shrink-0 overflow-hidden rounded-lg"
                            >
                                <img
                                    :src="img.preview"
                                    :alt="`Imagem ${index + 1}`"
                                    class="size-full object-cover"
                                />
                                <button
                                    type="button"
                                    class="absolute inset-0 flex items-center justify-center bg-black/50 opacity-0 transition-opacity group-hover:opacity-100"
                                    @click="removeImage(index)"
                                >
                                    <span class="text-xs font-bold text-white"
                                        >✕</span
                                    >
                                </button>
                            </div>
                        </div>

                        <!-- Indicador de gravação (dentro da pill) -->
                        <div
                            v-if="recordingState === 'recording'"
                            class="flex items-center justify-center gap-3 px-4 py-3"
                        >
                            <span
                                class="size-2.5 animate-pulse rounded-full bg-destructive"
                            />
                            <span class="text-sm font-medium text-destructive"
                                >Gravando
                                {{ formatDuration(recordingDuration) }}</span
                            >
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
                            class="w-full resize-none bg-transparent px-4 pt-3 pb-1 text-[15px] leading-relaxed transition-[height] duration-150 ease-out outline-none placeholder:text-muted-foreground disabled:pointer-events-none disabled:cursor-not-allowed disabled:opacity-50"
                            :disabled="isLoading || recordingState !== 'idle'"
                            autocomplete="off"
                            @keydown="handleKeydown"
                        />

                        <!-- Linha de ações (dentro da pill) -->
                        <div
                            class="flex items-center justify-between px-2 pt-1 pb-2"
                        >
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
                                    :disabled="
                                        isLoading || recordingState !== 'idle'
                                    "
                                    @click="triggerImagePicker"
                                >
                                    <ImagePlus class="size-4" />
                                </Button>
                            </div>

                            <!-- Lado direito: mic + send -->
                            <div class="flex items-center gap-0.5">
                                <Button
                                    v-if="!hasContent"
                                    type="button"
                                    size="icon"
                                    variant="ghost"
                                    class="size-8 rounded-full text-muted-foreground hover:text-foreground"
                                    :class="
                                        recordingState === 'recording'
                                            ? 'text-destructive hover:text-destructive'
                                            : ''
                                    "
                                    :disabled="
                                        isLoading ||
                                        recordingState === 'sending'
                                    "
                                    @click="toggleRecording"
                                >
                                    <Square
                                        v-if="recordingState === 'recording'"
                                        class="size-4"
                                    />
                                    <Mic v-else class="size-4" />
                                </Button>

                                <Button
                                    v-if="hasContent"
                                    type="submit"
                                    size="icon"
                                    class="size-8 rounded-full"
                                    :disabled="
                                        isLoading ||
                                        !hasContent ||
                                        recordingState !== 'idle'
                                    "
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
