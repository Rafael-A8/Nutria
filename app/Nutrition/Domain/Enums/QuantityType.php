<?php

namespace App\Nutrition\Domain\Enums;

enum QuantityType: string
{
    case Exact = 'exact';
    case HouseholdMeasure = 'household_measure';
    case SizedUnit = 'sized_unit';
    case Vague = 'vague';
    case EntryTotal = 'entry_total';
    case PackageFraction = 'package_fraction';
}
