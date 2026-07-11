<?php

namespace App\Nutrition\Domain\Drafts;

use DateTimeImmutable;

final readonly class MealDraft
{
    /**
     * @param  list<MealEntryDraft>  $entries
     */
    public function __construct(
        public ?string $mealType = null,
        public ?DateTimeImmutable $consumedAt = null,
        public array $entries = [],
    ) {}
}
