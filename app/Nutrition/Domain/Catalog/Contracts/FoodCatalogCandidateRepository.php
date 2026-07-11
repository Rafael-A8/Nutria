<?php

namespace App\Nutrition\Domain\Catalog\Contracts;

use App\Nutrition\Domain\Catalog\ValueObjects\FoodResolutionCandidate;
use App\Nutrition\Domain\Catalog\ValueObjects\NormalizedFoodText;

interface FoodCatalogCandidateRepository
{
    /**
     * Retrieves exact normalized canonical-name and alias matches visible to the requested owner scope.
     *
     * Inactive or unreviewed visible matches remain represented by candidate eligibility fields.
     * The repository never resolves ambiguity or returns another owner's private candidate facts.
     * Retrieval is catalog-only: it never reads meal history, embeddings, RAG, memories, or AI output,
     * and it never calculates calories or converts quantities.
     *
     * @return list<FoodResolutionCandidate>
     */
    public function findExactCandidates(
        NormalizedFoodText $foodText,
        string $locale,
        int|string|null $catalogOwnerId = null,
    ): array;

    /**
     * Reports only that an exact lexical match was excluded by owner scope, without exposing private candidate facts.
     */
    public function hasExactMatchExcludedByOwnerScope(
        NormalizedFoodText $foodText,
        string $locale,
        int|string|null $catalogOwnerId = null,
    ): bool;
}
