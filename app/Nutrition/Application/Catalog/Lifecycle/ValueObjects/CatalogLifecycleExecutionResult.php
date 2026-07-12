<?php

namespace App\Nutrition\Application\Catalog\Lifecycle\ValueObjects;

use App\Nutrition\Domain\Catalog\Lifecycle\ValueObjects\CatalogLifecycleResult;
use InvalidArgumentException;

final readonly class CatalogLifecycleExecutionResult
{
    public function __construct(
        public CatalogLifecycleResult $lifecycleResult,
        public CatalogLifecycleStoredEvent $rootEvent,
        public bool $replayed,
    ) {
        if ($this->rootEvent->toLifecycleResult() != $this->lifecycleResult) {
            throw new InvalidArgumentException('The root event and lifecycle result must agree.');
        }
    }
}
