<?php

namespace App\Nutrition\Domain\Resolution\Contracts;

use App\Nutrition\Domain\Resolution\ValueObjects\FoodResolution;
use App\Nutrition\Domain\Resolution\ValueObjects\FoodResolutionRequest;

interface MealComponentResolver
{
    public function resolve(FoodResolutionRequest $request): FoodResolution;
}
