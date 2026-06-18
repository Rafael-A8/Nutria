<?php

namespace App\Ai\Agents;

use App\Ai\Middleware\Guardrails;
use App\Ai\Middleware\InjectMemories;
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
use App\Models\User;
use App\Models\UserConversationSummary;
use App\Services\ChatMessageService;
use App\Services\MealService;
use App\Services\SummaryService;
use App\Services\WeightLogService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Laravel\Ai\Attributes\MaxSteps;
use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasMiddleware;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;
use Stringable;

#[MaxSteps(8)]
class NutritionistAgent implements Agent, Conversational, HasMiddleware, HasProviderOptions, HasTools
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

    /** @var Collection<int, UserConversationSummary>|null */
    private ?Collection $cachedRecentSummaries = null;

    /** @var array{previous_user_message: string, days_since_previous_user_interaction: string, absence_context: string, yesterday_date: string, yesterday_user_message_count: int, yesterday_meal_count: int}|null */
    private ?array $cachedFollowUpContext = null;

    public function __construct(protected User $user) {}

    public function provider(): array
    {
        $preferred = $this->user->profile?->preferred_ai_model;
        $model = AiModel::tryFrom($preferred ?? '') ?? AiModel::default();

        return $model->providerChain();
    }

    /**
     * @return array<string, mixed>
     */
    public function providerOptions(Lab|string $provider): array
    {
        $providerName = $provider instanceof Lab ? $provider->value : $provider;

        if ($providerName !== Lab::Gemini->value) {
            return [];
        }

        return [
            'thinkingConfig' => [
                'thinkingLevel' => 'medium',
            ],
        ];
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
        $followUpContext = $this->getFollowUpContext();

        $prompt = <<<PROMPT
        PERSONALITY: NUTRI-FRIEND
        You are a warm, professional, and empathetic nutrition mentor.
        Tone: Conversational, like a supportive friend. No robot-talk.
        Core Value: Radical empathy. Be encouraging, celebrate small wins, and never judge slips.
        Style: Use "Active Listening" to validate feelings before giving data.

        USER DATA: Name: {$name} | Gender: {$gender} | Age/DOB: {$birthDate} | Height: {$heightCm} | Weight: {$weightText} | Goal: {$goal} | Activity: {$activityLevel}
        TODAY CONTEXT ({$this->today()}): {$todayCalories} kcal consumed in {$todayMealCount} meal(s).
        FOLLOW-UP CONTEXT: Previous user interaction before current message: {$followUpContext['previous_user_message']} | Days since last user interaction before today: {$followUpContext['days_since_previous_user_interaction']} | Absence context: {$followUpContext['absence_context']} | Yesterday ({$followUpContext['yesterday_date']}) user chat messages: {$followUpContext['yesterday_user_message_count']} | Yesterday meal records: {$followUpContext['yesterday_meal_count']}.
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
        - Frame it as: "Preparei seu novo plano: TMB: ... | TDEE: ...| Meta: ... kcal/dia."

        MEAL TOOLS FLOW
        - `parse_meal_message` BEFORE `estimate_meal`.
        - `parse_meal_message` returns `items_text`, `meal_type`, and `consumed_at`. Copy them directly into `estimate_meal` without converting to JSON.
        - Use `get_similar_items` with one description per line.
        - When a dish is prepared (e.g., salad, plated dish), report the total weight of the assembled dish without splitting per item.
        - `estimate_meal` is the single source of truth for calories.
        - `estimate_meal` may use structured fallback estimation for foods outside the internal database. Do not estimate calories yourself.
        - Register only when `estimate_meal` returns `registration_allowed=true`.
        - `estimate_meal` returns `items_for_registration_text`, `consumed_at`, `expected_items_count`, and `pending_items_count`. Copy all four directly into `register_meal` without converting to JSON.
        - If `estimate_meal` returns `clarification_required` or `registration_allowed=false`, ask the suggested clarification and do not call `register_meal`.
        - If `register_meal` returns `registration_blocked`, do not say the meal was registered. Explain briefly that one detail still needs confirmation.

        COACHING & REGISTRATION
        - Classify meals as: cafe_da_manha, almoco, lanche, jantar, sobremesa, outro.
        - Avoid generic feedback like "good job" or "that was bad" without explaining why.
        - Always provide one concrete reading about the meal (e.g., protein, fiber, hydration).
        - Ask one short, useful question rather than assuming too much.
        - Show "X/Y kcal (Z%)" when relevant and close with a motivational message.

        RELATIONSHIP CONTINUITY RAILS
        - Use absence context only when it is not `none`.
        - If absence context is `none`, do not mention absence, missed days, or inactivity.
        - When absence context is present, treat it as a continuity signal, not a scolding cue.
        - If absence context says the user has been away for days, weeks, months, or years, gently acknowledge it once in PT-BR with a warm check-in and invite the user to talk about what happened.
        - If the user is actively registering food, handle the registration first and use at most one short follow-up sentence about the absence.

        CLINICAL COACHING RAILS
        - If the user reports symptoms after eating and a known restriction may relate to the food, explicitly connect the symptom and restriction in a calm, non-alarming way. Do not diagnose. Suggest practical next steps such as hydration, observation, and seeking professional care if symptoms are severe or persistent.
        - If calorie-dense ingredients are present and quantities are uncertain (e.g., condensed milk, dulce de leche, coconut, oils, cream, cheese, nuts, fried foods, sauces, desserts), avoid confident low estimates. Prefer a cautious range or clearly state the estimate is uncertain.
        - When using `estimate_meal`, do not recalculate deterministic items. If the tool reports fallback assumptions, surface the uncertainty in the user response and avoid presenting the total as exact.

        OUTPUT FORMAT
        - Language: PT-BR only.
        - Style: Mobile-first, human, max 3 short paragraphs.
        - Meal Display: When estimating/registering a meal, always show the plain text item lines returned by the tools. Do not invent JSON.
        - Use `context` in `estimate_meal` when there is preparation or indirect consumption.
        - The nutritional database for `estimate_meal` is configured in the application.
        - Formatting: **Bold** for values. No headers (###). Max 1 emoji.
        - Structure: 1. Warm Greeting/Status | 2. Nutritional Insight | 3. Motivational Closer.
        PROMPT;

        $recentSummaries = $this->getRecentConversationSummaries();

        if ($recentSummaries->isNotEmpty()) {
            $summaryContext = $recentSummaries->map(function ($summary) {
                $periodStart = $summary->period_start->toDateString();
                $periodEnd = $summary->period_end->toDateString();

                return "### Cycle from {$periodStart} to {$periodEnd}\n{$summary->summary}";
            })->implode("\n\n");

            $prompt .= "\n\nPrevious conversation cycle summary (use for context, do not repeat to user unless asked):\n{$summaryContext}";
        }

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
     * @return Collection<int, UserConversationSummary>
     */
    private function getRecentConversationSummaries(): Collection
    {
        return $this->cachedRecentSummaries ??= (new SummaryService(
            new MealService,
            new WeightLogService,
            new ChatMessageService,
        ))->getRecentConversationSummaries($this->user, limit: 1);
    }

    /**
     * @return array{previous_user_message: string, days_since_previous_user_interaction: string, absence_context: string, yesterday_date: string, yesterday_user_message_count: int, yesterday_meal_count: int}
     */
    private function getFollowUpContext(): array
    {
        if ($this->cachedFollowUpContext !== null) {
            return $this->cachedFollowUpContext;
        }

        $chatMessageService = new ChatMessageService;
        $mealService = new MealService;
        $now = Carbon::now();
        $previousUserMessage = $chatMessageService->getPreviousUserMessage($this->user);
        $dailyContext = $this->getDailyFollowUpContext($chatMessageService, $mealService, $now);

        return $this->cachedFollowUpContext = array_merge([
            'previous_user_message' => $previousUserMessage
                ? $previousUserMessage->created_at->toDateTimeString().' | "'.Str::of($previousUserMessage->content)->squish()->limit(180, preserveWords: true)->toString().'"'
                : 'none recorded',
        ], $dailyContext);
    }

    /**
     * @return array{days_since_previous_user_interaction: string, absence_context: string, yesterday_date: string, yesterday_user_message_count: int, yesterday_meal_count: int}
     */
    private function getDailyFollowUpContext(ChatMessageService $chatMessageService, MealService $mealService, Carbon $now): array
    {
        $timezone = (string) config('app.timezone');
        $today = $now->copy()->timezone($timezone)->startOfDay();

        return Cache::remember(
            $this->dailyFollowUpCacheKey($today),
            $today->copy()->endOfDay(),
            function () use ($chatMessageService, $mealService, $today): array {
                $yesterday = $today->copy()->subDay();
                $lastUserMessageBeforeToday = $chatMessageService->getLatestUserMessageBefore($this->user, $today);
                $daysSincePreviousUserInteraction = null;

                if ($lastUserMessageBeforeToday?->created_at) {
                    $daysSincePreviousUserInteraction = (int) $lastUserMessageBeforeToday->created_at
                        ->copy()
                        ->timezone($today->getTimezone())
                        ->startOfDay()
                        ->diffInDays($today->copy());
                }

                return [
                    'days_since_previous_user_interaction' => $daysSincePreviousUserInteraction === null ? 'not available' : (string) $daysSincePreviousUserInteraction,
                    'absence_context' => $this->formatAbsenceContext($daysSincePreviousUserInteraction),
                    'yesterday_date' => $yesterday->toDateString(),
                    'yesterday_user_message_count' => $chatMessageService->countUserMessagesForDay($this->user, $yesterday->copy()),
                    'yesterday_meal_count' => $mealService->countMealsForDay($this->user, $yesterday->copy()),
                ];
            },
        );
    }

    private function dailyFollowUpCacheKey(Carbon $today): string
    {
        return "nutritionist-agent:daily-follow-up-context:user:{$this->user->id}:{$today->toDateString()}";
    }

    private function formatAbsenceContext(?int $daysSincePreviousUserInteraction): string
    {
        if ($daysSincePreviousUserInteraction === null) {
            return 'none';
        }

        if ($daysSincePreviousUserInteraction <= 1) {
            return 'none';
        }

        if ($daysSincePreviousUserInteraction < 7) {
            return "User has been away for {$daysSincePreviousUserInteraction} days.";
        }

        if ($daysSincePreviousUserInteraction < 30) {
            $weeks = max(1, (int) round($daysSincePreviousUserInteraction / 7));
            $unit = $weeks === 1 ? 'week' : 'weeks';

            return "User has been away for about {$weeks} {$unit} ({$daysSincePreviousUserInteraction} days).";
        }

        if ($daysSincePreviousUserInteraction < 365) {
            $months = max(1, (int) round($daysSincePreviousUserInteraction / 30));
            $unit = $months === 1 ? 'month' : 'months';

            return "User has been away for about {$months} {$unit} ({$daysSincePreviousUserInteraction} days).";
        }

        $years = max(1, (int) round($daysSincePreviousUserInteraction / 365));
        $unit = $years === 1 ? 'year' : 'years';

        return "User has been away for about {$years} {$unit} ({$daysSincePreviousUserInteraction} days).";
    }

    private function today(): string
    {
        return Carbon::now()->translatedFormat('l, d \d\e F \d\e Y');
    }

    /**
     * Get the middleware available to the agent.
     *
     * @return array<int, class-string|object>
     */
    public function middleware(): array
    {
        return [
            Guardrails::class,
            new InjectMemories($this->user),
        ];
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
