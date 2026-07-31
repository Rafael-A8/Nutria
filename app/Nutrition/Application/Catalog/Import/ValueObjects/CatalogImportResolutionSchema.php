<?php

namespace App\Nutrition\Application\Catalog\Import\ValueObjects;

use InvalidArgumentException;

final readonly class CatalogImportResolutionSchema
{
    public const IDENTIFIER = 'nutria.catalog-import-resolution/1';

    public function __construct(public string $value)
    {
        if ($value !== self::IDENTIFIER) {
            throw new InvalidArgumentException('The catalog import resolution schema is malformed or unsupported.');
        }
    }

    public static function current(): self
    {
        return new self(self::IDENTIFIER);
    }
}
