<?php

namespace App\Nutrition\Application\Catalog\Lifecycle\Exceptions;

use App\Nutrition\Domain\Catalog\Lifecycle\Enums\CatalogLifecycleReason;
use RuntimeException;

final class CatalogLifecycleSubjectNotFoundException extends RuntimeException
{
    public readonly CatalogLifecycleReason $reason;

    public function __construct()
    {
        $this->reason = CatalogLifecycleReason::NotFound;

        parent::__construct('The catalog lifecycle subject was not found.');
    }
}
