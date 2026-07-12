<?php

namespace App\Nutrition\Domain\Catalog\Lifecycle\ValueObjects;

use App\Nutrition\Domain\Catalog\Lifecycle\Contracts\CatalogLifecycleSnapshot;
use App\Nutrition\Domain\Catalog\Lifecycle\Enums\CatalogLifecycleState;
use App\Nutrition\Domain\Catalog\Lifecycle\Enums\CatalogLifecycleSubjectType;
use InvalidArgumentException;

final readonly class FoodReferenceLifecycleSnapshot implements CatalogLifecycleSnapshot
{
    public function __construct(
        public string $subjectId,
        public bool $exists,
        public ?CatalogLifecycleState $state,
        public bool $hasActiveVersion = false,
        public bool $hasActiveAlias = false,
        public bool $hasActivePortion = false,
    ) {
        if (trim($this->subjectId) === '') {
            throw new InvalidArgumentException('A reference lifecycle snapshot requires a subject identifier.');
        }

        if ($this->exists !== ($this->state !== null)) {
            throw new InvalidArgumentException('Reference existence and lifecycle state must agree.');
        }

        if ($this->state !== null && ! in_array($this->state, [CatalogLifecycleState::Available, CatalogLifecycleState::Archived], true)) {
            throw new InvalidArgumentException('A reference supports only available or archived states.');
        }

        if (! $this->exists && ($this->hasActiveVersion || $this->hasActiveAlias || $this->hasActivePortion)) {
            throw new InvalidArgumentException('A nonexisting reference cannot have active children.');
        }
    }

    public function subjectType(): CatalogLifecycleSubjectType
    {
        return CatalogLifecycleSubjectType::Reference;
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
}
