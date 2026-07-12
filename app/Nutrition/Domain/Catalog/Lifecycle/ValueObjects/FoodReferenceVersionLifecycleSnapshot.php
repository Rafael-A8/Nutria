<?php

namespace App\Nutrition\Domain\Catalog\Lifecycle\ValueObjects;

use App\Nutrition\Domain\Catalog\Lifecycle\Contracts\CatalogLifecycleSnapshot;
use App\Nutrition\Domain\Catalog\Lifecycle\Enums\CatalogLifecycleState;
use App\Nutrition\Domain\Catalog\Lifecycle\Enums\CatalogLifecycleSubjectType;
use InvalidArgumentException;

final readonly class FoodReferenceVersionLifecycleSnapshot implements CatalogLifecycleSnapshot
{
    public function __construct(
        public string $subjectId,
        public bool $exists,
        public ?CatalogLifecycleState $state,
        public bool $actorIsOriginalAuthor = false,
        public bool $parentArchived = false,
        public bool $contentComplete = false,
        public bool $normalizedCanonicalNamePresent = false,
        public bool $provenanceComplete = false,
        public bool $conceptCompatible = false,
        public bool $hasPositiveEnergyBasis = false,
        public bool $hasPositiveEnergyKcal = false,
        public int $primarySourceCount = 0,
        public bool $primarySourceEligible = false,
        public bool $primarySourceProhibited = false,
        public bool $primarySourceArchived = false,
        public bool $primarySourceRecordKeyPresent = false,
        public bool $sourceScopeCompatible = false,
        public bool $hasActiveVersionConflict = false,
        public bool $hasSuccessor = false,
        public bool $isReferenceHead = false,
        public bool $isSupersededPredecessor = false,
        public bool $successorParentMatches = false,
        public bool $successorNumberIsContiguous = false,
    ) {
        $this->validateCommonShape();
        $this->validateSourceFacts();
    }

    public function subjectType(): CatalogLifecycleSubjectType
    {
        return CatalogLifecycleSubjectType::ReferenceVersion;
    }

    public function subjectId(): string
    {
        return $this->subjectId;
    }

    public function exists(): bool
    {
        return $this->exists;
    }

    public function state(): ?CatalogLifecycleState
    {
        return $this->state;
    }

    private function validateCommonShape(): void
    {
        if (trim($this->subjectId) === '') {
            throw new InvalidArgumentException('A version lifecycle snapshot requires a subject identifier.');
        }

        if ($this->exists !== ($this->state !== null)) {
            throw new InvalidArgumentException('Version existence and lifecycle state must agree.');
        }

        if (! $this->exists && ($this->hasActiveVersionConflict || $this->hasSuccessor || $this->isSupersededPredecessor)) {
            throw new InvalidArgumentException('A nonexisting version cannot claim active or successor facts.');
        }
    }

    private function validateSourceFacts(): void
    {
        if ($this->primarySourceCount < 0) {
            throw new InvalidArgumentException('Primary source count cannot be negative.');
        }

        if ($this->primarySourceCount === 0 && (
            $this->primarySourceEligible
            || $this->primarySourceProhibited
            || $this->primarySourceArchived
            || $this->primarySourceRecordKeyPresent
            || $this->sourceScopeCompatible
        )) {
            throw new InvalidArgumentException('Primary source facts require at least one primary source.');
        }

        if ($this->primarySourceEligible && $this->primarySourceProhibited) {
            throw new InvalidArgumentException('A primary source cannot be eligible and prohibited.');
        }
    }
}
