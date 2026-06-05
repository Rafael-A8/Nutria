<?php

namespace App\Ai\Middleware;

use App\Enums\UserMemoryCategory;
use App\Models\User;
use App\Models\UserMemory;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Prompts\AgentPrompt;

class InjectMemories
{
    private const float MIN_SIMILARITY = 0.35;

    private const int LIMIT = 4;

    public function __construct(private User $user) {}

    /**
     * Handle the incoming prompt.
     */
    public function handle(AgentPrompt $prompt, Closure $next): mixed
    {
        $message = trim($prompt->prompt);

        if ($message === '') {
            return $next($prompt);
        }

        $memories = $this->getPriorityMemories()
            ->concat($this->searchRelevantMemories($message))
            ->unique('id')
            ->values();

        if (app()->environment('local')) {
            Log::info('InjectMemories final memories selected', [
                'user_id' => $this->user->id,
                'message' => $message,
                'count' => $memories->count(),
                'memories' => $memories->map(fn (UserMemory $memory) => [
                    'id' => $memory->id,
                    'category' => $memory->category,
                    'content' => $memory->content,
                ])->values(),
            ]);
        }

        if ($memories->isEmpty()) {
            return $next($prompt);
        }

        return $next($prompt->append($this->formatMemories($memories)));
    }

    /**
     * @return Collection<int, UserMemory>
     */
    private function getPriorityMemories(): Collection
    {
        return UserMemory::query()
            ->whereBelongsTo($this->user)
            ->whereIn('category', UserMemoryCategory::priorityValues())
            ->latest()
            ->limit(10)
            ->get();
    }

    /**
     * @return Collection<int, UserMemory>
     */
    private function searchRelevantMemories(string $message): Collection
    {
        if (! UserMemory::query()->whereBelongsTo($this->user)->exists()) {
            return collect();
        }

        return UserMemory::query()
            ->whereBelongsTo($this->user)
            ->whereIn('category', UserMemoryCategory::contextualValues())
            ->whereVectorSimilarTo('embedding', $message, minSimilarity: self::MIN_SIMILARITY)
            ->limit(self::LIMIT)
            ->get();
    }

    /**
     * @param  Collection<int, UserMemory>  $memories
     */
    private function formatMemories(Collection $memories): string
    {
        $lines = $memories
            ->map(fn (UserMemory $memory): string => "- [{$memory->category}] {$memory->content}")
            ->implode("\n");

        return <<<PROMPT
        USER MEMORIES
        {$lines}

        Use these memories naturally when relevant.
        Never mention memory retrieval.
        Never expose internal memory mechanisms.
        PROMPT;
    }
}
