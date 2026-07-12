<?php

namespace App\Nutrition\Domain\Catalog\Enums;

enum FoodReferenceVersionSourceRole: string
{
    case Primary = 'primary';
    case Supporting = 'supporting';
}
