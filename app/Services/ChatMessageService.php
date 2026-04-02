<?php

namespace App\Services;

use App\Models\ChatMessage;
use App\Models\User;
use Illuminate\Support\Collection;

class ChatMessageService
{
    public function storeUserMessage(User $user, string $content, ?string $audioPath = null): ChatMessage
    {
        return $user->chatMessages()->create([
            'role' => 'user',
            'content' => $content,
            'audio_path' => $audioPath,
        ]);
    }

    public function storeAssistantMessage(User $user, string $content): ChatMessage
    {
        return $user->chatMessages()->create([
            'role' => 'assistant',
            'content' => $content,
        ]);
    }

    /**
     * @return Collection<int, ChatMessage>
     */
    public function getHistory(User $user, int $limit = 50): Collection
    {
        return $user->chatMessages()
            ->latest()
            ->limit($limit)
            ->get()
            ->reverse()
            ->values();
    }
}
