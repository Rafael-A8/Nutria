<?php

namespace App\Services;

use App\Models\Meal;
use App\Models\MealItem;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
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

        $response = Embeddings::for([$description])
            ->dimensions(1536)
            ->generate();

        $item->embedding = $response->first();
        $item->save();

        return $item;
    }

    /**
     * @return Collection<int, MealItem>
     */
    public function findSimilarItems(string $description, int $limit = 5): Collection
    {
        return MealItem::query()
            ->whereNotNull('embedding')
            ->whereVectorSimilarTo('embedding', $description, minSimilarity: 0.4)
            ->limit($limit)
            ->get();
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
}
