<?php

namespace App\Nutrition\Application\Catalog\Import\ValueObjects;

final readonly class CatalogImportChecksums
{
    public function __construct(
        public SourceArtifactChecksum $source,
        public CanonicalManifestChecksum $manifest,
    ) {}
}
