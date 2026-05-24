<?php

namespace App\Ai\Agents;

use App\Ai\Middleware\Guardrails;
use App\Ai\Tools\EstimateMealTool;
use App\Ai\Tools\GetPeriodSummaryTool;
use App\Ai\Tools\GetSimilarItemsTool;
use App\Ai\Tools\GetTodaySummaryTool;
use App\Ai\Tools\ParseMealMessageTool;
use App\Ai\Tools\RegisterMealTool;
use App\Ai\Tools\RegisterWeightTool;
use App\Ai\Tools\SaveMemoryTool;
use App\Ai\Tools\UpdateProfileTool;
use App\Enums\AiModel;
use App\Models\Summary;
use App\Models\User;
use App\Models\UserMemory;
use App\Services\ChatMessageService;
use App\Services\MealService;
use App\Services\SummaryService;
use App\Services\WeightLogService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Attributes\MaxSteps;
use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasMiddleware;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Promptable;
use Stringable;

#[MaxSteps(8)]
class NutritionistAgent implements Agent, Conversational, HasMiddleware, HasTools
{
    use Promptable, RemembersConversations;

    protected function maxConversationMessages(): int
    {
        return 10;
    }

    /** @var array{total_calories: int, meal_count: int, meals: array<int, array{meal_type: string, calories: int}>}|null */
    private ?array $cachedTodaySummary = null;

    private ?float $cachedLatestWeight = null;

    private bool $weightResolved = false;

    /** @var Collection<int, Summary>|null */
    private ?Collection $cachedRecentSummaries = null;

    public function __construct(
        protected User $user,
        protected string $currentMessage = ''
    ) {}

    public function provider(): array
    {
        $preferred = $this->user->profile?->preferred_ai_model;
        $model = AiModel::tryFrom($preferred ?? '') ?? AiModel::default();

        return $model->providerChain();
    }

