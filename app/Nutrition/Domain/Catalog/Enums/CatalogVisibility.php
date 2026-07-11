<?php

namespace App\Nutrition\Domain\Catalog\Enums;

enum CatalogVisibility: string
{
    case Global = 'global';
    case Private = 'private';
}
