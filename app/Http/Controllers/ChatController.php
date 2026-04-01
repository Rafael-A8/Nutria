<?php

namespace App\Http\Controllers;

use App\Ai\Agents\NutritionistAgent;
use App\Http\Requests\SendMessageRequest;
use Illuminate\Http\JsonResponse;
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

    /**
     * Send a message to the NutritionistAgent and return the reply.
     */
    public function sendMessage(SendMessageRequest $request): JsonResponse
    {
        $user = $request->user();
        $agent = new NutritionistAgent($user);
        $response = $agent->prompt($request->validated('message'));

        return response()->json(['reply' => $response->text]);
    }
}
