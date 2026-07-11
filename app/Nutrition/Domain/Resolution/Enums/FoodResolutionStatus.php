<?php

namespace App\Nutrition\Domain\Resolution\Enums;

enum FoodResolutionStatus: string
{
    case Resolved = 'resolved';
    case Ambiguous = 'ambiguous';
    case ClarificationRequired = 'clarification_required';
    case Unresolved = 'unresolved';
    case InvalidInput = 'invalid_input';
}
