<?php

namespace App\Nutrition\Application\Catalog\Import\ValueObjects;

use App\Nutrition\Domain\Catalog\Enums\FoodReferenceVersionSourceRole;
use App\Nutrition\Domain\Catalog\Enums\FoodSourceAuthorityStatus;
use InvalidArgumentException;

final readonly class LegacyCatalogSourceLinkSemantics
{
    public function __construct(
        public FoodReferenceVersionSourceRole $role,
        public FoodSourceAuthorityStatus $authority,
    ) {
        if (
            $role !== FoodReferenceVersionSourceRole::Primary
            || $authority !== FoodSourceAuthorityStatus::Untrusted
        ) {
            throw new InvalidArgumentException('Legacy catalog evidence must be primary and untrusted.');
        }
    }

    public function isPrincipalEvidence(): bool
    {
        return true;
    }

    public function isTrusted(): bool
    {
        return false;
    }

    public function mayParticipateInDraftReview(): bool
    {
        return true;
    }

    public function isEligibleForActivation(): bool
    {
        return false;
    }
}
