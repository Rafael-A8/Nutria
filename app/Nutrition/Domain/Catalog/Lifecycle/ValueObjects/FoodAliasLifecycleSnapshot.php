<?php

namespace App\Nutrition\Domain\Catalog\Lifecycle\ValueObjects;

use App\Nutrition\Domain\Catalog\Lifecycle\Contracts\CatalogLifecycleSnapshot;
use App\Nutrition\Domain\Catalog\Lifecycle\Enums\AliasKind;
use App\Nutrition\Domain\Catalog\Lifecycle\Enums\CatalogLifecycleState;
use App\Nutrition\Domain\Catalog\Lifecycle\Enums\CatalogLifecycleSubjectType;
use InvalidArgumentException;

final readonly class FoodAliasLifecycleSnapshot implements CatalogLifecycleSnapshot
{
    public function __construct(
        public string $subjectId,
        public bool $exists,
        public ?CatalogLifecycleState $state,
        public bool $actorIsOriginalAuthor = false,
        public bool $parentArchived = false,
        public bool $referenceIsGeneric = false,
        public bool $contentComplete = false,
        public bool $normalizedAliasPresent = false,
        public bool $localePresent = false,
        public ?AliasKind $aliasKind = null,
        public bool $provenanceComplete = false,
        public bool $sourcePresent = false,
        public bool $sourceEligible = false,
        public bool $sourceProhibited = false,
        public bool $sourceArchived = false,
        public bool $sourceRecordKeyPresent = false,
        public bool $sourceScopeCompatible = false,
        public bool $hasActiveAliasConflict = false,
        public bool $hasSuccessor = false,
        public bool $isLineageHead = false,
        public bool $isSupersededPredecessor = false,
        public bool $successorParentMatches = false,
        public bool $successorLineageMatches = false,
        public bool $successorNumberIsContiguous = false,
    ) {
        $this->validateCommonShape();
        $this->validateSourceFacts();
    }

    public function subjectType(): CatalogLifecycleSubjectType
    {
        return CatalogLifecycleSubjectType::Alias;
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
            throw new InvalidArgumentException('An alias lifecycle snapshot requires a subject identifier.');
        }

        if ($this->exists !== ($this->state !== null)) {
            throw new InvalidArgumentException('Alias existence and lifecycle state must agree.');
        }

        if (! $this->exists && ($this->hasActiveAliasConflict || $this->hasSuccessor || $this->isSupersededPredecessor)) {
            throw new InvalidArgumentException('A nonexisting alias cannot claim active or successor facts.');
        }
    }

    private function validateSourceFacts(): void
    {
        if (! $this->sourcePresent && (
            $this->sourceEligible
            || $this->sourceProhibited
            || $this->sourceArchived
            || $this->sourceRecordKeyPresent
            || $this->sourceScopeCompatible
        )) {
            throw new InvalidArgumentException('Alias source facts require a present source.');
        }

        if ($this->sourceEligible && $this->sourceProhibited) {
            throw new InvalidArgumentException('An alias source cannot be eligible and prohibited.');
        }
    }
}
