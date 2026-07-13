<?php

namespace App\Nutrition\Application\Catalog\Lifecycle\ValueObjects;

use App\Nutrition\Domain\Catalog\Lifecycle\Enums\CatalogLifecycleOutcome;
use InvalidArgumentException;

final readonly class CatalogSuccessorCreationResult
{
    public function __construct(
        public CatalogLifecycleExecutionResult $execution,
        public ?string $successorPublicId,
    ) {
        if ($this->execution->lifecycleResult->outcome === CatalogLifecycleOutcome::Succeeded) {
            $this->validateSuccessorPublicId();
        }
    }

    public function wasCreated(): bool
    {
        return $this->execution->lifecycleResult->outcome === CatalogLifecycleOutcome::Succeeded
            && ! $this->execution->replayed;
    }

    public function wasReplayed(): bool
    {
        return $this->execution->replayed;
    }

    public function hasSuccessor(): bool
    {
        return $this->successorPublicId !== null;
    }

    private function validateSuccessorPublicId(): void
    {
        if ($this->successorPublicId === null
            || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $this->successorPublicId) !== 1) {
            throw new InvalidArgumentException('Successful catalog successor creation requires a canonical successor UUID.');
        }
    }
}
