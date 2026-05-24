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
        return 'Registers a meal after estimation. ALWAYS call `estimate_meal` before this tool. Use the plain text item lines returned by `estimate_meal` and report calories actually consumed when providing values.';
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
                ->description('One item per line. Use: description=...; quantity_grams=...; calories=.... Leave empty values blank. Do not use JSON.')
                ->required(),
        ];
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): Stringable|string
    {
        $items = $this->normalizeItems($request['items'] ?? '');

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

        return json_encode([
            'status' => 'registered',
            'meal_type' => $request['meal_type'],
            'item_count' => $itemCount,
            'total_calories' => $totalCalories,
            'summary' => "Meal ({$request['meal_type']}) registered with {$itemCount} item(s). Total: {$totalCalories} kcal.",
        ], JSON_UNESCAPED_UNICODE);
    }

    /**
     * @return list<array{description: string, quantity_grams: int|null, calories: int}>
     */
    private function normalizeItems(mixed $items): array
    {
        if (is_array($items)) {
            return array_values(array_filter($items, fn ($item) => is_array($item)));
        }

        if (! is_string($items) || trim($items) === '') {
            return [];
        }

        $lines = preg_split('/\R+/', trim($items)) ?: [];

        return collect($lines)
            ->map(fn (string $line) => $this->parseLineItem($line))
            ->filter(fn (?array $item) => $item !== null)
            ->values()
            ->all();
    }

    /**
     * @return array{description: string, quantity_grams: int|null, calories: int}|null
     */
    private function parseLineItem(string $line): ?array
    {
        $line = trim($line);

        if ($line === '') {
            return null;
        }

        if (! str_contains($line, '=')) {
            return [
                'description' => $line,
                'quantity_grams' => null,
                'calories' => 0,
            ];
        }

        $data = [];

        foreach (explode(';', $line) as $part) {
            [$key, $value] = array_pad(array_map('trim', explode('=', $part, 2)), 2, '');

            if ($key === '') {
                continue;
            }

            $data[$key] = $value;
        }

        if (! isset($data['description'])) {
            return null;
        }

        return [
            'description' => $data['description'],
            'quantity_grams' => isset($data['quantity_grams']) && $data['quantity_grams'] !== ''
                ? (int) $data['quantity_grams']
                : null,
            'calories' => isset($data['calories']) && $data['calories'] !== ''
                ? (int) $data['calories']
                : 0,
        ];
    }
}
