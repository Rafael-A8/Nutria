<?php

namespace App\Nutrition\Domain\Catalog\Lifecycle\ValueObjects;

use App\Nutrition\Domain\Catalog\Lifecycle\Enums\CatalogLifecycleReason;
use InvalidArgumentException;

final readonly class CatalogEligibilityResult
{
    /**
     * @param  list<CatalogLifecycleReason>  $reasons
     */
    private function __construct(private array $reasons)
    {
        if (! array_is_list($this->reasons)) {
            throw new InvalidArgumentException('Eligibility reasons must be a list.');
        }

        $seenReasons = [];

        foreach ($this->reasons as $reason) {
            if (! $reason instanceof CatalogLifecycleReason) {
                throw new InvalidArgumentException('Eligibility reasons must use CatalogLifecycleReason values.');
            }

            if (isset($seenReasons[$reason->value])) {
                throw new InvalidArgumentException('Eligibility reasons cannot contain duplicates.');
            }

            $seenReasons[$reason->value] = true;
        }
    }

    public static function eligible(): self
    {
        return new self([]);
    }

    /**
     * @param  list<CatalogLifecycleReason>  $reasons
     */
    public static function ineligible(array $reasons): self
    {
        if ($reasons === []) {
            throw new InvalidArgumentException('An ineligible result requires at least one reason.');
        }

        return new self($reasons);
    }

    public function isEligible(): bool
    {
        return $this->reasons === [];
    }

    /** @return list<CatalogLifecycleReason> */
    public function reasons(): array
    {
        return $this->reasons;
    }

    public function firstReason(): ?CatalogLifecycleReason
    {
        return $this->reasons[0] ?? null;
    }
}
