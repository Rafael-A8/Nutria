<?php

namespace App\Http\Controllers;

use App\Ai\Agents\NutritionistAgent;
use App\Http\Requests\SendMessageRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class ChatController extends Controller
{
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
        $agent = new NutritionistAgent($user);

        $conversationId = $this->getCurrentMonthConversationId($user->id);

        if ($conversationId) {
            $response = $agent->continue($conversationId, as: $user)
                ->prompt($request->validated('message'));
        } else {
            $response = $agent->forUser($user)
                ->prompt($request->validated('message'));
        }

        return response()->json([
            'reply' => $response->text,
            'conversationId' => $response->conversationId,
        ]);
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
