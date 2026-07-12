<?php

namespace App\Nutrition\Domain\Catalog\Lifecycle\Policies;

use App\Nutrition\Domain\Catalog\Lifecycle\Contracts\CatalogLifecyclePolicy;
use App\Nutrition\Domain\Catalog\Lifecycle\Contracts\CatalogLifecycleSnapshot;
use App\Nutrition\Domain\Catalog\Lifecycle\Enums\AliasKind;
use App\Nutrition\Domain\Catalog\Lifecycle\Enums\CatalogLifecycleOperation;
use App\Nutrition\Domain\Catalog\Lifecycle\Enums\CatalogLifecycleReason;
use App\Nutrition\Domain\Catalog\Lifecycle\Enums\CatalogLifecycleState;
use App\Nutrition\Domain\Catalog\Lifecycle\Enums\CatalogLifecycleSubjectType;
use App\Nutrition\Domain\Catalog\Lifecycle\ValueObjects\CatalogEligibilityResult;
use App\Nutrition\Domain\Catalog\Lifecycle\ValueObjects\CatalogLifecycleCommand;
use App\Nutrition\Domain\Catalog\Lifecycle\ValueObjects\CatalogLifecycleResult;
use App\Nutrition\Domain\Catalog\Lifecycle\ValueObjects\FoodAliasLifecycleSnapshot;

final class FoodAliasLifecyclePolicy implements CatalogLifecyclePolicy
{
    public function evaluate(CatalogLifecycleCommand $command, CatalogLifecycleSnapshot $snapshot): CatalogLifecycleResult
    {
        if ($command->subjectType !== CatalogLifecycleSubjectType::Alias
            || ! $snapshot instanceof FoodAliasLifecycleSnapshot
            || $command->subjectId !== $snapshot->subjectId()) {
            return CatalogLifecycleResult::invalid(CatalogLifecycleReason::InvalidTransition, $snapshot->state());
        }

        $noOp = $this->noOp($command->operation, $snapshot->state);

        if ($noOp !== null) {
            return $noOp;
        }

        if ($snapshot->state === CatalogLifecycleState::Archived) {
            return CatalogLifecycleResult::invalid(CatalogLifecycleReason::TerminalArchived, $snapshot->state);
        }
        if ($snapshot->state === CatalogLifecycleState::Withdrawn && $command->operation !== CatalogLifecycleOperation::CreateSuccessor) {
            return CatalogLifecycleResult::invalid(CatalogLifecycleReason::TerminalWithdrawn, $snapshot->state);
        }
        if ($snapshot->state === CatalogLifecycleState::Rejected
            && ! in_array($command->operation, [CatalogLifecycleOperation::Archive, CatalogLifecycleOperation::CreateSuccessor], true)) {
            return CatalogLifecycleResult::invalid(CatalogLifecycleReason::TerminalRejected, $snapshot->state);
        }

        return match ($command->operation) {
            CatalogLifecycleOperation::CreateDraft => $this->createDraft($snapshot),
            CatalogLifecycleOperation::EditDraft => $this->editDraft($snapshot),
            CatalogLifecycleOperation::SubmitForReview => $this->submit($snapshot),
            CatalogLifecycleOperation::ReturnToDraft => $this->transition($snapshot, CatalogLifecycleState::PendingReview, CatalogLifecycleState::Draft),
            CatalogLifecycleOperation::Approve => $this->approve($snapshot),
            CatalogLifecycleOperation::Reject => $this->transition($snapshot, CatalogLifecycleState::PendingReview, CatalogLifecycleState::Rejected),
            CatalogLifecycleOperation::Publish => $this->publish($snapshot),
            CatalogLifecycleOperation::Activate => $this->activate($snapshot, CatalogLifecycleState::PublishedInactive),
            CatalogLifecycleOperation::Reactivate => $this->activate($snapshot, CatalogLifecycleState::Deactivated),
            CatalogLifecycleOperation::Deactivate => $this->transition($snapshot, CatalogLifecycleState::Active, CatalogLifecycleState::Deactivated),
            CatalogLifecycleOperation::Withdraw => $this->withdraw($snapshot),
            CatalogLifecycleOperation::Archive => $this->archive($snapshot),
            CatalogLifecycleOperation::CreateSuccessor => $this->createSuccessor($snapshot),
            default => CatalogLifecycleResult::invalid(CatalogLifecycleReason::InvalidTransition, $snapshot->state),
        };
    }

