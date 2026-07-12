<?php

namespace App\Nutrition\Domain\Catalog\Lifecycle\Contracts;

use App\Nutrition\Domain\Catalog\Lifecycle\ValueObjects\CatalogLifecycleCommand;
use App\Nutrition\Domain\Catalog\Lifecycle\ValueObjects\CatalogLifecycleResult;

interface CatalogLifecyclePolicy
{
    public function evaluate(
        CatalogLifecycleCommand $command,
        CatalogLifecycleSnapshot $snapshot,
    ): CatalogLifecycleResult;
}
