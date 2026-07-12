<?php

namespace App\Nutrition\Application\Catalog\Lifecycle\Exceptions;

use RuntimeException;
use Throwable;

final class CatalogLifecycleEventPersistenceException extends RuntimeException
{
    public function __construct(Throwable $previous)
    {
        parent::__construct('The catalog lifecycle event could not be persisted.', previous: $previous);
    }
}
