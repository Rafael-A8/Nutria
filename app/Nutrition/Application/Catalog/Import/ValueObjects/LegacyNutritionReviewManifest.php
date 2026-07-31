<?php

namespace App\Nutrition\Application\Catalog\Import\ValueObjects;

final readonly class LegacyNutritionReviewManifest
{
    /** @param array<string, mixed> $manifest */
    public function __construct(
        public array $manifest,
        public string $canonicalBytes,
        public CanonicalManifestChecksum $checksum,
    ) {}

    /** @return list<array<string, mixed>> */
    public function records(): array
    {
        return $this->manifest['records'];
    }

    public function sourceChecksum(): string
    {
        return $this->manifest['source']['checksum']['digest'];
    }
}
