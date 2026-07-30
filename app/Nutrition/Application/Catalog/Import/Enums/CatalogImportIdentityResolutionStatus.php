<?php

namespace App\Nutrition\Application\Catalog\Import\Enums;

enum CatalogImportIdentityResolutionStatus: string
{
    case Resolved = 'resolved';
    case Unresolved = 'unresolved';
    case Conflict = 'conflict';
}
