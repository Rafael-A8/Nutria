<?php

namespace App\Nutrition\Application\Catalog\Import\ValueObjects;

use InvalidArgumentException;

final readonly class CatalogImportManifestSchema
{
    public const IDENTIFIER = 'nutria.catalog-import-manifest/1';

    public function __construct(public string $value)
    {
        if ($value !== self::IDENTIFIER) {
            throw new InvalidArgumentException('The catalog import manifest schema is malformed or unsupported.');
        }
    }

    public static function current(): self
    {
        return new self(self::IDENTIFIER);
    }
}
