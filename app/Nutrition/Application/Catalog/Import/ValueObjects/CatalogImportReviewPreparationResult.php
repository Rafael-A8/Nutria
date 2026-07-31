<?php

namespace App\Nutrition\Application\Catalog\Import\ValueObjects;

final readonly class CatalogImportReviewPreparationResult
{
    /** @param array<string, mixed> $resolutionDocument */
    public function __construct(
        public array $resolutionDocument,
        public string $canonicalResolutionBytes,
        public CanonicalManifestChecksum $resolutionChecksum,
        public CatalogImportPreflightResult $preflight,
        public string $preflightReportBytes,
    ) {}
}
