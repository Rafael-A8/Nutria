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
        return 'Searches for previous meal items similar to a description. Use to check calories of previously logged foods before estimating.';
    }

    /**
     * Get the tool's schema definition.
     *
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'description' => $schema->string()->description('Description of the food to search for (e.g.: coxinha, arroz com feijão).')->required(),
        ];
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): Stringable|string
    {
        $service = new MealService;
        $items = $service->findSimilarItems($this->user, $request['description']);

        if ($items->isEmpty()) {
            return 'Nenhum item similar encontrado no histórico.';
        }

        $lines = ['Itens similares encontrados:'];

        foreach ($items as $item) {
            $grams = $item->quantity_grams ? " ({$item->quantity_grams}g)" : '';
            $lines[] = "- {$item->description}{$grams}: {$item->calories} kcal";
        }

        return implode("\n", $lines);
    }
}
