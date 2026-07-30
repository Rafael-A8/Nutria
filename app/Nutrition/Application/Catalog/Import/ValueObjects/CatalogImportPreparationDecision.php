<?php

namespace App\Nutrition\Application\Catalog\Import\ValueObjects;

use InvalidArgumentException;

final readonly class CatalogImportPreparationDecision
{
    private function __construct(
        public ?string $preparationKey,
        public bool $preparationNeutral,
    ) {
        if ($preparationNeutral === ($preparationKey !== null)) {
            throw new InvalidArgumentException('Preparation must be explicitly keyed or explicitly neutral.');
        }

        if (
            $preparationKey !== null
            && (
                trim($preparationKey) === ''
                || trim($preparationKey) !== $preparationKey
                || ! mb_check_encoding($preparationKey, 'UTF-8')
            )
        ) {
            throw new InvalidArgumentException('The preparation key must be nonblank, trimmed, and valid UTF-8.');
        }
    }

    public static function keyed(string $preparationKey): self
    {
        return new self($preparationKey, false);
    }

    public static function neutral(): self
    {
        return new self(null, true);
    }
}
