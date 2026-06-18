<?php

namespace App\Ai\Tools;

use App\Models\User;
use App\Services\MealRegistrationGuardrailService;
use App\Services\MealService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class RegisterMealTool implements Tool
{
    public function __construct(
        protected User $user,
        protected ?MealRegistrationGuardrailService $guardrailService = null,
    ) {
        $this->guardrailService ??= new MealRegistrationGuardrailService;
    }

    /**
     * Get the description of the tool's purpose.
     */
    public function description(): Stringable|string
    {
        return 'Registers a meal after estimation. ALWAYS call `estimate_meal` before this tool. Use the plain text item lines returned by `estimate_meal` and pass consumed_at, expected_items_count, and pending_items_count unchanged. If this tool returns registration_blocked, do not tell the user the meal was registered.';
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
            'consumed_at' => $schema->string()
                ->description('Datetime returned by estimate_meal, originally from parse_meal_message. Required for today/yesterday correctness.')
                ->required(),
            'items' => $schema->string()
                ->description('One item per line. Use: description=...; quantity_grams=...; calories=.... Leave empty values blank. Do not use JSON.')
                ->required(),
            'expected_items_count' => $schema->integer()
                ->description('Expected registration item count returned by estimate_meal.')
                ->required(),
            'pending_items_count' => $schema->integer()
                ->description('Pending item count returned by estimate_meal. Must be 0 to register.')
                ->required(),
        ];
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): Stringable|string
    {
        $items = $this->normalizeItems($request['items'] ?? '');
        $guardrail = $this->guardrailService->validate(
            mealType: (string) $request['meal_type'],
            consumedAt: $request['consumed_at'] ?? null,
            items: $items,
            expectedItemsCount: isset($request['expected_items_count']) ? (int) $request['expected_items_count'] : null,
            pendingItemsCount: isset($request['pending_items_count']) ? (int) $request['pending_items_count'] : 0,
        );

        if (! $guardrail['allowed']) {
            return json_encode([
                'status' => 'registration_blocked',
                'meal_type' => $request['meal_type'],
                'item_count' => count($items),
                'blocking_reasons' => $guardrail['reasons'],
                'summary' => 'Meal registration blocked. Resolve every reason before telling the user the meal was registered.',
            ], JSON_UNESCAPED_UNICODE);
        }

        $service = new MealService;
        $meal = $service->registerMeal($this->user, $request['meal_type'], $guardrail['consumed_at']);

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
            'consumed_at' => $meal->consumed_at->toDateTimeString(),
            'item_count' => $itemCount,
            'total_calories' => $totalCalories,
            'summary' => "Meal ({$request['meal_type']}) registered for {$meal->consumed_at->toDateTimeString()} with {$itemCount} item(s). Total: {$totalCalories} kcal.",
        ], JSON_UNESCAPED_UNICODE);
    }

    /**
     * @return list<array{description: string, quantity_grams: int|null, calories: int|null}>
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
     * @return array{description: string, quantity_grams: int|null, calories: int|null}|null
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
                'calories' => null,
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
                : null,
        ];
    }
}
