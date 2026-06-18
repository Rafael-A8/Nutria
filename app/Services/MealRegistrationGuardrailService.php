<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class MealRegistrationGuardrailService
{
    /** @var list<string> */
    private const ALLOWED_MEAL_TYPES = [
        'cafe_da_manha',
        'almoco',
        'lanche',
        'jantar',
        'sobremesa',
        'outro',
    ];

    /**
     * @param  list<array{description: string, quantity_grams: int|null, calories: int|null}>  $items
     * @return array{allowed: bool, reasons: list<string>, consumed_at: Carbon|null}
     */
    public function validate(
        string $mealType,
        ?string $consumedAt,
        array $items,
        ?int $expectedItemsCount,
        int $pendingItemsCount,
    ): array {
        $reasons = [];

        if (! in_array($mealType, self::ALLOWED_MEAL_TYPES, true)) {
            $reasons[] = 'Invalid meal_type. Use one of: '.implode(', ', self::ALLOWED_MEAL_TYPES).'.';
        }

        if ($expectedItemsCount === null || $expectedItemsCount <= 0) {
            $reasons[] = 'expected_items_count is required and must be greater than zero.';
        }

        if ($pendingItemsCount > 0) {
            $reasons[] = "There are {$pendingItemsCount} pending item(s). Resolve every extracted item before registration.";
        }

        if ($items === []) {
            $reasons[] = 'No items were provided for registration.';
        }

        if ($expectedItemsCount !== null && $expectedItemsCount > 0 && count($items) !== $expectedItemsCount) {
            $reasons[] = "Registration item count mismatch. Expected {$expectedItemsCount}, received ".count($items).'.';
        }

        foreach ($items as $index => $item) {
            $position = $index + 1;

            if (Str::of($item['description'] ?? '')->squish()->toString() === '') {
                $reasons[] = "Item {$position} is missing a description.";
            }

            if (($item['calories'] ?? null) === null || ($item['calories'] ?? 0) <= 0) {
                $reasons[] = "Item {$position} is missing a positive calorie estimate.";
            }
        }

        $parsedConsumedAt = $this->parseConsumedAt($consumedAt);

        if ($parsedConsumedAt === null) {
            $reasons[] = 'consumed_at is required and must be a valid datetime from parse_meal_message.';
        } elseif ($parsedConsumedAt->greaterThan(Carbon::now($this->timezone())->addMinutes(5))) {
            $reasons[] = 'consumed_at cannot be in the future.';
        }

        return [
            'allowed' => $reasons === [],
            'reasons' => $reasons,
            'consumed_at' => $parsedConsumedAt,
        ];
    }

    private function parseConsumedAt(?string $consumedAt): ?Carbon
    {
        if ($consumedAt === null || trim($consumedAt) === '') {
            return null;
        }

        try {
            return Carbon::parse($consumedAt, $this->timezone())->timezone($this->timezone());
        } catch (\Throwable) {
            return null;
        }
    }

    private function timezone(): string
    {
        return (string) config('app.timezone');
    }
}