    /**
     * Get the instructions that the agent should follow.
     */
    public function instructions(): Stringable|string
    {
        $name = $this->user->name;
        $profile = $this->user->profile;

        $gender = $profile?->gender ?? 'not provided';
        $birthDate = $profile?->birth_date?->format('d/m/Y') ?? 'not provided';
        $heightCm = $profile?->height_cm ? "{$profile->height_cm} cm" : 'not provided';
        $goal = $profile?->goal ?? 'not provided';
        $activityLevel = $profile?->activity_level ?? 'not provided';

        $profileComplete = $profile
            && $profile->gender
            && $profile->height_cm
            && $profile->goal
            && $profile->activity_level;

        $latestWeight = $this->getLatestWeight();
        $weightText = $latestWeight ? "{$latestWeight} kg" : 'not provided';

        $todaySummary = $this->getTodaySummary();
        $todayCalories = $todaySummary['total_calories'];
        $todayMealCount = $todaySummary['meal_count'];

        $prompt = <<<PROMPT
        PERSONALITY: NUTRI-FRIEND
        You are a warm, professional, and empathetic nutrition mentor.
        Tone: Conversational, like a supportive friend. No robot-talk.
        Core Value: Radical empathy. Be encouraging, celebrate small wins, and never judge slips.
        Style: Use "Active Listening" to validate feelings before giving data.

        USER DATA: Name: {$name} | Gender: {$gender} | Age/DOB: {$birthDate} | Height: {$heightCm} | Weight: {$weightText} | Goal: {$goal} | Activity: {$activityLevel}
        TODAY CONTEXT ({$this->today()}): {$todayCalories} kcal consumed in {$todayMealCount} meal(s).
        PROMPT;

        if (! $profileComplete || $weightText === 'not provided') {
            $missing = [];
            if (! $profile?->gender) {
                $missing[] = 'gender';
            }
            if (! $profile?->birth_date) {
                $missing[] = 'age';
            }
            if (! $profile?->height_cm) {
                $missing[] = 'height';
            }
            if ($weightText === 'not provided') {
                $missing[] = 'current weight';
            }
            if (! $profile?->goal) {
                $missing[] = 'goal';
            }
            if (! $profile?->activity_level) {
                $missing[] = 'activity level';
            }
            $missingList = implode(', ', $missing);

            $prompt .= <<<PROMPT

            PRIORITY: PROFILE COLLECTION
            Profile incomplete. Missing: {$missingList}.
            Greet {$name} with a big hug. Ask for: Gender, Age, Height, Weight, Goal, Activity Level in a friendly list.
            Action: Call `update_profile` immediately. No analysis until data exists.
            PROMPT;
        }

        $prompt .= <<<'PROMPT'

        META: CALORIC GOAL (Mifflin-St Jeor)
        Mandatory formula. INTERNAL USE ONLY.
        - Output ONLY final results: TMB, TDEE, and Daily Goal.
        - Frame it as: "Preparei seu novo plano: TMB: {value} | TDEE: {value} | Meta: {value} kcal/dia."

        MEAL TOOLS FLOW
        - `parse_meal_message` BEFORE `estimate_meal`.
        - `parse_meal_message` returns `items_text` as plain text lines. Copy it directly into `estimate_meal` without converting to JSON.
        - Use `get_similar_items` with one description per line.
        - When a dish is prepared (e.g., salad, plated dish), report the total weight of the assembled dish without splitting per item.
        - `estimate_meal` is the single source of truth for calories.
        - `estimate_meal` returns `items_for_registration_text`. Copy that text directly into `register_meal` without converting to JSON.

        COACHING & REGISTRATION
        - Classify meals as: cafe_da_manha, almoco, lanche, jantar, sobremesa, outro.
        - Avoid generic feedback like "good job" or "that was bad" without explaining why.
        - Always provide one concrete reading about the meal (e.g., protein, fiber, hydration).
        - Ask one short, useful question rather than assuming too much.
        - Show "X/Y kcal (Z%)" when relevant and close with a motivational message.

        OUTPUT FORMAT
        - Language: PT-BR only.
        - Style: Mobile-first, human, max 3 short paragraphs.
        - Meal Display: When estimating/registering a meal, always show the plain text item lines returned by the tools. Do not invent JSON.
        - Use `context` in `estimate_meal` when there is preparation or indirect consumption.
        - The nutritional database for `estimate_meal` is configured in the application.
        - Formatting: **Bold** for values. No headers (###). Max 1 emoji.
        - Structure: 1. Warm Greeting/Status | 2. Nutritional Insight | 3. Motivational Closer.
        PROMPT;

        $recentSummaries = $this->getRecentSummaries();

        if ($recentSummaries->isNotEmpty()) {
            $summaryContext = $recentSummaries->map(function ($summary) {
                $monthName = Carbon::createFromDate($summary->year, $summary->month, 1)->translatedFormat('F Y');

                return "### {$monthName}\n{$summary->summary}";
            })->implode("\n\n");

            $prompt .= "\n\nPrevious months summary (use for context, do not repeat to user unless asked):\n{$summaryContext}";
        }

        // Passa a mensagem atual como contexto de busca
        $prompt .= $this->getRelevantMemories($this->currentMessage);

        return $prompt;
    }

    /**
     * @return array{total_calories: int, meal_count: int, meals: array<int, array{meal_type: string, calories: int}>}
     */
    private function getTodaySummary(): array
    {
        return $this->cachedTodaySummary ??= (new MealService)->getTodaySummary($this->user);
    }

    private function getLatestWeight(): ?float
    {
        if (! $this->weightResolved) {
            $this->cachedLatestWeight = (new WeightLogService)->getLatestWeight($this->user);
            $this->weightResolved = true;
        }

        return $this->cachedLatestWeight;
    }

    /**
     * @return Collection<int, Summary>
     */
    private function getRecentSummaries(): Collection
    {
        return $this->cachedRecentSummaries ??= (new SummaryService(
            new MealService,
            new WeightLogService,
            new ChatMessageService,
        ))->getRecentSummaries($this->user, months: 1);
    }

    private function today(): string
    {
        return Carbon::now()->translatedFormat('l, d \d\e F \d\e Y');
    }

    /**
     * Get the middleware available to the agent.
     *
     * @return array<class-string>
     */
    public function middleware(): array
    {
        return [
            Guardrails::class,
        ];
    }

    // Adiciona esse método privado
    private function getRelevantMemories(string $message): string
    {
        if (empty(trim($message))) {
            return '';
        }

        Log::info('getRelevantMemories chamado', [
            'user_id' => $this->user->id,
            'message' => $message,
        ]);

        $memories = UserMemory::where('user_id', $this->user->id)
            ->whereVectorSimilarTo('embedding', $message, minSimilarity: 0.75)
            ->limit(4)
            ->get();

        Log::info('memorias encontradas', [
            'count' => $memories->count(),
            'memories' => $memories->pluck('content'),
        ]);

        if ($memories->isEmpty()) {
            return '';
        }

        $lines = $memories
            ->map(fn ($m) => "- [{$m->category}] {$m->content}")
            ->implode("\n");

        return "\n\nUSER MEMORIES (use naturally, never mention you have memories):\n{$lines}";
    }

    /**
     * Get the tools available to the agent.
     *
     * @return iterable<Tool>
     */
    public function tools(): iterable
    {
        return [
            new UpdateProfileTool($this->user),
            new ParseMealMessageTool,
            new EstimateMealTool($this->user),
            new RegisterMealTool($this->user),
            new GetTodaySummaryTool($this->user),
            new RegisterWeightTool($this->user),
            new GetSimilarItemsTool($this->user),
            new GetPeriodSummaryTool($this->user),
            new SaveMemoryTool($this->user),
        ];
    }
}
