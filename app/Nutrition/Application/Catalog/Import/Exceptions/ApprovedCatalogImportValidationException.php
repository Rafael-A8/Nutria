<?php

namespace App\Nutrition\Application\Catalog\Import\Exceptions;

use App\Nutrition\Application\Catalog\Import\Enums\ApprovedCatalogImportOutcome;
use RuntimeException;
use Throwable;

final class ApprovedCatalogImportValidationException extends RuntimeException
{
    public function __construct(
        public readonly ApprovedCatalogImportOutcome $outcome,
        string $message,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, previous: $previous);
    }
}
