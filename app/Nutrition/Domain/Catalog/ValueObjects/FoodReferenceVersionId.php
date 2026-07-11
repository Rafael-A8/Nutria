<?php

namespace App\Nutrition\Domain\Catalog\ValueObjects;

use InvalidArgumentException;

final readonly class FoodReferenceVersionId
{
    public function __construct(public string $value)
    {
        if (trim($this->value) === '') {
            throw new InvalidArgumentException('A food reference version identifier cannot be blank.');
        }
    }
}
