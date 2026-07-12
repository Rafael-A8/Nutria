<?php

namespace App\Nutrition\Domain\Catalog\Lifecycle\Enums;

enum CatalogLifecycleSubjectType: string
{
    case Source = 'source';
    case Reference = 'reference';
    case ReferenceVersion = 'reference_version';
    case Alias = 'alias';
    case Portion = 'portion';
}
