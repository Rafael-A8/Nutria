<?php

namespace App\Nutrition\Application\Catalog\Import\ValueObjects;

use InvalidArgumentException;

final readonly class ConceptualStableKey
{
    public function __construct(public string $value)
    {
        if (
            trim($value) === ''
            || trim($value) !== $value
            || ! mb_check_encoding($value, 'UTF-8')
            || mb_strlen($value, 'UTF-8') > 191
        ) {
            throw new InvalidArgumentException('The conceptual stable key must be nonblank, trimmed, valid UTF-8, and at most 191 characters.');
        }

        $lowercaseValue = mb_strtolower($value, 'UTF-8');

        if (
            str_contains($lowercaseValue, LegacyCatalogArtifactDescriptor::ARTIFACT_ID)
            || str_contains($lowercaseValue, 'config/nutrition.php')
            || str_starts_with($lowercaseValue, 'legacy_config:')
            || str_starts_with($lowercaseValue, 'legacy:')
        ) {
            throw new InvalidArgumentException('The conceptual stable key must be source-neutral.');
        }
    }
}
