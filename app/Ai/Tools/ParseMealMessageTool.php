<?php

namespace App\Ai\Tools;

use App\Services\MealMessageParsingService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class ParseMealMessageTool implements Tool
{
    public function __construct(protected ?MealMessageParsingService $mealMessageParsingService = null)
    {
        $this->mealMessageParsingService ??= new MealMessageParsingService;
    }

    /**
     * Get the description of the tool's purpose.
     */
    public function description(): Stringable|string
    {
        return 'Transforms a free-text meal message into plain text item lines before estimation. Use before `estimate_meal` when the user describes the meal in running text. If it returns status clarification_required, ask the suggested question and do not estimate or register yet. If status parsed is returned, use exactly meal_type and items_text in the `estimate_meal` call.';
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): Stringable|string
    {
        $result = $this->mealMessageParsingService->parse(
            message: $request['message'],
            mealTypeHint: $request['meal_type_hint'] ?? null,
        );

        if ($result['status'] === 'clarification_required') {
            return json_encode([
                'status' => $result['status'],
                'next_step' => $result['next_step'] ?? 'ask_for_clarification',
                'items_text' => $this->formatItemsText($result['items'] ?? []),
                'clarification_question' => $result['clarification_question'],
                'clarification_reason' => $result['clarification_reason'],
                'meal_total_quantity_grams' => $result['meal_total_quantity_grams'] ?? null,
            ], JSON_UNESCAPED_UNICODE);
        }

        return json_encode([
            'status' => $result['status'],
            'meal_type' => $result['meal_type'],
            'next_step' => $result['next_step'],
            'items_text' => $this->formatItemsText($result['items'] ?? []),
            'items_count' => count($result['items'] ?? []),
            'meal_total_quantity_grams' => $result['meal_total_quantity_grams'] ?? null,
            'user_facing_summary' => $result['user_facing_summary'] ?? '',
            'assistant_response_guide' => $result['assistant_response_guide'] ?? '',
            'clarification_question' => $result['clarification_question'],
            'clarification_reason' => $result['clarification_reason'],
        ], JSON_UNESCAPED_UNICODE);
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'message' => $schema->string()->description('Raw user message describing the meal.')->required(),
            'meal_type_hint' => $schema->string()->description('Optional meal type hint if the context already makes it clear: cafe_da_manha, almoco, lanche, jantar, sobremesa, outro.'),
        ];
    }

    /**
     * @param  list<array{description: string, quantity_grams: int|null, quantity_text: string|null, context: string|null}>  $items
     */
    private function formatItemsText(array $items): string
    {
        return collect($items)
            ->map(function (array $item): string {
                return implode('; ', [
                    'description='.$this->stringifyValue($item['description'] ?? ''),
                    'quantity_grams='.$this->stringifyValue($item['quantity_grams'] ?? null),
                    'quantity_text='.$this->stringifyValue($item['quantity_text'] ?? null),
                    'context='.$this->stringifyValue($item['context'] ?? null),
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
