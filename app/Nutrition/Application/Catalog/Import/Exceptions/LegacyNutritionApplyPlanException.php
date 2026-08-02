<?php

namespace App\Nutrition\Application\Catalog\Import\Exceptions;

use RuntimeException;
use Throwable;

final class LegacyNutritionApplyPlanException extends RuntimeException
{
    public function __construct(
        public readonly string $outcome,
        string $message,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, previous: $previous);
    }
}
