<?php

namespace App\Nutrition\Domain\Catalog\ValueObjects;

use App\Nutrition\Domain\Catalog\Enums\CatalogVisibility;
use App\Nutrition\Domain\Resolution\Enums\FoodMatchKind;
use App\Nutrition\Domain\Resolution\Enums\FoodResolutionReason;
use InvalidArgumentException;

final readonly class FoodResolutionCandidate
{
    /**
     * @param  list<FoodResolutionReason>  $diagnosticReasons
     */
    public function __construct(
        public FoodReferenceId $referenceId,
        public FoodReferenceVersionId $versionId,
        public string $canonicalName,
        public NormalizedFoodText $matchedText,
        public FoodMatchKind $matchKind,
        public CatalogVisibility $visibility,
        public int|string|null $catalogOwnerId,
        public bool $isGeneric,
        public string $classification,
        public ?string $matchedAliasIdentifier,
        public string $sourceIdentifier,
        public bool $isReferenceActive,
        public bool $isVersionActive,
        public bool $isVersionReviewed,
        public array $diagnosticReasons = [],
    ) {
        $this->validateRequiredText();
        $this->validateOwnerScope();
        $this->validateAliasIdentity();
        $this->validateDiagnosticReasons();
    }

    private function validateRequiredText(): void
    {
        if (trim($this->canonicalName) === '') {
            throw new InvalidArgumentException('A candidate requires a canonical name.');
        }

        if (trim($this->classification) === '') {
            throw new InvalidArgumentException('A candidate requires classification metadata.');
        }

        if (trim($this->sourceIdentifier) === '') {
            throw new InvalidArgumentException('A candidate requires a source identifier.');
        }
    }

    private function validateOwnerScope(): void
    {
        if (is_int($this->catalogOwnerId) && $this->catalogOwnerId <= 0) {
            throw new InvalidArgumentException('A numeric catalog owner identifier must be positive.');
        }

        if (is_string($this->catalogOwnerId) && trim($this->catalogOwnerId) === '') {
            throw new InvalidArgumentException('A catalog owner identifier cannot be blank.');
        }

        if ($this->visibility === CatalogVisibility::Private && $this->catalogOwnerId === null) {
            throw new InvalidArgumentException('A private candidate requires a catalog owner identifier.');
        }

        if ($this->visibility === CatalogVisibility::Global && $this->catalogOwnerId !== null) {
            throw new InvalidArgumentException('A global candidate cannot have a catalog owner identifier.');
        }
    }

    private function validateAliasIdentity(): void
    {
        if ($this->matchedAliasIdentifier !== null && trim($this->matchedAliasIdentifier) === '') {
            throw new InvalidArgumentException('A matched alias identifier cannot be blank.');
        }

        if ($this->matchKind === FoodMatchKind::ExactAlias && $this->matchedAliasIdentifier === null) {
            throw new InvalidArgumentException('An exact alias match requires an alias identifier.');
        }

        if ($this->matchKind === FoodMatchKind::ExactCanonicalName && $this->matchedAliasIdentifier !== null) {
            throw new InvalidArgumentException('An exact canonical-name match cannot have an alias identifier.');
        }
    }

    private function validateDiagnosticReasons(): void
    {
        foreach ($this->diagnosticReasons as $diagnosticReason) {
            if (! $diagnosticReason instanceof FoodResolutionReason) {
                throw new InvalidArgumentException('Candidate diagnostic reasons must use FoodResolutionReason values.');
            }
        }
    }
}
