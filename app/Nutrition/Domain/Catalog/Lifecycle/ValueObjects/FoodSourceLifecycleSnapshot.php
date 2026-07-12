<?php

namespace App\Nutrition\Domain\Catalog\Lifecycle\ValueObjects;

use App\Nutrition\Domain\Catalog\Lifecycle\Contracts\CatalogLifecycleSnapshot;
use App\Nutrition\Domain\Catalog\Lifecycle\Enums\CatalogLifecycleState;
use App\Nutrition\Domain\Catalog\Lifecycle\Enums\CatalogLifecycleSubjectType;
use InvalidArgumentException;

final readonly class FoodSourceLifecycleSnapshot implements CatalogLifecycleSnapshot
{
    public function __construct(
        public string $subjectId,
        public bool $exists,
        public ?CatalogLifecycleState $state,
        public bool $isAlreadyReferenced = false,
        public bool $authorityChangeValid = false,
        public bool $archiveAllowed = false,
    ) {
        if (trim($this->subjectId) === '') {
            throw new InvalidArgumentException('A source lifecycle snapshot requires a subject identifier.');
        }

        if ($this->exists !== ($this->state !== null)) {
            throw new InvalidArgumentException('Source existence and lifecycle state must agree.');
        }

        if ($this->state !== null && ! in_array($this->state, [CatalogLifecycleState::Available, CatalogLifecycleState::Archived], true)) {
            throw new InvalidArgumentException('A source supports only available or archived states.');
        }

        if (! $this->exists && $this->isAlreadyReferenced) {
            throw new InvalidArgumentException('A nonexisting source cannot already be referenced.');
        }
    }

    public function subjectType(): CatalogLifecycleSubjectType
    {
        return CatalogLifecycleSubjectType::Source;
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
