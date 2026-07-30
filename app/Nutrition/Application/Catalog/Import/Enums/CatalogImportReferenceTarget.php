<?php

namespace App\Nutrition\Application\Catalog\Import\Enums;

enum CatalogImportReferenceTarget: string
{
    case NewReference = 'new_reference';
    case ExistingReference = 'existing_reference';
}
