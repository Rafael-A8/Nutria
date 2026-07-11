<?php

namespace App\Nutrition\Domain\Drafts;

use App\Nutrition\Domain\Enums\ComponentRole;
use App\Nutrition\Domain\Enums\QuantityType;
use App\Nutrition\Domain\ValueObjects\Preparation;
use App\Nutrition\Domain\ValueObjects\Quantity;
use InvalidArgumentException;

final readonly class MealComponentDraft
{
    /**
     * @param  list<Preparation>  $preparations
     */
    public function __construct(
        public string $originalText,
        public string $interpretedFoodText,
        public ComponentRole $role,
        public ?Quantity $quantity = null,
        public array $preparations = [],
    ) {
        if ($this->quantity?->type === QuantityType::EntryTotal) {
            throw new InvalidArgumentException('An entry-total quantity cannot belong to a meal component.');
        }
    }
}
