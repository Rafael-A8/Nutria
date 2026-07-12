<?php

namespace Database\Factories\Nutrition\Catalog;

use App\Models\User;
use App\Nutrition\Domain\Catalog\Enums\CatalogVisibility;
use App\Nutrition\Domain\Catalog\Enums\FoodSourceAuthorityStatus;
use App\Nutrition\Domain\Catalog\Enums\FoodSourceKind;
use App\Nutrition\Infrastructure\Catalog\Eloquent\FoodSource;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<FoodSource>
 */
class FoodSourceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'public_id' => (string) Str::uuid7(),
            'visibility' => CatalogVisibility::Global,
            'owner_user_id' => null,
            'kind' => FoodSourceKind::CuratedDataset,
            'authority_status' => FoodSourceAuthorityStatus::Prohibited,
            'title' => fake()->sentence(3),
            'publisher' => null,
            'edition' => null,
            'source_uri' => null,
            'citation' => null,
            'license' => null,
            'checksum_algorithm' => null,
            'checksum' => null,
            'retrieved_at' => null,
            'metadata' => null,
            'archived_at' => null,
            'archived_by_user_id' => null,
            'archive_reason' => null,
            'created_by_user_id' => null,
        ];
    }

    public function privateFor(User $user): static
    {
        return $this->state(fn (array $attributes): array => [
            'visibility' => CatalogVisibility::Private,
            'owner_user_id' => $user->id,
        ]);
    }

    public function eligible(): static
    {
        return $this->state(fn (array $attributes): array => [
            'authority_status' => FoodSourceAuthorityStatus::Eligible,
        ]);
    }

    public function untrusted(): static
    {
        return $this->state(fn (array $attributes): array => [
            'authority_status' => FoodSourceAuthorityStatus::Untrusted,
        ]);
    }

    public function archived(): static
    {
        return $this->state(fn (array $attributes): array => [
            'archived_at' => now(),
            'archive_reason' => 'Archived by factory state.',
        ]);
    }
}
