<?php

namespace App\Nutrition\Application\Catalog\Lifecycle\ValueObjects;

use InvalidArgumentException;

final readonly class CatalogLifecycleExecutionContext
{
    public function __construct(
        public int $actorUserId,
        public string $actorReference,
    ) {
        if ($this->actorUserId <= 0) {
            throw new InvalidArgumentException('The lifecycle actor user identifier must be positive.');
        }

        if (trim($this->actorReference) === '' || trim($this->actorReference) !== $this->actorReference) {
            throw new InvalidArgumentException('The lifecycle actor reference must be nonblank and trimmed.');
        }
    }
}
