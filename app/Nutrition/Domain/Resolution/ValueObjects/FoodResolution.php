<?php

namespace App\Nutrition\Domain\Resolution\ValueObjects;

use App\Nutrition\Domain\Catalog\ValueObjects\FoodResolutionCandidate;
use App\Nutrition\Domain\Resolution\Enums\FoodResolutionReason;
use App\Nutrition\Domain\Resolution\Enums\FoodResolutionStatus;
use InvalidArgumentException;

final readonly class FoodResolution
{
    /**
     * @param  list<FoodResolutionCandidate>  $candidates
     */
    private function __construct(
        public FoodResolutionStatus $status,
        public FoodResolutionReason $reason,
        public array $candidates,
        public ?FoodResolutionCandidate $selectedCandidate,
        public FoodResolutionTrace $trace,
    ) {
        $this->validateCandidateList();
        $this->validateTrace();
        $this->validateStatusShape();
        $this->validateReasonForStatus();
    }

    public static function resolved(
        FoodResolutionCandidate $candidate,
        FoodResolutionReason $reason,
        FoodResolutionTrace $trace,
    ): self {
        return new self(
            status: FoodResolutionStatus::Resolved,
            reason: $reason,
            candidates: [$candidate],
            selectedCandidate: $candidate,
            trace: $trace,
        );
    }

    /**
     * @param  list<FoodResolutionCandidate>  $candidates
     */
    public static function ambiguous(
        array $candidates,
        FoodResolutionReason $reason,
        FoodResolutionTrace $trace,
    ): self {
        return new self(
            status: FoodResolutionStatus::Ambiguous,
            reason: $reason,
            candidates: $candidates,
            selectedCandidate: null,
            trace: $trace,
        );
    }

    /**
     * @param  list<FoodResolutionCandidate>  $candidates
     */
    public static function clarificationRequired(
        array $candidates,
        FoodResolutionReason $reason,
        FoodResolutionTrace $trace,
    ): self {
        return new self(
            status: FoodResolutionStatus::ClarificationRequired,
            reason: $reason,
            candidates: $candidates,
            selectedCandidate: null,
            trace: $trace,
        );
    }

    public static function unresolved(
        FoodResolutionReason $reason,
        FoodResolutionTrace $trace,
    ): self {
        return new self(
            status: FoodResolutionStatus::Unresolved,
            reason: $reason,
            candidates: [],
            selectedCandidate: null,
            trace: $trace,
        );
    }

    public static function invalidInput(
        FoodResolutionReason $reason,
        FoodResolutionTrace $trace,
    ): self {
        return new self(
            status: FoodResolutionStatus::InvalidInput,
            reason: $reason,
            candidates: [],
            selectedCandidate: null,
            trace: $trace,
        );
    }

    private function validateCandidateList(): void
    {
        if (! array_is_list($this->candidates)) {
            throw new InvalidArgumentException('Resolution candidates must be a list.');
        }

        foreach ($this->candidates as $candidate) {
            if (! $candidate instanceof FoodResolutionCandidate) {
                throw new InvalidArgumentException('Resolution candidates must use FoodResolutionCandidate values.');
            }
        }
    }

    private function validateTrace(): void
    {
        if ($this->trace->finalStatus !== $this->status || $this->trace->finalReason !== $this->reason) {
            throw new InvalidArgumentException('Resolution trace outcome must match the result outcome.');
        }
    }

    private function validateStatusShape(): void
    {
        match ($this->status) {
            FoodResolutionStatus::Resolved => $this->validateResolvedShape(),
            FoodResolutionStatus::Ambiguous => $this->validateAmbiguousShape(),
            FoodResolutionStatus::ClarificationRequired => $this->validateUnselectedShape(),
            FoodResolutionStatus::Unresolved,
            FoodResolutionStatus::InvalidInput => $this->validateEmptyShape(),
        };
    }

    private function validateResolvedShape(): void
    {
        if (count($this->candidates) !== 1 || $this->selectedCandidate !== $this->candidates[0]) {
            throw new InvalidArgumentException('A resolved result requires exactly one selected candidate.');
        }
    }

    private function validateAmbiguousShape(): void
    {
        if (count($this->candidates) < 2 || $this->selectedCandidate !== null) {
            throw new InvalidArgumentException('An ambiguous result requires at least two unselected candidates.');
        }
    }

    private function validateUnselectedShape(): void
    {
        if ($this->selectedCandidate !== null) {
            throw new InvalidArgumentException('A clarification-required result cannot select a candidate.');
        }
    }

    private function validateEmptyShape(): void
    {
        if ($this->candidates !== [] || $this->selectedCandidate !== null) {
            throw new InvalidArgumentException('An unresolved or invalid result cannot carry candidates.');
        }
    }

    private function validateReasonForStatus(): void
    {
        $allowedReasons = match ($this->status) {
            FoodResolutionStatus::Resolved => [
                FoodResolutionReason::ExplicitGenericReference,
                FoodResolutionReason::UniqueCompatibleExactMatch,
            ],
            FoodResolutionStatus::Ambiguous => [
                FoodResolutionReason::AliasCollision,
                FoodResolutionReason::CanonicalAliasCollision,
                FoodResolutionReason::MultipleCompatibleCandidates,
            ],
            FoodResolutionStatus::ClarificationRequired => [
                FoodResolutionReason::GenericAliasRequiresClarification,
                FoodResolutionReason::RoleIncompatible,
                FoodResolutionReason::PreparationIncompatible,
                FoodResolutionReason::InactiveReference,
                FoodResolutionReason::NoActiveReviewedVersion,
                FoodResolutionReason::PrivateScopeExcluded,
            ],
            FoodResolutionStatus::Unresolved => [
                FoodResolutionReason::NoExactMatch,
                FoodResolutionReason::InactiveReference,
                FoodResolutionReason::NoActiveReviewedVersion,
                FoodResolutionReason::PrivateScopeExcluded,
            ],
            FoodResolutionStatus::InvalidInput => [FoodResolutionReason::BlankFoodText],
        };

        if (! in_array($this->reason, $allowedReasons, true)) {
            throw new InvalidArgumentException('The resolution reason is incompatible with the result status.');
        }
    }
}
