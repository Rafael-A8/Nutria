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
        $items = is_string($request['items']) ? json_decode($request['items'], true) : $request['items'];
        $items = is_array($items) ? $items : [];

        $result = $this->mealEstimationService->estimate(
            user: $this->user,
            mealType: $request['meal_type'],
            items: $items,
        );

        // Se precisa de clarificação, retorna só isso
        if ($result['status'] === 'clarification_required') {
            return json_encode([
                'status' => $result['status'],
                'next_step' => 'ask_for_clarification',
                'clarification_question' => $result['clarification_question'],
                'clarification_reason' => $result['clarification_reason'] ?? '',
            ], JSON_UNESCAPED_UNICODE);
        }

        // Senão, retorna só o que o agente precisa para continuar
        return json_encode([
            'status' => $result['status'],
            'next_step' => $result['next_step'] ?? 'register_meal',
            'meal_type' => $result['meal_type'] ?? $request['meal_type'],
            'total_calories' => $result['total_calories'] ?? 0,
            'low_confidence_items' => $result['low_confidence_items'] ?? [],
            'items_for_registration' => $result['items_for_registration'] ?? [],
            'user_facing_summary' => $result['user_facing_summary'] ?? '',
        ], JSON_UNESCAPED_UNICODE);
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'meal_type' => $schema->string()->description('Meal type: cafe_da_manha, almoco, lanche, jantar, sobremesa, outro.')->required(),
            'items' => $schema->string()
                ->description('JSON formatted array of objects. Each object MUST contain EXACTLY: "description" (string, required), "quantity_grams" (integer, optional), "quantity_text" (string, optional), "context" (string, optional). Serialize this array as a JSON string.')
                ->required(),
        ];
    }
}
