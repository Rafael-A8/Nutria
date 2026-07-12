<?php

namespace Database\Factories\Nutrition\Catalog;

use App\Nutrition\Domain\Catalog\Enums\CatalogReviewStatus;
use App\Nutrition\Infrastructure\Catalog\Eloquent\FoodReference;
use App\Nutrition\Infrastructure\Catalog\Eloquent\FoodReferenceVersion;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<FoodReferenceVersion>
 */
class FoodReferenceVersionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $canonicalSuffix = Str::lower((string) Str::uuid7());

        return [
            'public_id' => (string) Str::uuid7(),
            'food_reference_id' => FoodReference::factory(),
            'version_number' => 1,
            'canonical_name' => "Catalog food {$canonicalSuffix}",
            'normalized_canonical_name' => "catalog food {$canonicalSuffix}",
            'locale' => 'pt-BR',
            'classification' => 'food',
            'preparation_key' => null,
            'energy_basis_grams' => null,
            'energy_kcal' => null,
            'nutrient_values' => null,
            'provenance' => null,
            'review_status' => CatalogReviewStatus::Draft,
            'submitted_at' => null,
            'submitted_by_user_id' => null,
            'reviewed_at' => null,
            'reviewed_by_user_id' => null,
            'review_reason' => null,
            'published_at' => null,
            'published_by_user_id' => null,
            'activated_at' => null,
            'activated_by_user_id' => null,
            'deactivated_at' => null,
            'deactivated_by_user_id' => null,
            'deactivation_reason' => null,
            'withdrawn_at' => null,
            'withdrawn_by_user_id' => null,
            'withdrawal_reason' => null,
            'archived_at' => null,
            'archived_by_user_id' => null,
            'archive_reason' => null,
            'supersedes_food_reference_version_id' => null,
            'created_by_user_id' => null,
        ];
    }

    public function withNutrition(): static
    {
        return $this->state(fn (array $attributes): array => [
            'energy_basis_grams' => '100.0000',
            'energy_kcal' => '120.0000',
            'nutrient_values' => ['protein_grams' => 20],
        ]);
    }

    public function approved(): static
    {
        return $this->state(fn (array $attributes): array => [
            'review_status' => CatalogReviewStatus::Approved,
            'reviewed_at' => now(),
        ]);
    }

    public function published(): static
    {
        return $this->approved()->state(fn (array $attributes): array => [
            'published_at' => now(),
        ]);
    }

    public function active(): static
    {
        return $this->published()->withNutrition()->state(fn (array $attributes): array => [
            'activated_at' => now(),
            'deactivated_at' => null,
            'withdrawn_at' => null,
            'archived_at' => null,
        ]);
    }
}
