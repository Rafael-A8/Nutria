<?php

namespace App\Nutrition\Application\Catalog\Lifecycle\ValueObjects;

use App\Nutrition\Domain\Catalog\Lifecycle\Enums\CatalogLifecycleOutcome;
use InvalidArgumentException;

final readonly class CatalogActiveReplacementResult
{
    public function __construct(
        public CatalogLifecycleExecutionResult $execution,
        public ?string $deactivatedSubjectPublicId,
    ) {
        if ($this->execution->lifecycleResult->outcome === CatalogLifecycleOutcome::Succeeded) {
            $this->validateDeactivatedSubjectPublicId();
        }
    }

    public function wasReplaced(): bool
    {
        return $this->execution->lifecycleResult->outcome === CatalogLifecycleOutcome::Succeeded
            && ! $this->execution->replayed;
    }

    public function wasReplayed(): bool
    {
        return $this->execution->replayed;
    }

    private function validateDeactivatedSubjectPublicId(): void
    {
        if ($this->deactivatedSubjectPublicId === null
            || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $this->deactivatedSubjectPublicId) !== 1) {
            throw new InvalidArgumentException('Successful catalog active replacement requires a canonical predecessor UUID.');
        }
    }
}
