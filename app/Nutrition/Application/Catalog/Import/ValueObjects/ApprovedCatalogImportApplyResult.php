<?php

namespace App\Nutrition\Application\Catalog\Import\ValueObjects;

use App\Nutrition\Application\Catalog\Import\Enums\ApprovedCatalogImportOutcome;

final readonly class ApprovedCatalogImportApplyResult
{
    /**
     * @param  array<string, string>  $graphFingerprints
     */
    public function __construct(
        public ApprovedCatalogImportOutcome $outcome,
        public string $message,
        public array $graphFingerprints = [],
    ) {}

    public function successful(): bool
    {
        return in_array($this->outcome, [
            ApprovedCatalogImportOutcome::Applied,
            ApprovedCatalogImportOutcome::NoOpReplay,
        ], true);
    }
}
