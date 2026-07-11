<?php

namespace App\Nutrition\Domain\Enums;

enum ComponentRole: string
{
    case PrimaryFood = 'primary_food';
    case Accompaniment = 'accompaniment';
    case CookingFat = 'cooking_fat';
    case Sauce = 'sauce';
    case Topping = 'topping';
    case Condiment = 'condiment';
    case Ingredient = 'ingredient';
    case Filling = 'filling';
    case BeverageComponent = 'beverage_component';
    case Unknown = 'unknown';
}
