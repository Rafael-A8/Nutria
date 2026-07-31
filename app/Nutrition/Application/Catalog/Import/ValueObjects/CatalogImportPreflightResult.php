<?php

namespace App\Nutrition\Application\Catalog\Import\ValueObjects;

final readonly class CatalogImportPreflightResult
{
    /**
     * @param  array<string, int>  $catalogCounts
     * @param  array<string, list<array<string, mixed>>>  $matchesByCandidate
     * @param  array<string, list<array<string, mixed>>>  $conflictsByCandidate
     * @param  array<string, int>  $evidenceCounts
     * @param  array<string, int>  $conflictCounts
     * @param  list<string>  $queryKinds
     */
    public function __construct(
        public array $catalogCounts,
        public array $matchesByCandidate,
        public array $conflictsByCandidate,
        public array $evidenceCounts,
        public array $conflictCounts,
        public int $queryCount,
        public array $queryKinds,
    ) {}

    /** @return list<array<string, mixed>> */
    public function matchesFor(string $sourceRecordKey): array
    {
        return $this->matchesByCandidate[$sourceRecordKey] ?? [];
    }

    /** @return list<array<string, mixed>> */
    public function conflictsFor(string $sourceRecordKey): array
    {
        return $this->conflictsByCandidate[$sourceRecordKey] ?? [];
    }
}
