<?php

namespace App\Nutrition\Domain\Resolution\Enums;

enum FoodMatchKind: string
{
    case ExactCanonicalName = 'exact_canonical_name';
    case ExactAlias = 'exact_alias';
}
