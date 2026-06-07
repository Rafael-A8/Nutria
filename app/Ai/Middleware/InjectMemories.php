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
        $restrictions = $memories->where('category', UserMemoryCategory::Restricoes->value);
        $goals = $memories->where('category', UserMemoryCategory::Objetivos->value);
        $contextual = $memories->whereIn('category', [
            UserMemoryCategory::Preferencias->value,
            UserMemoryCategory::Comportamento->value,
        ]);

        $sections = ['USER MEMORIES'];

        if ($restrictions->isNotEmpty()) {
            $sections[] = "HARD SAFETY RESTRICTIONS\n".$this->formatMemoryLines($restrictions);
        }

        if ($goals->isNotEmpty()) {
            $sections[] = "ACTIVE GOALS\n".$this->formatMemoryLines($goals);
        }

        if ($contextual->isNotEmpty()) {
            $sections[] = "CONTEXTUAL PREFERENCES AND BEHAVIOR\n".$this->formatMemoryLines($contextual);
        }

        $sections[] = <<<'PROMPT'
        Memory usage rules:
        - Treat [restricoes] as hard safety constraints. If the user's food, symptom, or requested advice may relate to a restriction, explicitly mention it and adapt the guidance.
        - Treat [objetivos] as active guidance. Use goals to shape recommendations, tradeoffs, and next steps. Mention the goal when it helps the user understand the recommendation.
        - Use [preferencias] and [comportamento] naturally only when relevant.
        - Do not mention unrelated memories just because they are present.
        - Never mention memory retrieval.
        - Never expose internal memory mechanisms.
        PROMPT;

        return implode("\n\n", $sections);
    }

    /**
     * @param  Collection<int, UserMemory>  $memories
     */
    private function formatMemoryLines(Collection $memories): string
    {
        return $memories
            ->map(fn (UserMemory $memory): string => "- [{$memory->category}] {$memory->content}")
            ->implode("\n");
    }
}
