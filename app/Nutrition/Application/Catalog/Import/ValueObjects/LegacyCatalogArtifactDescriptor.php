<?php

namespace App\Nutrition\Application\Catalog\Import\ValueObjects;

use App\Nutrition\Domain\Catalog\Enums\CatalogVisibility;
use App\Nutrition\Domain\Catalog\Enums\FoodSourceAuthorityStatus;
use App\Nutrition\Domain\Catalog\Enums\FoodSourceKind;
use InvalidArgumentException;

final readonly class LegacyCatalogArtifactDescriptor
{
    public const ARTIFACT_ID = 'legacy_config_nutrition_v1';

    public CatalogVisibility $visibility;

    public ?int $ownerUserId;

    public FoodSourceKind $kind;

    public FoodSourceAuthorityStatus $authority;

    public function __construct(
        public string $title,
        public SourceArtifactChecksum $checksum,
        public string $artifactPath,
        public string $sourceFormat,
        public int $byteSize,
        public string $repositoryCommit,
    ) {
        $this->visibility = CatalogVisibility::Global;
        $this->ownerUserId = null;
        $this->kind = FoodSourceKind::LegacyConfig;
        $this->authority = FoodSourceAuthorityStatus::Untrusted;

        if (trim($title) === '' || trim($title) !== $title || ! mb_check_encoding($title, 'UTF-8')) {
            throw new InvalidArgumentException('The source title must be nonblank, trimmed, and valid UTF-8.');
        }

        if (
            trim($artifactPath) === ''
            || trim($artifactPath) !== $artifactPath
            || str_starts_with($artifactPath, '/')
            || str_contains($artifactPath, '\\')
            || preg_match('/^[A-Za-z]:/', $artifactPath) === 1
            || in_array('..', explode('/', $artifactPath), true)
        ) {
            throw new InvalidArgumentException('The artifact path must be a clean repository-relative path.');
        }

        if (preg_match('/^[a-z][a-z0-9_]*$/D', $sourceFormat) !== 1) {
            throw new InvalidArgumentException('The source format must be a stable lowercase identifier.');
        }

        if ($byteSize < 1) {
            throw new InvalidArgumentException('The source byte size must be positive.');
        }

        if (preg_match('/^[0-9a-f]{40}([0-9a-f]{24})?$/D', $repositoryCommit) !== 1) {
            throw new InvalidArgumentException('The repository commit must be a canonical 40- or 64-character hash.');
        }
    }

    /** @return array{artifact_id: string, artifact_path: string, byte_size: int, repository_commit: string, source_format: string} */
    public function metadata(): array
    {
        return [
            'artifact_id' => self::ARTIFACT_ID,
            'artifact_path' => $this->artifactPath,
            'byte_size' => $this->byteSize,
            'repository_commit' => $this->repositoryCommit,
            'source_format' => $this->sourceFormat,
        ];
    }

    /**
     * @return array{
     *     authority_status: string,
     *     checksum: array{algorithm: string, digest: string},
     *     kind: string,
     *     metadata: array{artifact_id: string, artifact_path: string, byte_size: int, repository_commit: string, source_format: string},
     *     owner_user_id: null,
     *     title: string,
     *     visibility: string
     * }
     */
    public function toCanonicalArray(): array
    {
        return [
            'authority_status' => $this->authority->value,
            'checksum' => [
                'algorithm' => $this->checksum->algorithm,
                'digest' => $this->checksum->digest,
            ],
            'kind' => $this->kind->value,
            'metadata' => $this->metadata(),
            'owner_user_id' => null,
            'title' => $this->title,
            'visibility' => $this->visibility->value,
        ];
    }
}
