<?php

namespace App\Nutrition\Application\Catalog\Import\ValueObjects;

use App\Nutrition\Domain\Catalog\Lifecycle\Enums\AliasKind;
use InvalidArgumentException;

final readonly class CatalogImportAliasIdentity
{
    public function __construct(
        public string $normalizedAlias,
        public string $locale,
        public AliasKind $kind,
    ) {
        foreach ([$normalizedAlias, $locale] as $value) {
            if (
                trim($value) === ''
                || trim($value) !== $value
                || ! mb_check_encoding($value, 'UTF-8')
            ) {
                throw new InvalidArgumentException('Alias identity values must be nonblank, trimmed, and valid UTF-8.');
            }
        }
    }
}
