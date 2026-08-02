<?php

namespace App\Nutrition\Application\Catalog\Import\ValueObjects;

final readonly class CatalogImportApplyPlanResult
{
    /**
     * @param  array<string, mixed>  $plan
     * @param  array<string, int>  $counts
     */
    public function __construct(
        public array $plan,
        public string $canonicalPlanBytes,
        public CanonicalManifestChecksum $checksum,
        public string $reportBytes,
        public array $counts,
    ) {}
}
