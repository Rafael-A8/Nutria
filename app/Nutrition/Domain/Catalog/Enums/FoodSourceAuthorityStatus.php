<?php

namespace App\Nutrition\Domain\Catalog\Enums;

enum FoodSourceAuthorityStatus: string
{
    case Eligible = 'eligible';
    case Untrusted = 'untrusted';
    case Prohibited = 'prohibited';
}
