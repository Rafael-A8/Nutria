<?php

namespace App\Nutrition\Domain\Catalog\Lifecycle\ValueObjects;

use App\Nutrition\Domain\Catalog\Lifecycle\Enums\CatalogLifecycleOutcome;
use App\Nutrition\Domain\Catalog\Lifecycle\Enums\CatalogLifecycleReason;
use App\Nutrition\Domain\Catalog\Lifecycle\Enums\CatalogLifecycleState;
use InvalidArgumentException;

final readonly class CatalogLifecycleResult
{
    private function __construct(
        public CatalogLifecycleOutcome $outcome,
        public CatalogLifecycleReason $reason,
        public ?CatalogLifecycleState $previousState,
        public ?CatalogLifecycleState $nextState,
        public CatalogEligibilityResult $eligibility,
    ) {
        $this->validateShape();
    }

    public static function succeeded(
        CatalogLifecycleReason $reason,
        ?CatalogLifecycleState $previousState,
        CatalogLifecycleState $nextState,
    ): self {
        return new self(
            CatalogLifecycleOutcome::Succeeded,
            $reason,
            $previousState,
            $nextState,
            CatalogEligibilityResult::eligible(),
        );
    }

    public static function noOp(CatalogLifecycleReason $reason, CatalogLifecycleState $state): self
    {
        return new self(
            CatalogLifecycleOutcome::NoOp,
            $reason,
            $state,
            $state,
            CatalogEligibilityResult::eligible(),
        );
    }

    public static function invalid(CatalogLifecycleReason $reason, ?CatalogLifecycleState $state): self
    {
        return new self(
            CatalogLifecycleOutcome::InvalidTransition,
            $reason,
            $state,
            $state,
            CatalogEligibilityResult::eligible(),
        );
    }

    public static function validationFailed(
        CatalogLifecycleReason $reason,
        ?CatalogLifecycleState $state,
        CatalogEligibilityResult $eligibility,
    ): self {
        return new self(
            CatalogLifecycleOutcome::ValidationFailed,
            $reason,
            $state,
            $state,
            $eligibility,
        );
    }

    public static function conflict(CatalogLifecycleReason $reason, ?CatalogLifecycleState $state): self
    {
        return new self(
            CatalogLifecycleOutcome::Conflict,
            $reason,
            $state,
            $state,
            CatalogEligibilityResult::eligible(),
        );
    }

    private function validateShape(): void
    {
        if ($this->outcome === CatalogLifecycleOutcome::Succeeded && $this->nextState === null) {
            throw new InvalidArgumentException('A successful lifecycle result requires a next state.');
        }

        $successReasons = [
            CatalogLifecycleReason::TransitionApplied,
            CatalogLifecycleReason::SourceCreated,
            CatalogLifecycleReason::SourceUpdated,
            CatalogLifecycleReason::ReferenceCreated,
            CatalogLifecycleReason::DraftCreated,
            CatalogLifecycleReason::DraftUpdated,
            CatalogLifecycleReason::SuccessorCreated,
        ];

        if ($this->outcome === CatalogLifecycleOutcome::Succeeded && ! in_array($this->reason, $successReasons, true)) {
            throw new InvalidArgumentException('A successful lifecycle result requires a success reason.');
        }

        if ($this->outcome !== CatalogLifecycleOutcome::Succeeded && $this->previousState !== $this->nextState) {
            throw new InvalidArgumentException('An unsuccessful lifecycle result cannot change state.');
        }

        $noOpReasons = [
            CatalogLifecycleReason::AlreadyPendingReview,
            CatalogLifecycleReason::AlreadyApproved,
            CatalogLifecycleReason::AlreadyPublished,
            CatalogLifecycleReason::AlreadyActive,
            CatalogLifecycleReason::AlreadyDeactivated,
            CatalogLifecycleReason::AlreadyWithdrawn,
            CatalogLifecycleReason::AlreadyArchived,
        ];

        if ($this->outcome === CatalogLifecycleOutcome::NoOp && ! in_array($this->reason, $noOpReasons, true)) {
            throw new InvalidArgumentException('A no-op lifecycle result requires an already-reached reason.');
        }

        if ($this->outcome === CatalogLifecycleOutcome::ValidationFailed) {
            if ($this->eligibility->isEligible() || $this->eligibility->firstReason() !== $this->reason) {
                throw new InvalidArgumentException('A validation failure requires matching ordered eligibility reasons.');
            }
        } elseif (! $this->eligibility->isEligible()) {
            throw new InvalidArgumentException('Only validation failures may carry ineligibility reasons.');
        }
    }
}
