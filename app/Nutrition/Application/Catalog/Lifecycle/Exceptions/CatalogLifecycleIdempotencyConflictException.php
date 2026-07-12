<?php

namespace App\Nutrition\Application\Catalog\Lifecycle\Exceptions;

use App\Nutrition\Domain\Catalog\Lifecycle\Enums\CatalogLifecycleReason;
use RuntimeException;
use Throwable;

final class CatalogLifecycleIdempotencyConflictException extends RuntimeException
{
    public readonly CatalogLifecycleReason $reason;

    public function __construct(?Throwable $previous = null)
    {
        $this->reason = CatalogLifecycleReason::IdempotencyKeyReused;

        parent::__construct('The catalog lifecycle idempotency key was reused for a different command.', previous: $previous);
    }
}
