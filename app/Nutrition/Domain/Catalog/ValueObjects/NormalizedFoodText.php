<?php

namespace App\Nutrition\Domain\Catalog\ValueObjects;

use InvalidArgumentException;

final readonly class NormalizedFoodText
{
    public function __construct(
        public string $original,
        public string $value,
    ) {
        if (! mb_check_encoding($this->original, 'UTF-8') || ! mb_check_encoding($this->value, 'UTF-8')) {
            throw new InvalidArgumentException('Normalized food text must contain valid UTF-8.');
        }
    }
}
