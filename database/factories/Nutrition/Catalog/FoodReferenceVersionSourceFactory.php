<?php

namespace Database\Factories\Nutrition\Catalog;

use App\Nutrition\Domain\Catalog\Enums\FoodReferenceVersionSourceRole;
use App\Nutrition\Infrastructure\Catalog\Eloquent\FoodReferenceVersion;
use App\Nutrition\Infrastructure\Catalog\Eloquent\FoodReferenceVersionSource;
use App\Nutrition\Infrastructure\Catalog\Eloquent\FoodSource;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FoodReferenceVersionSource>
 */
class FoodReferenceVersionSourceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'food_reference_version_id' => FoodReferenceVersion::factory(),
            'food_source_id' => FoodSource::factory(),
            'role' => FoodReferenceVersionSourceRole::Supporting,
            'source_record_key' => null,
            'evidence_metadata' => null,
            'created_by_user_id' => null,
        ];
    }

    public function primary(): static
    {
        return $this->state(fn (array $attributes): array => [
            'role' => FoodReferenceVersionSourceRole::Primary,
        ]);
    }
}
