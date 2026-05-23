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
        return 'Deterministically estimates meals before registration. Use after parse_meal_message when the user describes food in free text. If status clarification_required is returned, ask the suggested question and do not register yet. If status estimated is returned, use exactly the items_for_registration in the register_meal call and leverage user_facing_summary to craft the final response.';
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): Stringable|string
    {
        $result = $this->mealEstimationService->estimate(
            user: $this->user,
            mealType: $request['meal_type'],
            items: $request['items'],
        );

        // Se precisa de clarificação, retorna só isso
        if ($result['status'] === 'clarification_required') {
            return json_encode([
                'status' => $result['status'],
                'clarification_question' => $result['clarification_question'],
                'clarification_reason' => $result['clarification_reason'],
            ], JSON_UNESCAPED_UNICODE);
        }

        // Senão, retorna só o que o agente precisa para continuar
        return json_encode([
            'status' => $result['status'],
            'meal_type' => $result['meal_type'],
            'total_calories' => $result['total_calories'],
            'low_confidence_items' => $result['low_confidence_items'],
            'items_for_registration' => $result['items_for_registration'],
            'user_facing_summary' => $result['user_facing_summary'],
        ], JSON_UNESCAPED_UNICODE);
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'meal_type' => $schema->string()->description('Meal type: cafe_da_manha, almoco, lanche, jantar, sobremesa, outro.')->required(),
            'items' => $schema->array()->items(
                $schema->object([
                    'description' => $schema->string()->description('Food description exactly as described by the user.')->required(),
                    'quantity_grams' => $schema->integer()->description('Weight in grams, if explicitly provided by the user.'),
                    'quantity_text' => $schema->string()->description('Household measure if grams are not provided, e.g.: 2 colheres de sopa, 1 unidade, 200 ml.'),
                    'context' => $schema->string()->description('Consumption or preparation context if it affects estimation, e.g.: usada no preparo do frango, servida por cima, virou molho no prato.'),
                ])
            )->min(1)->description('Items of the meal to estimate before registration.')->required(),
        ];
    }
}
