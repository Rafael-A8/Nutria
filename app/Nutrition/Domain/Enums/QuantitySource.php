<?php

namespace App\Nutrition\Domain\Enums;

enum QuantitySource: string
{
    case UserReported = 'user_reported';
    case DeterministicallyConverted = 'deterministically_converted';
    case UserClarified = 'user_clarified';
    case SystemAssumed = 'system_assumed';
}