    private function createDraft(FoodAliasLifecycleSnapshot $snapshot): CatalogLifecycleResult
    {
        if ($snapshot->exists) {
            return CatalogLifecycleResult::invalid(CatalogLifecycleReason::InvalidTransition, $snapshot->state);
        }
        if ($snapshot->parentArchived) {
            return $this->validationFailure($snapshot, [CatalogLifecycleReason::ParentArchived]);
        }

        return CatalogLifecycleResult::succeeded(CatalogLifecycleReason::DraftCreated, null, CatalogLifecycleState::Draft);
    }

    private function editDraft(FoodAliasLifecycleSnapshot $snapshot): CatalogLifecycleResult
    {
        if ($snapshot->state !== CatalogLifecycleState::Draft) {
            return CatalogLifecycleResult::invalid(CatalogLifecycleReason::ContentFrozen, $snapshot->state);
        }
        if ($snapshot->hasSuccessor) {
            return CatalogLifecycleResult::conflict(CatalogLifecycleReason::SuccessorExists, $snapshot->state);
        }

        return CatalogLifecycleResult::succeeded(CatalogLifecycleReason::DraftUpdated, $snapshot->state, CatalogLifecycleState::Draft);
    }

    private function submit(FoodAliasLifecycleSnapshot $snapshot): CatalogLifecycleResult
    {
        if ($snapshot->state !== CatalogLifecycleState::Draft) {
            return CatalogLifecycleResult::invalid(CatalogLifecycleReason::InvalidTransition, $snapshot->state);
        }

        $reasons = $this->reviewReasons($snapshot);

        return $reasons === []
            ? CatalogLifecycleResult::succeeded(CatalogLifecycleReason::TransitionApplied, $snapshot->state, CatalogLifecycleState::PendingReview)
            : $this->validationFailure($snapshot, $reasons);
    }

    private function approve(FoodAliasLifecycleSnapshot $snapshot): CatalogLifecycleResult
    {
        if ($snapshot->state !== CatalogLifecycleState::PendingReview) {
            return CatalogLifecycleResult::invalid(CatalogLifecycleReason::InvalidTransition, $snapshot->state);
        }

        $reasons = $snapshot->actorIsOriginalAuthor ? [CatalogLifecycleReason::SelfApprovalProhibited] : [];
        array_push($reasons, ...$this->reviewReasons($snapshot));

        return $reasons === []
            ? CatalogLifecycleResult::succeeded(CatalogLifecycleReason::TransitionApplied, $snapshot->state, CatalogLifecycleState::Approved)
            : $this->validationFailure($snapshot, $reasons);
    }

    private function publish(FoodAliasLifecycleSnapshot $snapshot): CatalogLifecycleResult
    {
        if ($snapshot->state !== CatalogLifecycleState::Approved) {
            return CatalogLifecycleResult::invalid(CatalogLifecycleReason::NotApproved, $snapshot->state);
        }
        if ($snapshot->parentArchived) {
            return $this->validationFailure($snapshot, [CatalogLifecycleReason::ParentArchived]);
        }

        return CatalogLifecycleResult::succeeded(CatalogLifecycleReason::TransitionApplied, $snapshot->state, CatalogLifecycleState::PublishedInactive);
    }

    private function activate(FoodAliasLifecycleSnapshot $snapshot, CatalogLifecycleState $requiredState): CatalogLifecycleResult
    {
        if ($snapshot->state !== $requiredState) {
            return CatalogLifecycleResult::invalid(CatalogLifecycleReason::NotPublished, $snapshot->state);
        }

        $reasons = $this->activationReasons($snapshot);

        if ($reasons !== []) {
            return $this->validationFailure($snapshot, $reasons);
        }
        if ($snapshot->hasActiveAliasConflict) {
            return CatalogLifecycleResult::conflict(CatalogLifecycleReason::ActiveAliasConflict, $snapshot->state);
        }

        return CatalogLifecycleResult::succeeded(CatalogLifecycleReason::TransitionApplied, $snapshot->state, CatalogLifecycleState::Active);
    }

