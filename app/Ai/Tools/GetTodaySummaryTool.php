<?php

namespace App\Ai\Tools;

use App\Models\User;
use App\Services\MealService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class GetTodaySummaryTool implements Tool
{
    public function __construct(protected User $user) {}

    /**
     * Get the description of the tool's purpose.
     */
    public function description(): Stringable|string
    {
        return 'Returns the summary of calories consumed by the user today, detailed by meal.';
    }

    /**
     * Get the tool's schema definition.
     *
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): Stringable|string
    {
        $service = new MealService;
        $summary = $service->getTodaySummary($this->user);

        $lines = ["Total de calorias hoje: {$summary['total_calories']} kcal em {$summary['meal_count']} refeição(ões)."];

        foreach ($summary['meals'] as $meal) {
            $lines[] = "- {$meal['meal_type']}: {$meal['calories']} kcal";
        }

        return implode("\n", $lines);
    }
}
