<?php

namespace App\Nutrition\Application\Catalog\Import\ValueObjects;

final readonly class LegacyNutritionPlanningResult
{
    /**
     * @param  array<string, mixed>  $manifest
     * @param  array<string, mixed>  $summary
     */
    public function __construct(
        public array $manifest,
        public string $canonicalManifestBytes,
        public CanonicalManifestChecksum $manifestChecksum,
        public array $summary,
    ) {}
}
