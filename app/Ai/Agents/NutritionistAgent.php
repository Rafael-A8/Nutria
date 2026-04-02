<?php

namespace App\Ai\Agents;

use App\Ai\Tools\GetTodaySummaryTool;
use App\Ai\Tools\RegisterMealTool;
use App\Ai\Tools\RegisterWeightTool;
use App\Models\User;
use App\Services\MealService;
use App\Services\WeightLogService;
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

        return <<<PROMPT
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
        - Sempre que o usuário relatar refeições, use `register_meal` para registrar. Inclua o tipo de refeição (cafe_da_manha, almoco, lanche, jantar, sobremesa, outro) e cada item separadamente com suas calorias estimadas.
        - Quando o usuário perguntar sobre o progresso, use `get_today_summary` para obter o resumo atualizado.
        - Quando o usuário informar seu peso, use `register_weight` para registrar.
        - Ofereça dicas nutricionais personalizadas e positivas com base no contexto.
        - Seja encorajador quando o progresso estiver bom e cuidadoso (sem julgamentos) quando ultrapassar metas.
        - Leve em conta a semana inteira nas avaliações — um dia mais pesado com uma semana leve está tudo bem.
        - Responda sempre em português do Brasil de forma clara, humana e motivadora.
        PROMPT;
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
        ];
    }
}
