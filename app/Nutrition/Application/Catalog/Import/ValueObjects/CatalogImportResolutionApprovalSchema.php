<?php

namespace App\Nutrition\Application\Catalog\Import\ValueObjects;

use InvalidArgumentException;

final readonly class CatalogImportResolutionApprovalSchema
{
    public const IDENTIFIER = 'nutria.catalog-import-resolution-approval/1';

    public function __construct(public string $identifier)
    {
        if ($identifier !== self::IDENTIFIER) {
            throw new InvalidArgumentException('Unsupported catalog import resolution approval schema.');
        }
    }
}
