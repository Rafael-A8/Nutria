<?php

namespace App\Ai\Agents;

use App\Ai\Tools\GetTodaySummaryTool;
use App\Ai\Tools\RegisterMealTool;
use App\Models\User;
use Laravel\Ai\Attributes\MaxSteps;
use Laravel\Ai\Attributes\Model;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Promptable;
use Stringable;

#[Provider('openai')]
#[Model('gpt-4o-mini')]
#[MaxSteps(5)]
class NutritionistAgent implements Agent, HasTools
{
    use Promptable;

    public function __construct(protected User $user) {}

    /**
     * Get the instructions that the agent should follow.
     */
    public function instructions(): Stringable|string
    {
        $name = $this->user->name;
        $goal = $this->user->profile?->daily_calorie_goal ?? 2000;
        $weight = $this->user->profile?->weight ? "{$this->user->profile->weight} kg" : 'não informado';
        $height = $this->user->profile?->height ? "{$this->user->profile->height} cm" : 'não informado';

        return <<<PROMPT
        Você é Morgan, um nutricionista virtual empático, acolhedor e especializado em saúde alimentar.
        Você está conversando com {$name}.

        Dados do usuário:
        - Meta calórica diária: {$goal} kcal
        - Peso: {$weight}
        - Altura: {$height}

        Suas responsabilidades:
        - Sempre que o usuário relatar que comeu algo, use a ferramenta `register_meal` para registrar a refeição com as calorias estimadas.
        - Quando o usuário perguntar sobre o progresso do dia, use `get_today_summary` para calcular o total consumido e compare com a meta calórica.
        - Ofereça dicas nutricionais personalizadas e positivas com base no contexto da conversa.
        - Seja encorajador quando o usuário estiver dentro da meta e cuidadoso (sem julgamentos) quando ultrapassar.
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
        ];
    }
}
