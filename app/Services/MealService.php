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
}
