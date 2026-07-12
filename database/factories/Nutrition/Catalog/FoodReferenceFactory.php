<?php

namespace Database\Factories\Nutrition\Catalog;

use App\Models\User;
use App\Nutrition\Domain\Catalog\Enums\CatalogVisibility;
use App\Nutrition\Infrastructure\Catalog\Eloquent\FoodReference;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<FoodReference>
 */
class FoodReferenceFactory extends Factory
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
            'stable_key' => 'food-'.Str::lower((string) Str::uuid7()),
            'visibility' => CatalogVisibility::Global,
            'owner_user_id' => null,
            'is_generic' => false,
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

    public function generic(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_generic' => true,
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
