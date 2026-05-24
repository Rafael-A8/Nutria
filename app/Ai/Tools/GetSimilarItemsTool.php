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
    public function __construct(
        protected User $user,
        protected ?MealService $mealService = null,
    ) {
        $this->mealService ??= new MealService;
    }

    /**
     * Get the description of the tool's purpose.
     */
    public function description(): Stringable|string
    {
        return 'Searches for previous meal items similar to plain text descriptions. Call this AFTER `parse_meal_message` and BEFORE `estimate_meal`. Pass one description per line to avoid nested structures.';
    }

    /**
     * Get the tool's schema definition.
     *
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'descriptions' => $schema->string()
                ->description('One food description per line. Do not use JSON or arrays.')
                ->required(),
        ];
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): Stringable|string
    {
        $lines = [];

        foreach ($this->normalizeDescriptions($request['descriptions'] ?? '') as $description) {
            $items = $this->mealService->findSimilarItems($this->user, $description);

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

        return "Itens similares encontrados:\n".implode("\n", $lines);
    }

    /**
     * @return list<string>
     */
    private function normalizeDescriptions(mixed $descriptions): array
    {
        if (is_array($descriptions)) {
            return array_values(array_filter(array_map('strval', $descriptions), fn (string $value) => trim($value) !== ''));
        }

        if (! is_string($descriptions) || trim($descriptions) === '') {
            return [];
        }

        return collect(preg_split('/\R+/', trim($descriptions)) ?: [])
            ->map(fn (string $line) => trim($line))
            ->filter(fn (string $line) => $line !== '')
            ->values()
            ->all();
    }
}