    private function withdraw(FoodAliasLifecycleSnapshot $snapshot): CatalogLifecycleResult
    {
        if (! in_array($snapshot->state, [CatalogLifecycleState::PublishedInactive, CatalogLifecycleState::Active, CatalogLifecycleState::Deactivated], true)) {
            return CatalogLifecycleResult::invalid(CatalogLifecycleReason::NotPublished, $snapshot->state);
        }

        return CatalogLifecycleResult::succeeded(CatalogLifecycleReason::TransitionApplied, $snapshot->state, CatalogLifecycleState::Withdrawn);
    }

    private function archive(FoodAliasLifecycleSnapshot $snapshot): CatalogLifecycleResult
    {
        if (! in_array($snapshot->state, [CatalogLifecycleState::Draft, CatalogLifecycleState::Approved, CatalogLifecycleState::PublishedInactive, CatalogLifecycleState::Deactivated, CatalogLifecycleState::Rejected], true)) {
            return CatalogLifecycleResult::invalid(CatalogLifecycleReason::InvalidTransition, $snapshot->state);
        }

        return CatalogLifecycleResult::succeeded(CatalogLifecycleReason::TransitionApplied, $snapshot->state, CatalogLifecycleState::Archived);
    }

    private function createSuccessor(FoodAliasLifecycleSnapshot $snapshot): CatalogLifecycleResult
    {
        if (! in_array($snapshot->state, [CatalogLifecycleState::Approved, CatalogLifecycleState::Rejected, CatalogLifecycleState::PublishedInactive, CatalogLifecycleState::Active, CatalogLifecycleState::Deactivated, CatalogLifecycleState::Withdrawn], true)) {
            return CatalogLifecycleResult::invalid(CatalogLifecycleReason::InvalidTransition, $snapshot->state);
        }
        if ($snapshot->hasSuccessor) {
            return CatalogLifecycleResult::conflict(CatalogLifecycleReason::SuccessorExists, $snapshot->state);
        }
        if (! $snapshot->isLineageHead) {
            return CatalogLifecycleResult::conflict(CatalogLifecycleReason::NotLineageHead, $snapshot->state);
        }

        $reasons = [];
        if (! $snapshot->successorParentMatches) {
            $reasons[] = CatalogLifecycleReason::ParentMismatch;
        }
        if (! $snapshot->successorLineageMatches) {
            $reasons[] = CatalogLifecycleReason::LineageMismatch;
        }
        if ($reasons !== []) {
            return $this->validationFailure($snapshot, $reasons);
        }
        if (! $snapshot->successorNumberIsContiguous) {
            return CatalogLifecycleResult::conflict(CatalogLifecycleReason::NumberConflict, $snapshot->state);
        }

        return CatalogLifecycleResult::succeeded(CatalogLifecycleReason::SuccessorCreated, $snapshot->state, CatalogLifecycleState::Draft);
    }

    private function transition(FoodAliasLifecycleSnapshot $snapshot, CatalogLifecycleState $requiredState, CatalogLifecycleState $nextState): CatalogLifecycleResult
    {
        return $snapshot->state === $requiredState
            ? CatalogLifecycleResult::succeeded(CatalogLifecycleReason::TransitionApplied, $snapshot->state, $nextState)
            : CatalogLifecycleResult::invalid(CatalogLifecycleReason::InvalidTransition, $snapshot->state);
    }

