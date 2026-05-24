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
        return 'Deterministically estimates meals before registration. Use after `parse_meal_message` when the user describes food in free text. If status clarification_required is returned, ask the suggested question and do not register yet. If status estimated is returned, use exactly `items_for_registration_text` in the `register_meal` call and leverage `user_facing_summary` to craft the final response.';
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): Stringable|string
    {
        $items = $this->normalizeItems($request['items'] ?? '');

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
                'items_for_registration_text' => '',
                'low_confidence_items_text' => $this->formatLowConfidenceItemsText($result['low_confidence_items'] ?? []),
                'clarification_question' => $result['clarification_question'],
                'clarification_reason' => $result['clarification_reason'] ?? '',
                'user_facing_summary' => $result['user_facing_summary'] ?? '',
            ], JSON_UNESCAPED_UNICODE);
        }

        // Senão, retorna só o que o agente precisa para continuar
        return json_encode([
            'status' => $result['status'],
            'next_step' => $result['next_step'] ?? 'register_meal',
            'meal_type' => $result['meal_type'] ?? $request['meal_type'],
            'total_calories' => $result['total_calories'] ?? 0,
            'low_confidence_items_text' => $this->formatLowConfidenceItemsText($result['low_confidence_items'] ?? []),
            'items_for_registration_text' => $this->formatItemsForRegistrationText($result['items_for_registration'] ?? []),
            'items_for_registration_count' => count($result['items_for_registration'] ?? []),
            'low_confidence_items_count' => count($result['low_confidence_items'] ?? []),
            'user_facing_summary' => $result['user_facing_summary'] ?? '',
            'assistant_response_guide' => $result['assistant_response_guide'] ?? '',
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
                ->description('One item per line. Use: description=...; quantity_grams=...; quantity_text=...; context=.... Leave empty values blank. Do not use JSON.')
                ->required(),
        ];
    }

    /**
     * @return list<array{description: string, quantity_grams?: int|null, quantity_text?: string|null, context?: string|null}>
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
     * @return array{description: string, quantity_grams: int|null, quantity_text: string|null, context: string|null}|null
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
                'quantity_text' => null,
                'context' => null,
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
            'quantity_text' => $data['quantity_text'] !== '' ? $data['quantity_text'] : null,
            'context' => $data['context'] !== '' ? $data['context'] : null,
        ];
    }

    /**
     * @param  list<array{description: string, quantity_grams: int|null, calories: int}>  $items
     */
    private function formatItemsForRegistrationText(array $items): string
    {
        return collect($items)
            ->map(function (array $item): string {
                return implode('; ', [
                    'description='.$this->stringifyValue($item['description'] ?? ''),
                    'quantity_grams='.$this->stringifyValue($item['quantity_grams'] ?? null),
                    'calories='.$this->stringifyValue($item['calories'] ?? null),
                ]);
            })
            ->implode("\n");
    }

    /**
     * @param  list<array{description: string, quantity_grams: int|null, quantity_text: string|null}>  $items
     */
    private function formatLowConfidenceItemsText(array $items): string
    {
        return collect($items)
            ->map(function (array $item): string {
                return implode('; ', [
                    'description='.$this->stringifyValue($item['description'] ?? ''),
                    'quantity_grams='.$this->stringifyValue($item['quantity_grams'] ?? null),
                    'quantity_text='.$this->stringifyValue($item['quantity_text'] ?? null),
                ]);
            })
            ->implode("\n");
    }

    private function stringifyValue(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        return (string) $value;
    }
}
