<?php

namespace App\Nutrition\Application\Catalog\Import\Enums;

enum CatalogImportGraphOutcome: string
{
    case Planned = 'planned';
    case Unchanged = 'unchanged';
    case NoOp = 'no_op';
    case Conflict = 'conflict';

    public function representsExactPersistedMatch(): bool
    {
        return $this === self::Unchanged;
    }

    public function representsWriteFreeApplyResult(): bool
    {
        return $this === self::NoOp;
    }
}
