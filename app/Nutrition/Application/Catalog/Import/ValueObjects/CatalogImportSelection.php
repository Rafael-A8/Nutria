<?php

namespace App\Nutrition\Application\Catalog\Import\ValueObjects;

final readonly class CatalogImportSelection
{
    public function __construct(public bool $selectedForApply) {}
}
