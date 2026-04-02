<?php

namespace App\Ai\Agents;

use App\Ai\Tools\GetPeriodSummaryTool;
use App\Ai\Tools\GetSimilarItemsTool;
use App\Ai\Tools\GetTodaySummaryTool;
use App\Ai\Tools\RegisterMealTool;
use App\Ai\Tools\RegisterWeightTool;
use App\Models\User;
use App\Services\ChatMessageService;
use App\Services\MealService;
use App\Services\SummaryService;
use App\Services\WeightLogService;
use Illuminate\Support\Carbon;
use Laravel\Ai\Attributes\MaxSteps;
use Laravel\Ai\Attributes\Model;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Promptable;
use Stringable;

#[Provider('openai')]
#[Model('gpt-4o-mini')]
#[MaxSteps(5)]
class NutritionistAgent implements Agent, Conversational, HasTools
{
    use Promptable, RemembersConversations;

    public function __construct(protected User $user) {}

    /**
     * Get the instructions that the agent should follow.
     */
    public function instructions(): Stringable|string
    {
        $name = $this->user->name;
        $profile = $this->user->profile;

        $gender = $profile?->gender ?? 'não informado';
        $heightCm = $profile?->height_cm ? "{$profile->height_cm} cm" : 'não informado';
        $goal = $profile?->goal ?? 'não informado';
        $activityLevel = $profile?->activity_level ?? 'não informado';

        $weightLogService = new WeightLogService;
        $latestWeight = $weightLogService->getLatestWeight($this->user);
        $weightText = $latestWeight ? "{$latestWeight} kg" : 'não informado';

        $mealService = new MealService;
        $todaySummary = $mealService->getTodaySummary($this->user);
        $todayCalories = $todaySummary['total_calories'];
        $todayMealCount = $todaySummary['meal_count'];

        $prompt = <<<PROMPT
        Você é Morgan, um nutricionista virtual empático, acolhedor e especializado em saúde alimentar.
        Você está conversando com {$name}.

        Dados do usuário:
        - Gênero: {$gender}
        - Altura: {$heightCm}
        - Peso atual: {$weightText}
        - Objetivo: {$goal}
        - Nível de atividade: {$activityLevel}

        Contexto de hoje:
        - Calorias consumidas hoje: {$todayCalories} kcal em {$todayMealCount} refeição(ões)

        Suas responsabilidades:
        - Antes de estimar calorias de um alimento, use `get_similar_items` para verificar se ele já foi registrado antes. Aproveite os valores históricos como referência.
        - Sempre que o usuário relatar refeições, use `register_meal` para registrar. Inclua o tipo de refeição (cafe_da_manha, almoco, lanche, jantar, sobremesa, outro) e cada item separadamente com suas calorias estimadas.
        - Quando o usuário perguntar sobre o progresso, use `get_today_summary` para obter o resumo atualizado.
        - Quando o usuário informar seu peso, use `register_weight` para registrar.
        - Ofereça dicas nutricionais personalizadas e positivas com base no contexto.
        - Seja encorajador quando o progresso estiver bom e cuidadoso (sem julgamentos) quando ultrapassar metas.
        - Leve em conta a semana inteira nas avaliações — um dia mais pesado com uma semana leve está tudo bem.
        - Quando o usuário perguntar sobre um período passado (ex: "como foi meu janeiro?", "como me saí na última semana?"), use `get_period_summary` para obter os dados detalhados.
        - Responda sempre em português do Brasil de forma clara, humana e motivadora.
        PROMPT;

        $summaryService = new SummaryService(
            new MealService,
            new WeightLogService,
            new ChatMessageService,
        );
        $recentSummaries = $summaryService->getRecentSummaries($this->user);

        if ($recentSummaries->isNotEmpty()) {
            $summaryContext = $recentSummaries->map(function ($summary) {
                $monthName = Carbon::createFromDate($summary->year, $summary->month, 1)->translatedFormat('F Y');

                return "### {$monthName}\n{$summary->summary}";
            })->implode("\n\n");

            $prompt .= "\n\nResumo dos meses anteriores (use para contexto, não repita ao usuário a menos que pergunte):\n{$summaryContext}";
        }

        return $prompt;
    }

    /**
     * Get the tools available to the agent.
     *
     * @return iterable<Tool>
     */
    public function tools(): iterable
    {
        return [
            new RegisterMealTool($this->user),
            new GetTodaySummaryTool($this->user),
            new RegisterWeightTool($this->user),
            new GetSimilarItemsTool,
            new GetPeriodSummaryTool($this->user),
        ];
    }
}
