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
       return 'Registers a meal after estimation.
        ALWAYS call estimate_meal before this tool.
        Never register without estimating first.';
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
            'items' => $schema->array()->items(
                $schema->object([
                    'description' => $schema->string()->description('Description of the consumed item (e.g.: coxinha, arroz, feijão). If there is estimation by absorption in preparation, make this clear in the description.')->required(),
                    'quantity_grams' => $schema->integer()->description('Weight in grams, if provided by the user. Null otherwise.'),
                    'calories' => $schema->integer()->description('Calories effectively consumed of the item. For oil, butter, and other ingredients used only in preparation, provide just the absorbed/ingested fraction.')->required(),
                ])
            )->min(1)->description('List of items consumed in the meal.')->required(),
        ];
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): Stringable|string
    {
        $service = new MealService;
        $meal = $service->registerMeal($this->user, $request['meal_type']);

        $totalCalories = 0;

        foreach ($request['items'] as $item) {
            $service->addItem(
                $meal,
                $item['description'],
                $item['quantity_grams'] ?? null,
                $item['calories'],
            );
            $totalCalories += $item['calories'];
        }

        $itemCount = count($request['items']);

        return "Refeição ({$request['meal_type']}) registrada com {$itemCount} item(ns). Total: {$totalCalories} kcal.";
    }
}
