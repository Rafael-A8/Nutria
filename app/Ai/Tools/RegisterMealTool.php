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
        return 'Registra uma refeição com seus itens. Use depois de estimate_meal e envie exatamente os items_for_registration retornados pela estimativa. Separe cada alimento consumido como um item individual com suas calorias efetivamente ingeridas. Ingredientes usados só no preparo devem considerar apenas a fração absorvida/consumida, não o total usado.';
    }

    /**
     * Get the tool's schema definition.
     *
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'meal_type' => $schema->string()->description('Tipo da refeição: cafe_da_manha, almoco, lanche, jantar, sobremesa, outro.')->required(),
            'items' => $schema->array()->items(
                $schema->object([
                    'description' => $schema->string()->description('Descrição do item consumido (ex: coxinha, arroz, feijão). Se houver estimativa por absorção no preparo, deixe isso claro na descrição.')->required(),
                    'quantity_grams' => $schema->integer()->description('Peso em gramas, se informado pelo usuário. Null caso contrário.'),
                    'calories' => $schema->integer()->description('Calorias efetivamente consumidas do item. Para óleo, manteiga e outros ingredientes usados só no preparo, informe apenas a fração absorvida/ingerida.')->required(),
                ])
            )->min(1)->description('Lista de itens consumidos na refeição.')->required(),
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
