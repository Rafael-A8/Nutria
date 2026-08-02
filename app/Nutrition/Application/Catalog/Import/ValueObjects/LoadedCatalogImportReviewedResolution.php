<?php

namespace App\Nutrition\Application\Catalog\Import\ValueObjects;

final readonly class LoadedCatalogImportReviewedResolution
{
    /**
     * @param  array<string, mixed>  $document
     * @param  list<array<string, mixed>>  $selectedEntries
     */
    public function __construct(
        public array $document,
        public string $canonicalBytes,
        public CanonicalManifestChecksum $checksum,
        public array $selectedEntries,
        public int $eligibleEntryCount,
    ) {}
}
