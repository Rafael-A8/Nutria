<?php

namespace App\Ai\Tools;

use App\Services\MealMessageParsingService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class ParseMealMessageTool implements Tool
{
    public function __construct(protected ?MealMessageParsingService $mealMessageParsingService = null)
    {
        $this->mealMessageParsingService ??= new MealMessageParsingService;
    }

    /**
     * Get the description of the tool's purpose.
     */
    public function description(): Stringable|string
    {
        return 'Transforma uma mensagem livre sobre refeição em itens estruturados antes da estimativa. Use antes de estimate_meal quando o usuário descrever a refeição em texto corrido. Se retornar status clarification_required, faça a pergunta sugerida e não estime nem registre ainda. Se retornar status parsed, use exatamente meal_type e items na chamada de estimate_meal.';
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): Stringable|string
    {
        return json_encode(
            $this->mealMessageParsingService->parse(
                message: $request['message'],
                mealTypeHint: $request['meal_type_hint'] ?? null,
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
            'message' => $schema->string()->description('Mensagem bruta do usuário descrevendo a refeição.')->required(),
            'meal_type_hint' => $schema->string()->description('Dica opcional de tipo de refeição quando o contexto já deixar isso claro: cafe_da_manha, almoco, lanche, jantar, sobremesa, outro.'),
        ];
    }
}
