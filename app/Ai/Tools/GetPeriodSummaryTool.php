<?php

namespace App\Ai\Tools;

use App\Models\User;
use App\Services\MealService;
use App\Services\WeightLogService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Carbon;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class GetPeriodSummaryTool implements Tool
{
    public function __construct(protected User $user) {}

    /**
     * Get the description of the tool's purpose.
     */
    public function description(): Stringable|string
    {
        return 'Returns a detailed summary of the user\'s meals and weight over a specific period. Use when the user asks about a past period (e.g., "how was my January?", "how did I do last week?").';
    }

    /**
     * Get the tool's schema definition.
     *
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'start_date' => $schema->string()->description('Start date in YYYY-MM-DD format')->required(),
            'end_date' => $schema->string()->description('End date in YYYY-MM-DD format')->required(),
        ];
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): Stringable|string
    {
        $startDate = Carbon::parse($request['start_date']);
        $endDate = Carbon::parse($request['end_date']);

        $mealService = new MealService;
        $mealStats = $mealService->getPeriodSummary($this->user, $startDate, $endDate);

        $weightLogService = new WeightLogService;
        $weightStats = $weightLogService->getPeriodWeights($this->user, $startDate, $endDate);

        $lines = [
            "Resumo de {$startDate->format('d/m/Y')} a {$endDate->format('d/m/Y')}:",
            "Calorias totais: {$mealStats['total_calories']} kcal",
            "Média diária: {$mealStats['avg_daily_calories']} kcal",
            "Total de refeições: {$mealStats['total_meals']}",
            "Total de itens: {$mealStats['total_items']}",
            "Dias rastreados: {$mealStats['days_tracked']}",
        ];

        if (! empty($mealStats['top_items'])) {
            $lines[] = 'Alimentos mais frequentes:';
            foreach ($mealStats['top_items'] as $item) {
                $lines[] = "- {$item['description']}: {$item['count']}x (~{$item['avg_calories']} kcal)";
            }
        }

        if ($weightStats['entries'] > 0) {
            $lines[] = "Peso inicial: {$weightStats['start_weight']} kg";
            $lines[] = "Peso final: {$weightStats['end_weight']} kg";
            $lines[] = "Peso mínimo: {$weightStats['min_weight']} kg";
            $lines[] = "Peso máximo: {$weightStats['max_weight']} kg";
        }

        return implode("\n", $lines);
    }
}
