<?php

namespace App\Nutrition\Domain\Resolution\ValueObjects;

use App\Nutrition\Domain\Catalog\ValueObjects\FoodReferenceVersionId;
use App\Nutrition\Domain\Catalog\ValueObjects\NormalizedFoodText;
use App\Nutrition\Domain\Resolution\Enums\FoodMatchKind;
use App\Nutrition\Domain\Resolution\Enums\FoodResolutionReason;
use App\Nutrition\Domain\Resolution\Enums\FoodResolutionStatus;
use InvalidArgumentException;

final readonly class FoodResolutionTrace
{
    /**
     * @param  list<FoodMatchKind>  $lookupKindsAttempted
     * @param  list<FoodReferenceVersionId>  $candidateVersionIds
     * @param  list<FoodResolutionReason>  $filteringReasons
     */
    public function __construct(
        public string $policyVersion,
        public string $originalFoodText,
        public NormalizedFoodText $normalizedFoodText,
        public string $locale,
        public int|string|null $requestedCatalogOwnerId,
        public array $lookupKindsAttempted,
        public array $candidateVersionIds,
        public array $filteringReasons,
        public FoodResolutionStatus $finalStatus,
        public FoodResolutionReason $finalReason,
    ) {
        $this->validateRequiredText();
        $this->validateOwnerScope();
        $this->validateLists();
    }

    private function validateRequiredText(): void
    {
        if (trim($this->policyVersion) === '') {
            throw new InvalidArgumentException('A resolution trace requires a policy version.');
        }

        if (trim($this->locale) === '') {
            throw new InvalidArgumentException('A resolution trace requires a locale.');
        }
    }

    private function validateOwnerScope(): void
    {
        if (is_int($this->requestedCatalogOwnerId) && $this->requestedCatalogOwnerId <= 0) {
            throw new InvalidArgumentException('A numeric catalog owner identifier must be positive.');
        }

        if (is_string($this->requestedCatalogOwnerId) && trim($this->requestedCatalogOwnerId) === '') {
            throw new InvalidArgumentException('A catalog owner identifier cannot be blank.');
        }
    }

    private function validateLists(): void
    {
        foreach ($this->lookupKindsAttempted as $lookupKind) {
            if (! $lookupKind instanceof FoodMatchKind) {
                throw new InvalidArgumentException('Trace lookup kinds must use FoodMatchKind values.');
            }
        }

        foreach ($this->candidateVersionIds as $candidateVersionId) {
            if (! $candidateVersionId instanceof FoodReferenceVersionId) {
                throw new InvalidArgumentException('Trace candidate identifiers must use FoodReferenceVersionId values.');
            }
        }

        foreach ($this->filteringReasons as $filteringReason) {
            if (! $filteringReason instanceof FoodResolutionReason) {
                throw new InvalidArgumentException('Trace filtering reasons must use FoodResolutionReason values.');
            }
        }
    }
}
