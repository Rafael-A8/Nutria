<?php

namespace App\Nutrition\Application\Catalog\Import\ValueObjects;

final readonly class LoadedLegacyNutritionSource
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public string $artifactPath,
        public string $rawBytes,
        public array $payload,
        public SourceArtifactChecksum $checksum,
    ) {}

    public function byteSize(): int
    {
        return strlen($this->rawBytes);
    }
}
