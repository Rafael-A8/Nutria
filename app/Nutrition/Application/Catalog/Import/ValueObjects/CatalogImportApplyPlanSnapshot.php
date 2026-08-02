<?php

namespace App\Nutrition\Application\Catalog\Import\ValueObjects;

final readonly class CatalogImportApplyPlanSnapshot
{
    /**
     * @param  array<string, int>  $catalogCounts
     * @param  array<string, mixed>|null  $source
     * @param  array<string, array<string, mixed>>  $referencesByPublicId
     * @param  array<string, array<string, mixed>>  $referencesByStableKey
     * @param  array<string, list<array<string, mixed>>>  $versionsByReferencePublicId
     * @param  array<string, list<array<string, mixed>>>  $aliasesByReferencePublicId
     * @param  array<string, list<array<string, mixed>>>  $sourceLinksByVersionPublicId
     * @param  list<string>  $queryKinds
     */
    public function __construct(
        public array $catalogCounts,
        public ?array $source,
        public array $referencesByPublicId,
        public array $referencesByStableKey,
        public array $versionsByReferencePublicId,
        public array $aliasesByReferencePublicId,
        public array $sourceLinksByVersionPublicId,
        public string $fingerprint,
        public int $queryCount,
        public array $queryKinds,
    ) {}
}
