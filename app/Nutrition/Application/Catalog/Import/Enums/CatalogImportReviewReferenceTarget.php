<?php

namespace App\Nutrition\Application\Catalog\Import\Enums;

enum CatalogImportReviewReferenceTarget: string
{
    case Unresolved = 'unresolved';
    case NewReference = 'new_reference';
    case ExistingReference = 'existing_reference';
}
