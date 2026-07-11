<?php

namespace App\Nutrition\Domain\Resolution\ValueObjects;

use App\Nutrition\Domain\Drafts\MealComponentDraft;
use InvalidArgumentException;

final readonly class FoodResolutionRequest
{
    public function __construct(
        public MealComponentDraft $component,
        public string $locale,
        public int|string|null $catalogOwnerId = null,
        public ?string $entryOriginalText = null,
    ) {
        if (trim($this->locale) === '') {
            throw new InvalidArgumentException('A food resolution request requires a locale.');
        }

        if (is_int($this->catalogOwnerId) && $this->catalogOwnerId <= 0) {
            throw new InvalidArgumentException('A numeric catalog owner identifier must be positive.');
        }

        if (is_string($this->catalogOwnerId) && trim($this->catalogOwnerId) === '') {
            throw new InvalidArgumentException('A catalog owner identifier cannot be blank.');
        }
    }
}
