<?php

namespace App\Nutrition\Application\Catalog\Import\Enums;

enum CatalogImportOwnerDecisionStatus: string
{
    case Unresolved = 'unresolved';
    case ExplicitNull = 'explicit_null';
    case ResolvedValue = 'resolved_value';
}
