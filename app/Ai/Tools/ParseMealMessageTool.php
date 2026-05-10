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
        return 'Transforms a free-text meal message into structured items before estimation. Use before estimate_meal when the user describes the meal in running text. If it returns status clarification_required, ask the suggested question and do not estimate or register yet. If status parsed is returned, use exactly meal_type and items in the estimate_meal call.';
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): Stringable|string
    {
        return json_encode(
            $this->mealMessageParsingService->parse(
                message: $request['message'],
                mealTypeHint: $request['meal_type_hint'] ?? null,
            ),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );
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
}
