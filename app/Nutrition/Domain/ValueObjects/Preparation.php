<?php

namespace App\Nutrition\Domain\ValueObjects;

use App\Nutrition\Domain\Enums\PreparationType;

final readonly class Preparation
{
    public function __construct(public PreparationType $type) {}
}
