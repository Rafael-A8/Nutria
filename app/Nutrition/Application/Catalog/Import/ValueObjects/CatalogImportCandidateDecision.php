<?php

namespace App\Nutrition\Application\Catalog\Import\ValueObjects;

use App\Nutrition\Application\Catalog\Import\Enums\CatalogImportCandidateClassification;
use InvalidArgumentException;

final readonly class CatalogImportCandidateDecision
{
    public function __construct(
        public CatalogImportCandidateClassification $classification,
        public CatalogImportIdentityResolution $identityResolution,
        public CatalogImportSelection $selection,
        public CatalogImportIssueSet $issues,
    ) {
        if (! $selection->selectedForApply) {
            return;
        }

        if ($classification === CatalogImportCandidateClassification::InvalidCandidate) {
            throw new InvalidArgumentException('Invalid catalog import candidates cannot be selected.');
        }

        if (! $identityResolution->isComplete()) {
            throw new InvalidArgumentException('Only candidates with completely resolved identity can be selected.');
        }
    }
}
