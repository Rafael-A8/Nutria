<?php

namespace App\Nutrition\Domain\Catalog\Lifecycle\Policies;

use App\Nutrition\Domain\Catalog\Lifecycle\Contracts\CatalogLifecyclePolicy;
use App\Nutrition\Domain\Catalog\Lifecycle\Contracts\CatalogLifecycleSnapshot;
use App\Nutrition\Domain\Catalog\Lifecycle\Enums\CatalogLifecycleOperation;
use App\Nutrition\Domain\Catalog\Lifecycle\Enums\CatalogLifecycleReason;
use App\Nutrition\Domain\Catalog\Lifecycle\Enums\CatalogLifecycleState;
use App\Nutrition\Domain\Catalog\Lifecycle\Enums\CatalogLifecycleSubjectType;
use App\Nutrition\Domain\Catalog\Lifecycle\ValueObjects\CatalogEligibilityResult;
use App\Nutrition\Domain\Catalog\Lifecycle\ValueObjects\CatalogLifecycleCommand;
use App\Nutrition\Domain\Catalog\Lifecycle\ValueObjects\CatalogLifecycleResult;
use App\Nutrition\Domain\Catalog\Lifecycle\ValueObjects\FoodReferenceLifecycleSnapshot;

final class FoodReferenceLifecyclePolicy implements CatalogLifecyclePolicy
{
    public function evaluate(CatalogLifecycleCommand $command, CatalogLifecycleSnapshot $snapshot): CatalogLifecycleResult
    {
        if ($command->subjectType !== CatalogLifecycleSubjectType::Reference
            || ! $snapshot instanceof FoodReferenceLifecycleSnapshot
            || $command->subjectId !== $snapshot->subjectId()) {
            return CatalogLifecycleResult::invalid(CatalogLifecycleReason::InvalidTransition, $snapshot->state());
        }

        if ($command->operation === CatalogLifecycleOperation::Archive && $snapshot->state === CatalogLifecycleState::Archived) {
            return CatalogLifecycleResult::noOp(CatalogLifecycleReason::AlreadyArchived, CatalogLifecycleState::Archived);
        }

        if ($snapshot->state === CatalogLifecycleState::Archived) {
            return CatalogLifecycleResult::invalid(CatalogLifecycleReason::TerminalArchived, $snapshot->state);
        }

        return match ($command->operation) {
            CatalogLifecycleOperation::CreateReference => $this->create($snapshot),
            CatalogLifecycleOperation::Archive => $this->archive($snapshot),
            default => CatalogLifecycleResult::invalid(CatalogLifecycleReason::InvalidTransition, $snapshot->state),
        };
    }

    private function create(FoodReferenceLifecycleSnapshot $snapshot): CatalogLifecycleResult
    {
        return ! $snapshot->exists
            ? CatalogLifecycleResult::succeeded(CatalogLifecycleReason::ReferenceCreated, null, CatalogLifecycleState::Available)
            : CatalogLifecycleResult::invalid(CatalogLifecycleReason::InvalidTransition, $snapshot->state);
    }

    private function archive(FoodReferenceLifecycleSnapshot $snapshot): CatalogLifecycleResult
    {
        if ($snapshot->state !== CatalogLifecycleState::Available) {
            return CatalogLifecycleResult::invalid(CatalogLifecycleReason::InvalidTransition, $snapshot->state);
        }

        if ($snapshot->hasActiveVersion || $snapshot->hasActiveAlias || $snapshot->hasActivePortion) {
            $reason = CatalogLifecycleReason::ReferenceHasActiveChildren;
            $eligibility = CatalogEligibilityResult::ineligible([$reason]);

            return CatalogLifecycleResult::validationFailed($reason, $snapshot->state, $eligibility);
        }

        return CatalogLifecycleResult::succeeded(CatalogLifecycleReason::TransitionApplied, $snapshot->state, CatalogLifecycleState::Archived);
    }
}
