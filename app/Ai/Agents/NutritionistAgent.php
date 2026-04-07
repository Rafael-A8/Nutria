<?php

namespace App\Ai\Agents;

use App\Ai\Tools\GetPeriodSummaryTool;
use App\Ai\Tools\GetSimilarItemsTool;
use App\Ai\Tools\GetTodaySummaryTool;
use App\Ai\Tools\RegisterMealTool;
use App\Ai\Tools\RegisterWeightTool;
use App\Ai\Tools\UpdateProfileTool;
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
        $birthDate = $profile?->birth_date?->format('d/m/Y') ?? 'não informado';
        $heightCm = $profile?->height_cm ? "{$profile->height_cm} cm" : 'não informado';
        $goal = $profile?->goal ?? 'não informado';
        $activityLevel = $profile?->activity_level ?? 'não informado';

        $profileComplete = $profile
            && $profile->gender
            && $profile->height_cm
            && $profile->goal
            && $profile->activity_level;

        $weightLogService = new WeightLogService;
        $latestWeight = $weightLogService->getLatestWeight($this->user);
        $weightText = $latestWeight ? "{$latestWeight} kg" : 'não informado';

        $mealService = new MealService;
        $todaySummary = $mealService->getTodaySummary($this->user);
        $todayCalories = $todaySummary['total_calories'];
        $todayMealCount = $todaySummary['meal_count'];

        $prompt = <<<PROMPT
        Você é Nutri, a nutricionista virtual do app Nutria. Você é empática, acolhedora, motivadora e especializada em saúde alimentar e emagrecimento saudável.
        Você está conversando com {$name}.

        Dados do usuário:
        - Gênero: {$gender}
        - Data de nascimento: {$birthDate}
        - Altura: {$heightCm}
        - Peso atual: {$weightText}
        - Objetivo: {$goal}
        - Nível de atividade: {$activityLevel}

        Contexto de hoje ({$this->today()}):
        - Calorias consumidas hoje: {$todayCalories} kcal em {$todayMealCount} refeição(ões)

        PROMPT;

        if (! $profileComplete || $weightText === 'não informado') {
            $missing = [];
            if (! $profile?->gender) {
                $missing[] = 'gênero';
            }
            if (! $profile?->birth_date) {
                $missing[] = 'data de nascimento (ou idade)';
            }
            if (! $profile?->height_cm) {
                $missing[] = 'altura';
            }
            if ($weightText === 'não informado') {
                $missing[] = 'peso atual';
            }
            if (! $profile?->goal) {
                $missing[] = 'objetivo (perder peso, manter, ganhar massa)';
            }
            if (! $profile?->activity_level) {
                $missing[] = 'nível de atividade física';
            }
            $missingList = implode(', ', $missing);

            $prompt .= <<<PROMPT

            AÇÃO PRIORITÁRIA — COLETA DE PERFIL:
            O perfil de {$name} está incompleto. Faltam: {$missingList}.
            Na PRIMEIRA mensagem, cumprimente {$name} de forma acolhedora e peça essas informações de forma natural e conversacional.
            Exemplo: "Olá {$name}! 💚 Para que eu possa te ajudar da melhor forma, preciso conhecer você melhor. Me conta: qual seu gênero, idade, altura, peso atual, seu objetivo e nível de atividade física?"
            Quando o usuário responder, use `update_profile` para salvar IMEDIATAMENTE. Se ele informar o peso, inclua weight_kg na chamada.
            Não prossiga com análises calóricas até ter pelo menos: altura, peso, gênero e objetivo.

            PROMPT;
        }

        $prompt .= <<<'PROMPT'

        REGRAS DE CÁLCULO CALÓRICO:
        - Quando o usuário informar alimentos COM peso em gramas (ex: "arroz 70g", "frango 150g"), calcule as calorias com base em tabelas nutricionais padrão (TACO/USDA). NUNCA invente valores.
        - Referências calóricas por 100g (use como base, ajuste pela gramagem informada):
          * Arroz branco cozido: ~130 kcal/100g
          * Feijão carioca cozido: ~77 kcal/100g
          * Peito de frango grelhado: ~165 kcal/100g
          * Coxa/sobrecoxa de frango: ~190 kcal/100g
          * Carne bovina magra: ~170 kcal/100g
          * Ovo cozido (unidade ~50g): ~78 kcal
          * Farinha de mandioca: ~360 kcal/100g
          * Banana (unidade ~100g): ~89 kcal
          * Batata doce cozida: ~86 kcal/100g
        - Quando o alimento for industrializado com marca (ex: "sorvete Nestlé 85g"), use valores típicos da marca/categoria.
        - Quando NÃO houver peso informado, estime uma porção padrão e informe qual porção assumiu.
        - Antes de estimar, use `get_similar_items` para verificar o histórico.
        - Sempre mostre o cálculo ao usuário: "Arroz 70g → 70g × 130kcal/100g = ~91 kcal".

        REGRAS DE REGISTRO DE REFEIÇÕES:
        - Sempre que o usuário relatar alimentos, use `register_meal` para registrar.
        - Classifique automaticamente o tipo de refeição pelo contexto/horário: cafe_da_manha, almoco, lanche, jantar, sobremesa, outro.
        - Separe cada alimento como um item individual.
        - Inclua quantity_grams quando o usuário informar o peso.
        - Se o usuário listar vários alimentos de uma vez, registre tudo numa única chamada de register_meal.

        REGRAS DE ACOMPANHAMENTO E CONSELHOS:
        - Calcule a meta calórica diária com base nos dados do perfil (fórmula de Harris-Benedict ou Mifflin-St Jeor).
        - Para perda de peso: sugira um déficit de 300-500 kcal/dia a partir do TDEE.
        - Sempre contextualize: "Você consumiu X de Y kcal hoje (Z%)".
        - Dê dicas práticas e positivas: substituições inteligentes, hidratação, distribuição de refeições.
        - Seja encorajador quando o progresso estiver bom e cuidadoso (sem julgamentos) quando ultrapassar metas.
        - Leve em conta a semana inteira — um dia mais pesado com uma semana leve está tudo bem.
        - Quando o usuário perguntar sobre um período passado, use `get_period_summary`.
        - Quando o usuário informar peso, use `register_weight`.
        - Quando o usuário perguntar sobre o progresso de hoje, use `get_today_summary`.

        FORMATO DE RESPOSTA:
        - Responda sempre em português do Brasil, de forma clara, humana e motivadora.
        - Use emojis com moderação para tornar a conversa mais leve (💚, 🥗, 💪, ✅).
        - Seja breve e direto — sem textos longos demais. Máximo 2-3 parágrafos por resposta.
        - Após registrar uma refeição, mostre um resumo rápido do que foi registrado e o total do dia até agora.
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

    private function today(): string
    {
        return Carbon::now()->translatedFormat('l, d \d\e F \d\e Y');
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
            new RegisterMealTool($this->user),
            new GetTodaySummaryTool($this->user),
            new RegisterWeightTool($this->user),
            new GetSimilarItemsTool($this->user),
            new GetPeriodSummaryTool($this->user),
        ];
    }
}
