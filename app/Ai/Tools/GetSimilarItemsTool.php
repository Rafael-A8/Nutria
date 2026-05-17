<?php

namespace App\Ai\Tools;

use App\Models\User;
use App\Services\MealService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class GetSimilarItemsTool implements Tool
{
    public function __construct(protected User $user) {}

    /**
     * Get the description of the tool's purpose.
     */
    public function description(): Stringable|string
    {
        return 'Searches for previous meal items similar to an array of
                descriptions. Call this AFTER parse_meal_message and
                BEFORE estimate_meal. Batch and pass ALL identified food
                items simultaneously in a single call to minimize
                tool calls.';
    }

    /**
     * Get the tool's schema definition.
     *
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'descriptions' => $schema->array()
                ->items($schema->string())
                ->min(1)
                ->description('Array of food descriptions to search for simultaneously.')
                ->required(),
        ];
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): Stringable|string
    {
        $service = new MealService;
        $lines = [];

        foreach ($request['descriptions'] as $description) {
            $items = $service->findSimilarItems($this->user, $description);

            if ($items->isNotEmpty()) {
                foreach ($items as $item) {
                    $grams = $item->quantity_grams ? " ({$item->quantity_grams}g)" : '';
                    $lines[] = "- {$item->description}{$grams}: {$item->calories} kcal";
                }
            }
        }

        if (empty($lines)) {
            return 'Nenhum item similar encontrado no histórico.';
        }

        return "Itens similares encontrados:\n" . implode("\n", $lines);
    }
}
