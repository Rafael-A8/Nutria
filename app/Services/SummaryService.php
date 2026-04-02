<?php

namespace App\Services;

use App\Models\Summary;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

use function Laravel\Ai\agent;

class SummaryService
{
    public function __construct(
        private MealService $mealService,
        private WeightLogService $weightLogService,
        private ChatMessageService $chatMessageService,
    ) {}

    /**
     * Generate a summary for the previous month if needed.
     * Called on the first message of a new month.
     */
    public function generateIfNeeded(User $user): ?Summary
    {
        $previousMonth = Carbon::now()->subMonth();
        $month = $previousMonth->month;
        $year = $previousMonth->year;

        $existingSummary = $user->summaries()
            ->where('month', $month)
            ->where('year', $year)
            ->first();

        if ($existingSummary) {
            return null;
        }

        $startDate = $previousMonth->copy()->startOfMonth();
        $endDate = $previousMonth->copy()->endOfMonth();

        $mealStats = $this->mealService->getPeriodSummary($user, $startDate, $endDate);

        if ($mealStats['total_meals'] === 0) {
            return null;
        }

        $weightStats = $this->weightLogService->getPeriodWeights($user, $startDate, $endDate);

        $stats = [
            'meals' => $mealStats,
            'weights' => $weightStats,
        ];

        $statsFormatted = $this->formatStatsForAgent($mealStats, $weightStats, $previousMonth);

        $response = agent(
            instructions: 'Você é Morgan, um nutricionista virtual. Gere um resumo curto e motivador (máximo 3 parágrafos) sobre o mês do usuário com base nas estatísticas fornecidas. Mencione destaques, padrões e dê uma dica para o próximo mês. Responda em português do Brasil.',
        )->prompt($statsFormatted);

        $summary = $user->summaries()->create([
            'month' => $month,
            'year' => $year,
            'summary' => $response->text,
            'stats' => $stats,
        ]);

        $this->chatMessageService->storeAssistantMessage($user, $response->text);

        return $summary;
    }

    /**
     * @return Collection<int, Summary>
     */
    public function getRecentSummaries(User $user, int $months = 3): Collection
    {
        return $user->summaries()
            ->orderByDesc('year')
            ->orderByDesc('month')
            ->limit($months)
            ->get();
    }

    /**
     * @param  array{total_calories: int, avg_daily_calories: int, total_meals: int, total_items: int, days_tracked: int, top_items: array<int, array{description: string, count: int, avg_calories: int}>}  $mealStats
     * @param  array{start_weight: ?float, end_weight: ?float, min_weight: ?float, max_weight: ?float, entries: int}  $weightStats
     */
    private function formatStatsForAgent(array $mealStats, array $weightStats, Carbon $month): string
    {
        $monthName = $month->translatedFormat('F Y');

        $lines = [
            "Resumo de {$monthName}:",
            "- Calorias totais: {$mealStats['total_calories']} kcal",
            "- Média diária: {$mealStats['avg_daily_calories']} kcal",
            "- Total de refeições: {$mealStats['total_meals']}",
            "- Total de itens: {$mealStats['total_items']}",
            "- Dias rastreados: {$mealStats['days_tracked']}",
        ];

        if (! empty($mealStats['top_items'])) {
            $lines[] = '- Alimentos mais frequentes:';
            foreach ($mealStats['top_items'] as $item) {
                $lines[] = "  - {$item['description']}: {$item['count']}x (~{$item['avg_calories']} kcal)";
            }
        }

        if ($weightStats['entries'] > 0) {
            $lines[] = "- Peso inicial: {$weightStats['start_weight']} kg";
            $lines[] = "- Peso final: {$weightStats['end_weight']} kg";
            $lines[] = "- Peso mínimo: {$weightStats['min_weight']} kg";
            $lines[] = "- Peso máximo: {$weightStats['max_weight']} kg";
            $lines[] = "- Registros de peso: {$weightStats['entries']}";
        }

        return implode("\n", $lines);
    }
}
