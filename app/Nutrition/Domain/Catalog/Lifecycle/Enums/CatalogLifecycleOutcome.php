<?php

namespace App\Nutrition\Domain\Catalog\Lifecycle\Enums;

enum CatalogLifecycleOutcome: string
{
    case Succeeded = 'succeeded';
    case NoOp = 'no_op';
    case InvalidTransition = 'invalid_transition';
    case ValidationFailed = 'validation_failed';
    case Conflict = 'conflict';
}
