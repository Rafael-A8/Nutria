<?php

namespace App\Ai\Tools;

use App\Models\User;
use App\Services\MealEstimationService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class EstimateMealTool implements Tool
{
    public function __construct(
        protected User $user,
        protected ?MealEstimationService $mealEstimationService = null,
    ) {
        $this->mealEstimationService ??= new MealEstimationService;
    }

    /**
     * Get the description of the tool's purpose.
     */
    public function description(): Stringable|string
    {
        return 'Estima refeições de forma determinística antes do registro. Use depois de parse_meal_message quando o usuário relatar alimentos em texto livre. Se retornar status clarification_required, faça a pergunta sugerida e não registre ainda. Se retornar status estimated, use exatamente os items_for_registration na chamada de register_meal e aproveite user_facing_summary, calculation_lines e assistant_response_guide para montar a resposta final.';
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): Stringable|string
    {
        return json_encode(
            $this->mealEstimationService->estimate(
                user: $this->user,
                mealType: $request['meal_type'],
                items: $request['items'],
            ),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'meal_type' => $schema->string()->description('Tipo da refeição: cafe_da_manha, almoco, lanche, jantar, sobremesa, outro.')->required(),
            'items' => $schema->array()->items(
                $schema->object([
                    'description' => $schema->string()->description('Descrição do alimento exatamente como o usuário descreveu.')->required(),
                    'quantity_grams' => $schema->integer()->description('Peso em gramas, quando o usuário informou de forma explícita.'),
                    'quantity_text' => $schema->string()->description('Medida caseira quando o usuário não deu gramas, por exemplo: 2 colheres de sopa, 1 unidade, 200 ml.'),
                    'context' => $schema->string()->description('Contexto de consumo ou preparo quando isso muda a estimativa, por exemplo: usada no preparo do frango, servida por cima, virou molho no prato.'),
                ])
            )->min(1)->description('Itens da refeição a estimar antes do registro.')->required(),
        ];
    }
}
