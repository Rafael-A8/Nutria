<?php

namespace App\Http\Controllers;

use App\Ai\Agents\NutritionistAgent;
use App\Http\Requests\SendAudioMessageRequest;
use App\Http\Requests\SendMessageRequest;
use App\Models\User;
use App\Services\ChatMessageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Ai\Transcription;

class ChatController extends Controller
{
    public function __construct(
        private ChatMessageService $chatMessageService,
    ) {}

    /**
     * Show the chat page.
     */
    public function index(): Response
    {
        return Inertia::render('Chat/Index');
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

    /**
     * @return array{reply: string, conversationId: string}
     */
    private function sendToAgent(User $user, string $message): array
    {
        $agent = new NutritionistAgent($user);

        $conversationId = $this->getCurrentMonthConversationId($user->id);

        if ($conversationId) {
            $response = $agent->continue($conversationId, as: $user)
                ->prompt($message);
        } else {
            $response = $agent->forUser($user)
                ->prompt($message);
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
