<?php

namespace App\Nutrition\Domain\Enums;

enum PreparationType: string
{
    case Grilled = 'grilled';
    case Fried = 'fried';
    case Roasted = 'roasted';
    case Baked = 'baked';
    case Boiled = 'boiled';
    case Steamed = 'steamed';
    case Sauteed = 'sauteed';
    case Cooked = 'cooked';
    case Raw = 'raw';
    case Mixed = 'mixed';
    case Unknown = 'unknown';
}
