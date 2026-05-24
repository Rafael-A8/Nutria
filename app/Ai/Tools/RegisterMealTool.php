<?php

namespace App\Ai\Tools;

use App\Models\User;
use App\Services\MealService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class RegisterMealTool implements Tool
{
    public function __construct(protected User $user) {}

    /**
     * Get the description of the tool's purpose.
     */
    public function description(): Stringable|string
    {
        return 'Registers a meal after estimation. ALWAYS call `estimate_meal` before this tool. Report "calories actually consumed" when providing values and, when applicable, the "fraction absorbed/consumed" for ingredients used only in preparation.';
    }

    /**
     * Get the tool's schema definition.
     *
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'meal_type' => $schema->string()->description('Meal type: cafe_da_manha, almoco, lanche, jantar, sobremesa, outro.')->required(),
            'items' => $schema->string()
                ->description('JSON formatted array of objects. Each object MUST contain EXACTLY: "description" (string, required, described item), "quantity_grams" (integer, optional, weight), "calories" (integer, required, consumed calories). Do NOT use nested objects in the tool call directly, serialize this array as a JSON string.')
                ->required(),
        ];
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): Stringable|string
    {
        $items = is_string($request['items']) ? json_decode($request['items'], true) : $request['items'];
        $items = is_array($items) ? $items : [];

        $service = new MealService;
        $meal = $service->registerMeal($this->user, $request['meal_type']);

        $totalCalories = 0;

        foreach ($items as $item) {
            $calories = isset($item['calories']) ? (int) $item['calories'] : 0;
            $service->addItem(
                $meal,
                $item['description'] ?? 'Item sem descrição',
                $item['quantity_grams'] ?? null,
                $calories,
            );
            $totalCalories += $calories;
        }

        $itemCount = count($items);

        return "Refeição ({$request['meal_type']}) registrada com {$itemCount} item(ns). Total: {$totalCalories} kcal.";
    }
}
