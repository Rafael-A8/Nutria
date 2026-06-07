<?php

namespace App\Services;

use App\Models\ChatMessage;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class ChatMessageService
{
    /**
     * @param  list<string>|null  $imagePaths
     */
    public function storeUserMessage(User $user, string $content, ?string $audioPath = null, ?array $imagePaths = null): ChatMessage
    {
        return $user->chatMessages()->create([
            'role' => 'user',
            'content' => $content,
            'audio_path' => $audioPath,
            'image_paths' => $imagePaths,
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

    public function countUserMessagesForPeriod(User $user, Carbon $startDate, Carbon $endDate): int
    {
        return $user->chatMessages()
            ->where('role', 'user')
            ->whereBetween('created_at', [$startDate->copy(), $endDate->copy()])
            ->count();
    }

    public function countUserMessagesForDay(User $user, Carbon $date): int
    {
        return $this->countUserMessagesForPeriod($user, $date->copy()->startOfDay(), $date->copy()->endOfDay());
    }

    public function getPreviousUserMessage(User $user): ?ChatMessage
    {
        return $user->chatMessages()
            ->where('role', 'user')
            ->latest('created_at')
            ->latest('id')
            ->skip(1)
            ->first();
    }

    /**
     * @return Collection<int, ChatMessage>
     */
    public function getUserMessagesForPeriod(User $user, Carbon $startDate, Carbon $endDate, int $limit = 40): Collection
    {
        return $user->chatMessages()
            ->where('role', 'user')
            ->whereBetween('created_at', [$startDate->copy(), $endDate->copy()])
            ->latest('created_at')
            ->latest('id')
            ->limit($limit)
            ->get()
            ->reverse()
            ->values();
    }
}
