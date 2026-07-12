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
use App\Nutrition\Domain\Catalog\Lifecycle\ValueObjects\FoodSourceLifecycleSnapshot;

final class FoodSourceLifecyclePolicy implements CatalogLifecyclePolicy
{
    public function evaluate(CatalogLifecycleCommand $command, CatalogLifecycleSnapshot $snapshot): CatalogLifecycleResult
    {
        if ($command->subjectType !== CatalogLifecycleSubjectType::Source
            || ! $snapshot instanceof FoodSourceLifecycleSnapshot
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
            CatalogLifecycleOperation::CreateSource => $this->create($snapshot),
            CatalogLifecycleOperation::EditSource => $this->edit($snapshot),
            CatalogLifecycleOperation::ChangeAuthority => $this->changeAuthority($snapshot),
            CatalogLifecycleOperation::Archive => $this->archive($snapshot),
            default => CatalogLifecycleResult::invalid(CatalogLifecycleReason::InvalidTransition, $snapshot->state),
        };
    }

    private function create(FoodSourceLifecycleSnapshot $snapshot): CatalogLifecycleResult
    {
        return ! $snapshot->exists
            ? CatalogLifecycleResult::succeeded(CatalogLifecycleReason::SourceCreated, null, CatalogLifecycleState::Available)
            : CatalogLifecycleResult::invalid(CatalogLifecycleReason::InvalidTransition, $snapshot->state);
    }

    private function edit(FoodSourceLifecycleSnapshot $snapshot): CatalogLifecycleResult
    {
        if ($snapshot->state !== CatalogLifecycleState::Available) {
            return CatalogLifecycleResult::invalid(CatalogLifecycleReason::InvalidTransition, $snapshot->state);
        }
        if ($snapshot->isAlreadyReferenced) {
            return $this->validationFailure($snapshot, CatalogLifecycleReason::SourceAlreadyUsed);
        }

        return CatalogLifecycleResult::succeeded(CatalogLifecycleReason::SourceUpdated, $snapshot->state, CatalogLifecycleState::Available);
    }

    private function changeAuthority(FoodSourceLifecycleSnapshot $snapshot): CatalogLifecycleResult
    {
        if ($snapshot->state !== CatalogLifecycleState::Available) {
            return CatalogLifecycleResult::invalid(CatalogLifecycleReason::InvalidTransition, $snapshot->state);
        }
        if (! $snapshot->authorityChangeValid) {
            return $this->validationFailure($snapshot, CatalogLifecycleReason::CatalogIntegrityViolation);
        }

        return CatalogLifecycleResult::succeeded(CatalogLifecycleReason::SourceUpdated, $snapshot->state, CatalogLifecycleState::Available);
    }

    private function archive(FoodSourceLifecycleSnapshot $snapshot): CatalogLifecycleResult
    {
        if ($snapshot->state !== CatalogLifecycleState::Available) {
            return CatalogLifecycleResult::invalid(CatalogLifecycleReason::InvalidTransition, $snapshot->state);
        }
        if (! $snapshot->archiveAllowed) {
            return $this->validationFailure($snapshot, CatalogLifecycleReason::CatalogIntegrityViolation);
        }

        return CatalogLifecycleResult::succeeded(CatalogLifecycleReason::TransitionApplied, $snapshot->state, CatalogLifecycleState::Archived);
    }

    private function validationFailure(FoodSourceLifecycleSnapshot $snapshot, CatalogLifecycleReason $reason): CatalogLifecycleResult
    {
        $eligibility = CatalogEligibilityResult::ineligible([$reason]);

        return CatalogLifecycleResult::validationFailed($reason, $snapshot->state, $eligibility);
    }
}
