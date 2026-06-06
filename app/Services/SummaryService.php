<?php

namespace App\Services;

use App\Enums\ConversationSummaryTriggerType;
use App\Enums\ConversationSummaryType;
use App\Models\User;
use App\Models\UserConversationSummary;
use Carbon\CarbonInterface;
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
     * Generate a summary for the previous completed conversation cycle if needed.
     */
    public function generateConversationCycleSummaryIfNeeded(User $user): ?UserConversationSummary
    {
        $periodStart = Carbon::now()->subWeek()->startOfWeek(CarbonInterface::MONDAY);
        $periodEnd = Carbon::now()->subWeek()->endOfWeek(CarbonInterface::SUNDAY);
        $summaryType = ConversationSummaryType::ConversationCycle;
        $triggerType = ConversationSummaryTriggerType::Weekly;

        $existingSummary = $user->conversationSummaries()
            ->where('summary_type', $summaryType->value)
            ->where('trigger_type', $triggerType->value)
            ->where('period_start', $periodStart)
            ->where('period_end', $periodEnd)
            ->first();

        if ($existingSummary) {
            return null;
        }

        $mealStats = $this->mealService->getPeriodSummary($user, $periodStart->copy(), $periodEnd->copy());

        if ($mealStats['total_meals'] === 0) {
            return null;
        }

        $weightStats = $this->weightLogService->getPeriodWeights($user, $periodStart->copy(), $periodEnd->copy());

        $stats = [
            'meals' => $mealStats,
            'weights' => $weightStats,
        ];

        $statsFormatted = $this->formatCycleStatsForAgent($mealStats, $weightStats, $periodStart, $periodEnd);

        $response = agent(
            instructions: 'Generate an internal nutrition summary in English for one completed conversation cycle. Use no more than 4 short paragraphs. Highlight patterns, difficulties, progress, and one light orientation for the next cycle. Do not address the user directly.',
        )->prompt($statsFormatted);

        $summary = $user->conversationSummaries()->create([
            'summary_type' => $summaryType,
            'trigger_type' => $triggerType,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'summary' => $response->text,
            'stats' => $stats,
        ]);

        $this->chatMessageService->storeAssistantMessage($user, $response->text);

        return $summary;
    }

    /**
     * @return Collection<int, UserConversationSummary>
     */
    public function getRecentConversationSummaries(
        User $user,
        int $limit = 1,
        ConversationSummaryType $summaryType = ConversationSummaryType::ConversationCycle,
    ): Collection {
        return $user->conversationSummaries()
            ->where('summary_type', $summaryType->value)
            ->orderByDesc('period_end')
            ->orderByDesc('period_start')
            ->limit($limit)
            ->get();
    }

    /**
     * @param  array{total_calories: int, avg_daily_calories: int, total_meals: int, total_items: int, days_tracked: int, top_items: array<int, array{description: string, count: int, avg_calories: int}>}  $mealStats
     * @param  array{start_weight: ?float, end_weight: ?float, min_weight: ?float, max_weight: ?float, entries: int}  $weightStats
     */
    private function formatCycleStatsForAgent(
        array $mealStats,
        array $weightStats,
        Carbon $periodStart,
        Carbon $periodEnd,
    ): string {
        $lines = [
            "Conversation cycle from {$periodStart->toDateString()} to {$periodEnd->toDateString()}:",
            "- Total calories: {$mealStats['total_calories']} kcal",
            "- Daily average: {$mealStats['avg_daily_calories']} kcal",
            "- Total meals: {$mealStats['total_meals']}",
            "- Total items: {$mealStats['total_items']}",
            "- Tracked days: {$mealStats['days_tracked']}",
        ];

        if (! empty($mealStats['top_items'])) {
            $lines[] = '- Most frequent foods:';
            foreach ($mealStats['top_items'] as $item) {
                $lines[] = "  - {$item['description']}: {$item['count']}x (~{$item['avg_calories']} kcal)";
            }
        }

        if ($weightStats['entries'] > 0) {
            $lines[] = "- Start weight: {$weightStats['start_weight']} kg";
            $lines[] = "- End weight: {$weightStats['end_weight']} kg";
            $lines[] = "- Minimum weight: {$weightStats['min_weight']} kg";
            $lines[] = "- Maximum weight: {$weightStats['max_weight']} kg";
            $lines[] = "- Weight entries: {$weightStats['entries']}";
        }

        return implode("\n", $lines);
    }
}
