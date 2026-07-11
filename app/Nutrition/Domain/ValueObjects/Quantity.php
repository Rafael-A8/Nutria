<?php

namespace App\Nutrition\Domain\ValueObjects;

use App\Nutrition\Domain\Enums\QuantitySource;
use App\Nutrition\Domain\Enums\QuantityType;
use InvalidArgumentException;

final readonly class Quantity
{
    public function __construct(
        public QuantityType $type,
        public QuantitySource $source,
        public ?float $amount = null,
        public ?string $unit = null,
        public ?float $gramAmount = null,
        public ?string $measureText = null,
        public ?string $sizeText = null,
        public ?float $packageFraction = null,
    ) {
        $this->validateExactQuantity();
        $this->validatePackageFraction();
        $this->validateHouseholdMeasure();
        $this->validateSizedUnit();
        $this->validateVagueQuantity();
        $this->validateGramAmount();
    }

    private function validateExactQuantity(): void
    {
        if ($this->type !== QuantityType::Exact) {
            return;
        }

        if ($this->amount === null || ! is_finite($this->amount) || $this->amount <= 0) {
            throw new InvalidArgumentException('An exact quantity requires a positive amount.');
        }

        if ($this->unit === null || trim($this->unit) === '') {
            throw new InvalidArgumentException('An exact quantity requires a unit.');
        }
    }

    private function validatePackageFraction(): void
    {
        if (
            $this->packageFraction !== null
            && (! is_finite($this->packageFraction) || $this->packageFraction < 0 || $this->packageFraction > 1)
        ) {
            throw new InvalidArgumentException('A package fraction must be between 0 and 1.');
        }

        if ($this->type === QuantityType::PackageFraction && $this->packageFraction === null) {
            throw new InvalidArgumentException('A package-fraction quantity requires a package fraction.');
        }
    }

    private function validateHouseholdMeasure(): void
    {
        if (
            $this->type === QuantityType::HouseholdMeasure
            && ($this->measureText === null || trim($this->measureText) === '')
        ) {
            throw new InvalidArgumentException('A household measure requires its original measure text.');
        }
    }

    private function validateSizedUnit(): void
    {
        if (
            $this->type === QuantityType::SizedUnit
            && ($this->sizeText === null || trim($this->sizeText) === '')
        ) {
            throw new InvalidArgumentException('A sized unit requires its size text.');
        }
    }

    private function validateVagueQuantity(): void
    {
        if ($this->type !== QuantityType::Vague) {
            return;
        }

        if (
            $this->gramAmount !== null
            || $this->amount !== null
            || $this->unit !== null
            || $this->packageFraction !== null
            || $this->sizeText !== null
        ) {
            throw new InvalidArgumentException('A vague quantity cannot carry a numeric, package, or size payload.');
        }
    }

    private function validateGramAmount(): void
    {
        if ($this->gramAmount === null) {
            return;
        }

        if ($this->type === QuantityType::Vague) {
            throw new InvalidArgumentException('A vague quantity cannot have a gram amount.');
        }

        if (! is_finite($this->gramAmount) || $this->gramAmount <= 0) {
            throw new InvalidArgumentException('A gram amount must be positive.');
        }

        if (
            $this->type !== QuantityType::Exact
            && $this->source !== QuantitySource::DeterministicallyConverted
        ) {
            throw new InvalidArgumentException('A gram amount requires an exact or deterministically converted quantity.');
        }
    }
}
