<?php

namespace App\Nutrition\Application\Catalog\Import\Enums;

enum ApprovedCatalogImportGraphState: string
{
    case Exact = 'exact';
    case AbsentAtApprovedSnapshot = 'absent_at_approved_snapshot';
    case Drift = 'drift';
    case Conflict = 'conflict';
}
