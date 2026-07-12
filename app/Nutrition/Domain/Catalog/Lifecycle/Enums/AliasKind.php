<?php

namespace App\Nutrition\Domain\Catalog\Lifecycle\Enums;

enum AliasKind: string
{
    case Common = 'common';
    case Generic = 'generic';
    case Regional = 'regional';
    case Brand = 'brand';
}
