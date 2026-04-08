<?php

namespace App\Http\Controllers;

use App\Ai\Agents\NutritionistAgent;
use App\Http\Requests\SendAudioMessageRequest;
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
use Laravel\Ai\Enums\Lab;
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

    /**
     * @return array{reply: string, conversationId: string}
     */
    private function sendToAgent(User $user, string $message): array
    {
        $agent = new NutritionistAgent($user);

        $conversationId = $this->getCurrentMonthConversationId($user->id);

        if (! $conversationId) {
            $this->summaryService->generateIfNeeded($user);
        }

        $aiConfig = $this->resolveAiModel($user->profile?->preferred_ai_model);

        if ($conversationId) {
            $response = $agent->continue($conversationId, as: $user)
                ->prompt($message, provider: $aiConfig['provider'], model: $aiConfig['model']);
        } else {
            $response = $agent->forUser($user)
                ->prompt($message, provider: $aiConfig['provider'], model: $aiConfig['model']);
        }

        return [
            'reply' => $response->text,
            'conversationId' => $response->conversationId,
        ];
    }

    /**
     * Resolve AI provider and model from the user's preference.
     *
     * @return array{provider: Lab, model: string}
     */
    private function resolveAiModel(string $preference): array
    {
        return match ($preference) {
            'gpt-4o-mini' => ['provider' => Lab::OpenAI, 'model' => 'gpt-4o-mini'],
            default => ['provider' => Lab::Gemini, 'model' => 'gemini-2.0-flash-lite'],
        };
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
