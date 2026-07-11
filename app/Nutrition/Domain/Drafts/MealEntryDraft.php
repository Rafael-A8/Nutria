<?php

namespace App\Nutrition\Domain\Drafts;

use App\Nutrition\Domain\ValueObjects\Quantity;

final readonly class MealEntryDraft
{
    /**
     * @param  list<MealComponentDraft>  $components
     */
    public function __construct(
        public string $originalText,
        public ?Quantity $quantity = null,
        public array $components = [],
    ) {}
}
