<?php

namespace App\Nutrition\Application\Catalog\Import\ValueObjects;

final readonly class LoadedApprovedCatalogImportArtifacts
{
    /**
     * @param  array<string, mixed>  $applyPlan
     */
    public function __construct(
        public LoadedLegacyNutritionSource $source,
        public LegacyNutritionReviewManifest $manifest,
        public LoadedCatalogImportReviewedResolution $resolution,
        public LoadedCatalogImportApproval $approval,
        public array $applyPlan,
        public string $applyPlanChecksum,
    ) {}
}
