<?php

namespace App\Services;

use App\Models\Meal;
use App\Models\MealItem;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Laravel\Ai\Embeddings;

class MealService
{
    public function registerMeal(User $user, string $mealType, ?Carbon $consumedAt = null): Meal
    {
        return $user->meals()->create([
            'meal_type' => $mealType,
            'consumed_at' => $consumedAt ?? Carbon::now(),
        ]);
    }

    public function addItem(Meal $meal, string $description, ?int $quantityGrams, int $calories): MealItem
    {
        $item = $meal->items()->create([
            'description' => $description,
            'quantity_grams' => $quantityGrams,
            'calories' => $calories,
        ]);

        $response = Embeddings::for([$this->embeddingText($description)])
            ->dimensions(1536)
            ->generate();

        $item->embedding = $response->first();
        $item->save();

        return $item;
    }

    /**
     * Busca itens similares priorizando o histórico do usuário,
     * complementando com itens globais (base nutricional compartilhada).
     *
     * @return Collection<int, MealItem>
     */
    public function findSimilarItems(User $user, string $description, int $limit = 5): Collection
    {
        $embeddingQuery = $this->embeddingText($description);

        $userItems = MealItem::query()
            ->whereHas('meal', fn ($q) => $q->where('user_id', $user->id))
            ->whereNotNull('embedding')
            ->whereVectorSimilarTo('embedding', $embeddingQuery, minSimilarity: 0.4)
            ->limit($limit)
            ->get();

        if ($userItems->count() >= $limit) {
            return $userItems;
        }

        $remaining = $limit - $userItems->count();
        $excludeIds = $userItems->pluck('id')->all();

        $globalItems = MealItem::query()
            ->whereHas('meal', fn ($q) => $q->where('user_id', '!=', $user->id))
            ->whereNotIn('id', $excludeIds)
            ->whereNotNull('embedding')
            ->whereVectorSimilarTo('embedding', $embeddingQuery, minSimilarity: 0.4)
            ->limit($remaining)
            ->get();

        return $userItems->concat($globalItems);
    }

    /**
     * @return array{total_calories: int, meal_count: int, meals: array<int, array{meal_type: string, calories: int}>}
     */
    public function getTodaySummary(User $user): array
    {
        $meals = $user->meals()
            ->with('items')
            ->whereDate('consumed_at', Carbon::today())
            ->get();

        $mealBreakdown = $meals->map(fn (Meal $meal) => [
            'meal_type' => $meal->meal_type,
            'calories' => $meal->items->sum('calories'),
        ])->all();

        return [
            'total_calories' => $meals->flatMap->items->sum('calories'),
            'meal_count' => $meals->count(),
            'meals' => $mealBreakdown,
        ];
    }

    /**
     * @return array<string, int>
     */
    public function getWeekSummary(User $user): array
    {
        $startOfWeek = Carbon::now()->startOfWeek();

        return $user->meals()
            ->with('items')
            ->where('consumed_at', '>=', $startOfWeek)
            ->get()
            ->groupBy(fn (Meal $meal) => $meal->consumed_at->toDateString())
            ->map(fn ($meals) => $meals->flatMap->items->sum('calories'))
            ->all();
    }

    /**
     * @return array{total_calories: int, avg_daily_calories: int, total_meals: int, total_items: int, days_tracked: int, top_items: array<int, array{description: string, count: int, avg_calories: int}>}
     */
    public function getPeriodSummary(User $user, Carbon $startDate, Carbon $endDate): array
    {
        $meals = $user->meals()
            ->with('items')
            ->whereBetween('consumed_at', [$startDate->startOfDay(), $endDate->endOfDay()])
            ->get();

        $allItems = $meals->flatMap->items;
        $totalCalories = $allItems->sum('calories');
        $daysTracked = $meals->groupBy(fn (Meal $meal) => $meal->consumed_at->toDateString())->count();

        $topItems = $allItems
            ->groupBy('description')
            ->map(fn (Collection $items) => [
                'description' => $items->first()->description,
                'count' => $items->count(),
                'avg_calories' => (int) round($items->avg('calories')),
            ])
            ->sortByDesc('count')
            ->take(5)
            ->values()
            ->all();

        return [
            'total_calories' => $totalCalories,
            'avg_daily_calories' => $daysTracked > 0 ? (int) round($totalCalories / $daysTracked) : 0,
            'total_meals' => $meals->count(),
            'total_items' => $allItems->count(),
            'days_tracked' => $daysTracked,
            'top_items' => $topItems,
        ];
    }

    private function embeddingText(string $description): string
    {
        return Str::of($description)
            ->replaceMatches('/\s*\([^)]*\)/', '')
            ->squish()
            ->toString();
    }
}
