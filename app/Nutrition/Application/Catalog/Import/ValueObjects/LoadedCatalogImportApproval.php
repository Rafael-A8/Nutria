<?php

namespace App\Nutrition\Application\Catalog\Import\ValueObjects;

final readonly class LoadedCatalogImportApproval
{
    /** @param array<string, mixed> $document */
    public function __construct(
        public array $document,
        public string $canonicalBytes,
        public CanonicalManifestChecksum $checksum,
    ) {}
}
