<?php

namespace Database\Factories\Nutrition\Catalog;

use App\Models\User;
use App\Nutrition\Domain\Catalog\Lifecycle\Enums\CatalogLifecycleOperation;
use App\Nutrition\Domain\Catalog\Lifecycle\Enums\CatalogLifecycleOutcome;
use App\Nutrition\Domain\Catalog\Lifecycle\Enums\CatalogLifecycleReason;
use App\Nutrition\Domain\Catalog\Lifecycle\Enums\CatalogLifecycleState;
use App\Nutrition\Domain\Catalog\Lifecycle\Enums\CatalogLifecycleSubjectType;
use App\Nutrition\Infrastructure\Catalog\Eloquent\CatalogLifecycleEvent;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<CatalogLifecycleEvent>
 */
class CatalogLifecycleEventFactory extends Factory
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
            'subject_type' => CatalogLifecycleSubjectType::ReferenceVersion,
            'subject_id' => fake()->numberBetween(1, 100000),
            'subject_public_id' => (string) Str::uuid7(),
            'event_type' => CatalogLifecycleOperation::SubmitForReview,
            'outcome' => CatalogLifecycleOutcome::Succeeded,
            'reason_code' => CatalogLifecycleReason::TransitionApplied,
            'reason' => null,
            'previous_state' => CatalogLifecycleState::Draft,
            'next_state' => CatalogLifecycleState::PendingReview,
            'eligibility_reasons' => null,
            'actor_user_id' => null,
            'actor_reference' => 'audit:test-actor',
            'metadata' => null,
            'occurred_at' => now(),
            'idempotency_key' => (string) Str::uuid7(),
            'command_fingerprint' => hash('sha256', (string) Str::uuid7()),
            'correlation_id' => (string) Str::uuid7(),
            'transaction_id' => (string) Str::uuid7(),
        ];
    }

    public function derived(): static
    {
        return $this->state(fn (array $attributes): array => [
            'idempotency_key' => null,
            'command_fingerprint' => null,
        ]);
    }

    public function noOp(): static
    {
        return $this->state(fn (array $attributes): array => [
            'outcome' => CatalogLifecycleOutcome::NoOp,
            'reason_code' => CatalogLifecycleReason::AlreadyPendingReview,
            'previous_state' => CatalogLifecycleState::PendingReview,
            'next_state' => CatalogLifecycleState::PendingReview,
            'eligibility_reasons' => null,
        ]);
    }

    public function validationFailed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'outcome' => CatalogLifecycleOutcome::ValidationFailed,
            'reason_code' => CatalogLifecycleReason::IncompleteContent,
            'previous_state' => CatalogLifecycleState::Draft,
            'next_state' => CatalogLifecycleState::Draft,
            'eligibility_reasons' => [
                CatalogLifecycleReason::IncompleteContent->value,
                CatalogLifecycleReason::ProvenanceIncomplete->value,
            ],
        ]);
    }

    public function conflict(): static
    {
        return $this->state(fn (array $attributes): array => [
            'outcome' => CatalogLifecycleOutcome::Conflict,
            'reason_code' => CatalogLifecycleReason::NumberConflict,
            'previous_state' => CatalogLifecycleState::Approved,
            'next_state' => CatalogLifecycleState::Approved,
            'eligibility_reasons' => null,
        ]);
    }

    public function forActor(User $user, string $actorReference): static
    {
        return $this->state(fn (array $attributes): array => [
            'actor_user_id' => $user->id,
            'actor_reference' => $actorReference,
        ]);
    }
}
