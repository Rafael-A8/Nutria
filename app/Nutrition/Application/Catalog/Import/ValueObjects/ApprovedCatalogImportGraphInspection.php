<?php

namespace App\Nutrition\Application\Catalog\Import\ValueObjects;

use App\Nutrition\Application\Catalog\Import\Enums\ApprovedCatalogImportGraphState;

final readonly class ApprovedCatalogImportGraphInspection
{
    /**
     * @param  array<string, string>  $graphFingerprints
     */
    public function __construct(
        public ApprovedCatalogImportGraphState $state,
        public array $graphFingerprints,
        public string $catalogPreflightFingerprint,
    ) {}
}
