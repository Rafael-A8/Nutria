<?php

namespace App\Nutrition\Application\Catalog\Import\Enums;

enum CatalogImportCandidateClassification: string
{
    case ValidCandidate = 'valid_candidate';
    case SuspiciousCandidate = 'suspicious_candidate';
    case InvalidCandidate = 'invalid_candidate';
}
