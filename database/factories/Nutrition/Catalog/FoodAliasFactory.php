<?php

namespace Database\Factories\Nutrition\Catalog;

use App\Nutrition\Domain\Catalog\Enums\CatalogReviewStatus;
use App\Nutrition\Infrastructure\Catalog\Eloquent\FoodAlias;
use App\Nutrition\Infrastructure\Catalog\Eloquent\FoodReference;
use App\Nutrition\Infrastructure\Catalog\Eloquent\FoodSource;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<FoodAlias>
 */
class FoodAliasFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $aliasSuffix = Str::lower((string) Str::uuid7());

        return [
            'public_id' => (string) Str::uuid7(),
            'lineage_id' => (string) Str::uuid7(),
            'food_reference_id' => FoodReference::factory(),
            'revision_number' => 1,
            'supersedes_food_alias_id' => null,
            'display_alias' => "Catalog alias {$aliasSuffix}",
            'normalized_alias' => "catalog alias {$aliasSuffix}",
            'locale' => 'pt-BR',
            'alias_kind' => 'common',
            'food_source_id' => null,
            'source_record_key' => null,
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
            'created_by_user_id' => null,
        ];
    }

    public function withSource(): static
    {
        return $this->state(fn (array $attributes): array => [
            'food_source_id' => FoodSource::factory(),
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
        return $this->published()->state(fn (array $attributes): array => [
            'activated_at' => now(),
            'deactivated_at' => null,
            'withdrawn_at' => null,
            'archived_at' => null,
        ]);
    }
}
