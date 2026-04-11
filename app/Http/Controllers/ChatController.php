<?php

namespace App\Http\Controllers;

use App\Ai\Agents\NutritionistAgent;
use App\Enums\AiModel;
use App\Http\Requests\SendAudioMessageRequest;
use App\Http\Requests\SendImageMessageRequest;
use App\Http\Requests\SendMessageRequest;
use App\Models\ChatMessage;
use App\Models\User;
use App\Services\ChatMessageService;
use App\Services\SummaryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Ai\Files;
use Laravel\Ai\Transcription;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ChatController extends Controller
{
    public function __construct(
        private ChatMessageService $chatMessageService,
        private SummaryService $summaryService,
    ) {}

    /**
     * Show the chat page.
     */
    public function index(): Response
    {
        /** @var User $user */
        $user = auth()->user();

        return Inertia::render('Chat/Index', [
            'chatMessages' => $this->chatMessageService->getHistory($user),
        ]);
    }

    public function sendMessage(SendMessageRequest $request): JsonResponse
    {
        $user = $request->user();
        $message = $request->validated('message');

        $this->chatMessageService->storeUserMessage($user, $message);

        $result = $this->sendToAgent($user, $message);

        $this->chatMessageService->storeAssistantMessage($user, $result['reply']);

        return response()->json($result);
    }

    public function sendImageMessage(SendImageMessageRequest $request): JsonResponse
    {
        $user = $request->user();
        $message = $request->validated('message') ?? '';

        $imagePaths = [];
        $attachments = [];

        foreach ($request->file('images') as $image) {
            $path = $image->store("images/{$user->id}", 'public');
            $imagePaths[] = $path;
            $attachments[] = Files\Image::fromStorage($path, disk: 'public');
        }

        $displayText = $message ?: 'Enviou '.count($imagePaths).' '.(count($imagePaths) === 1 ? 'imagem' : 'imagens');
        $this->chatMessageService->storeUserMessage($user, $displayText, imagePaths: $imagePaths);

        $promptText = $message
            ? $message
            : 'O usuário enviou '.count($imagePaths).' '.(count($imagePaths) === 1 ? 'imagem' : 'imagens').' de comida. Analise e descreva o que vê, estime as calorias e registre a refeição.';

        $result = $this->sendToAgent($user, $promptText, $attachments);

        $this->chatMessageService->storeAssistantMessage($user, $result['reply']);

        return response()->json([
            ...$result,
            'imagePaths' => $imagePaths,
        ]);
    }

    public function sendAudioMessage(SendAudioMessageRequest $request): JsonResponse
    {
        $user = $request->user();

        $audioPath = $request->file('audio')->store("audio/{$user->id}", 'local');

        $transcript = Transcription::fromUpload($request->file('audio'))
            ->language('pt')
            ->generate();

        $transcribedText = $transcript->text;

        $this->chatMessageService->storeUserMessage($user, $transcribedText, $audioPath);

        $result = $this->sendToAgent($user, $transcribedText);

        $this->chatMessageService->storeAssistantMessage($user, $result['reply']);

        return response()->json([
            ...$result,
            'transcription' => $transcribedText,
        ]);
    }

    public function audioFile(ChatMessage $chatMessage): StreamedResponse
    {
        /** @var User $user */
        $user = auth()->user();

        if ($chatMessage->user_id !== $user->id) {
            abort(403);
        }

        if (! $chatMessage->audio_path) {
            abort(404);
        }

        return Storage::disk('local')->response($chatMessage->audio_path);
    }

    public function imageFile(ChatMessage $chatMessage, int $index): StreamedResponse
    {
        /** @var User $user */
        $user = auth()->user();

        if ($chatMessage->user_id !== $user->id) {
            abort(403);
        }

        $paths = $chatMessage->image_paths ?? [];

        if (! isset($paths[$index])) {
            abort(404);
        }

        return Storage::disk('public')->response($paths[$index]);
    }

    /**
     * @param  list<Files\Image|Files\Document>  $attachments
     * @return array{reply: string, conversationId: string}
     */
    private function sendToAgent(User $user, string $message, array $attachments = []): array
    {
        $agent = new NutritionistAgent($user);

        $conversationId = $this->getCurrentMonthConversationId($user->id);

        if (! $conversationId) {
            $this->summaryService->generateIfNeeded($user);
        }

        $model = AiModel::tryFrom($user->profile?->preferred_ai_model ?? '') ?? AiModel::default();

        $promptArgs = ['provider' => $model->providerChain()];

        if ($attachments !== []) {
            $promptArgs['attachments'] = $attachments;
        }

        if ($conversationId) {
            $response = $agent->continue($conversationId, as: $user)
                ->prompt($message, ...$promptArgs);
        } else {
            $response = $agent->forUser($user)
                ->prompt($message, ...$promptArgs);
        }

        return [
            'reply' => $response->text,
            'conversationId' => $response->conversationId,
        ];
    }

    private function getCurrentMonthConversationId(int $userId): ?string
    {
        return DB::table('agent_conversations')
            ->where('user_id', $userId)
            ->whereMonth('created_at', now()->month)
            ->whereYear('created_at', now()->year)
            ->latest('updated_at')
            ->value('id');
    }
}
