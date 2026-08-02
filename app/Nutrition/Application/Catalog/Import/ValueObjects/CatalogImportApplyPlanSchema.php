<?php

namespace App\Nutrition\Application\Catalog\Import\ValueObjects;

use InvalidArgumentException;

final readonly class CatalogImportApplyPlanSchema
{
    public const IDENTIFIER = 'nutria.catalog-import-apply-plan/1';

    public function __construct(public string $identifier)
    {
        if ($identifier !== self::IDENTIFIER) {
            throw new InvalidArgumentException('Unsupported catalog import apply-plan schema.');
        }
    }
}
