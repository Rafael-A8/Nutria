<?php

namespace App\Nutrition\Application\Catalog\Lifecycle\Exceptions;

use RuntimeException;
use Throwable;

final class CatalogLifecycleTransitionPersistenceException extends RuntimeException
{
    public function __construct(Throwable $previous)
    {
        parent::__construct('The catalog lifecycle projection could not be persisted.', previous: $previous);
    }
}