    /** @return list<CatalogLifecycleReason> */
    private function reviewReasons(FoodAliasLifecycleSnapshot $snapshot): array
    {
        $reasons = [];
        if ($snapshot->parentArchived) {
            $reasons[] = CatalogLifecycleReason::ParentArchived;
        }
        if (! $snapshot->contentComplete) {
            $reasons[] = CatalogLifecycleReason::IncompleteContent;
        }
        if (! $snapshot->normalizedAliasPresent) {
            $reasons[] = CatalogLifecycleReason::AliasNormalizationMissing;
        }
        if (! $snapshot->localePresent) {
            $reasons[] = CatalogLifecycleReason::AliasLocaleMissing;
        }
        if ($snapshot->aliasKind === null) {
            $reasons[] = CatalogLifecycleReason::AliasKindInvalid;
        }
        if (! $snapshot->provenanceComplete) {
            $reasons[] = CatalogLifecycleReason::ProvenanceIncomplete;
        }
        if (! $snapshot->sourcePresent) {
            $reasons[] = CatalogLifecycleReason::SourceMissing;
        }
        if ($snapshot->sourceProhibited) {
            $reasons[] = CatalogLifecycleReason::SourceProhibited;
        }
        if ($snapshot->sourcePresent && ! $snapshot->sourceRecordKeyPresent) {
            $reasons[] = CatalogLifecycleReason::SourceRecordKeyMissing;
        }
        if ($snapshot->sourcePresent && ! $snapshot->sourceScopeCompatible) {
            $reasons[] = CatalogLifecycleReason::SourceScopeMismatch;
        }
        $compatibilityReason = $this->compatibilityReason($snapshot);
        if ($compatibilityReason !== null) {
            $reasons[] = $compatibilityReason;
        }

        return $reasons;
    }

    /** @return list<CatalogLifecycleReason> */
    private function activationReasons(FoodAliasLifecycleSnapshot $snapshot): array
    {
        $reasons = $this->reviewReasons($snapshot);
        if ($snapshot->sourcePresent && ! $snapshot->sourceEligible) {
            $reasons[] = CatalogLifecycleReason::SourceIneligible;
        }
        if ($snapshot->sourceArchived) {
            $reasons[] = CatalogLifecycleReason::SourceArchived;
        }
        if ($snapshot->isSupersededPredecessor) {
            $reasons[] = CatalogLifecycleReason::SupersededPredecessor;
        }

        return $reasons;
    }

    private function compatibilityReason(FoodAliasLifecycleSnapshot $snapshot): ?CatalogLifecycleReason
    {
        return match (true) {
            $snapshot->aliasKind === AliasKind::Generic && ! $snapshot->referenceIsGeneric => CatalogLifecycleReason::GenericAliasReferenceMismatch,
            $snapshot->aliasKind === AliasKind::Brand && $snapshot->referenceIsGeneric => CatalogLifecycleReason::BrandAliasGenericReferenceMismatch,
            default => null,
        };
    }

    private function noOp(CatalogLifecycleOperation $operation, ?CatalogLifecycleState $state): ?CatalogLifecycleResult
    {
        $reason = match (true) {
            $operation === CatalogLifecycleOperation::SubmitForReview && $state === CatalogLifecycleState::PendingReview => CatalogLifecycleReason::AlreadyPendingReview,
            $operation === CatalogLifecycleOperation::Approve && $state === CatalogLifecycleState::Approved => CatalogLifecycleReason::AlreadyApproved,
            $operation === CatalogLifecycleOperation::Publish && in_array($state, [CatalogLifecycleState::PublishedInactive, CatalogLifecycleState::Active, CatalogLifecycleState::Deactivated], true) => CatalogLifecycleReason::AlreadyPublished,
            $operation === CatalogLifecycleOperation::Activate && $state === CatalogLifecycleState::Active => CatalogLifecycleReason::AlreadyActive,
            $operation === CatalogLifecycleOperation::Deactivate && $state === CatalogLifecycleState::Deactivated => CatalogLifecycleReason::AlreadyDeactivated,
            $operation === CatalogLifecycleOperation::Withdraw && $state === CatalogLifecycleState::Withdrawn => CatalogLifecycleReason::AlreadyWithdrawn,
            $operation === CatalogLifecycleOperation::Archive && $state === CatalogLifecycleState::Archived => CatalogLifecycleReason::AlreadyArchived,
            default => null,
        };

        return $reason !== null && $state !== null ? CatalogLifecycleResult::noOp($reason, $state) : null;
    }

    /** @param list<CatalogLifecycleReason> $reasons */
    private function validationFailure(FoodAliasLifecycleSnapshot $snapshot, array $reasons): CatalogLifecycleResult
    {
        $eligibility = CatalogEligibilityResult::ineligible($reasons);
        $reason = $eligibility->firstReason();

        return CatalogLifecycleResult::validationFailed($reason, $snapshot->state, $eligibility);
    }
}
