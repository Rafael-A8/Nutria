<?php

namespace App\Nutrition\Application\Catalog\Import\Enums;

enum CatalogImportAliasReviewStatus: string
{
    case Unresolved = 'unresolved';
    case Include = 'include';
    case Exclude = 'exclude';
}
