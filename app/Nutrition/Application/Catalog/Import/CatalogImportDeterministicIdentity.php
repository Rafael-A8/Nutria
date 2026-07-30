<?php

namespace App\Nutrition\Application\Catalog\Import;

use App\Nutrition\Application\Catalog\Import\ValueObjects\CatalogImportLifecycleIdempotencyInput;
use App\Nutrition\Application\Catalog\Import\ValueObjects\LegacyCatalogArtifactDescriptor;
use InvalidArgumentException;

final class CatalogImportDeterministicIdentity
{
    public const ROOT_NAMESPACE = 'd83e65a3-da9f-5ff1-9c84-29ab6c208724';

    public const SOURCE_NAMESPACE = '3f41222a-363c-5994-add1-9e8b4bdbfe8d';

    public const REFERENCE_NAMESPACE = '7969d0f8-abc6-5f96-b381-a542b66fe2fd';

    public const REFERENCE_VERSION_NAMESPACE = 'a5aa9049-cf99-5362-96de-0f08aa4e5f77';

    public const ALIAS_LINEAGE_NAMESPACE = '021118d0-af97-5350-8bfd-d769e5631fbd';

    public const ALIAS_REVISION_NAMESPACE = '1765715a-577d-59ee-a6bd-884dde00e135';

    public const LIFECYCLE_IDEMPOTENCY_NAMESPACE = '08897a45-0e20-560e-9226-344391496c79';

    public static function sourceCanonicalName(): string
    {
        return 'v1|artifact|'.self::canonicalComponent(LegacyCatalogArtifactDescriptor::ARTIFACT_ID);
    }

    public static function plannedReferenceCanonicalName(string $sourceRecordKey): string
    {
        return self::sourceCanonicalName().'|record_key|'.self::canonicalComponent($sourceRecordKey);
    }

    public static function referenceVersionCanonicalName(string $referencePublicId, int $versionNumber): string
    {
        self::assertCanonicalUuid($referencePublicId);

        if ($versionNumber < 1) {
            throw new InvalidArgumentException('The reference version number must be positive.');
        }

        return 'v1|reference|'.self::canonicalComponent($referencePublicId).'|version|'.$versionNumber;
    }

    public static function aliasLineageCanonicalName(
        string $referencePublicId,
        string $locale,
        string $normalizedAlias,
    ): string {
        self::assertCanonicalUuid($referencePublicId);

        return 'v1|reference|'.self::canonicalComponent($referencePublicId)
            .'|locale|'.self::canonicalComponent($locale)
            .'|normalized_alias|'.self::canonicalComponent($normalizedAlias);
    }

    public static function aliasRevisionCanonicalName(string $lineageId, int $revisionNumber): string
    {
        self::assertCanonicalUuid($lineageId);

        if ($revisionNumber < 1) {
            throw new InvalidArgumentException('The alias revision number must be positive.');
        }

        return 'v1|lineage|'.self::canonicalComponent($lineageId).'|revision|'.$revisionNumber;
    }

    public static function lifecycleIdempotencyCanonicalName(CatalogImportLifecycleIdempotencyInput $input): string
    {
        $name = 'v1|manifest_sha256|'.self::canonicalComponent($input->manifestChecksum->digest)
            .'|subject_type|'.self::canonicalComponent($input->subjectType->value)
            .'|subject_public_id|'.self::canonicalComponent($input->subjectPublicId)
            .'|operation|'.self::canonicalComponent($input->operation->value)
            .'|actor_id|'.self::canonicalComponent($input->actorId)
            .'|actor_reference|'.self::canonicalComponent($input->actorReference);

        $name .= $input->normalizedReason === null
            ? '|reason|null'
            : '|reason|'.self::canonicalComponent($input->normalizedReason);

        return $name.'|occurred_at|'.self::canonicalComponent($input->occurredAtUtc());
    }

    public static function sourcePublicId(): string
    {
        return self::uuidV5(self::SOURCE_NAMESPACE, self::sourceCanonicalName());
    }

    public static function plannedReferencePublicId(string $sourceRecordKey): string
    {
        return self::uuidV5(self::REFERENCE_NAMESPACE, self::plannedReferenceCanonicalName($sourceRecordKey));
    }

    public static function referenceVersionPublicId(string $referencePublicId, int $versionNumber): string
    {
        return self::uuidV5(
            self::REFERENCE_VERSION_NAMESPACE,
            self::referenceVersionCanonicalName($referencePublicId, $versionNumber),
        );
    }

    public static function aliasLineageId(
        string $referencePublicId,
        string $locale,
        string $normalizedAlias,
    ): string {
        return self::uuidV5(
            self::ALIAS_LINEAGE_NAMESPACE,
            self::aliasLineageCanonicalName($referencePublicId, $locale, $normalizedAlias),
        );
    }

    public static function aliasRevisionPublicId(string $lineageId, int $revisionNumber): string
    {
        return self::uuidV5(
            self::ALIAS_REVISION_NAMESPACE,
            self::aliasRevisionCanonicalName($lineageId, $revisionNumber),
        );
    }

    public static function lifecycleIdempotencyKey(CatalogImportLifecycleIdempotencyInput $input): string
    {
        return self::uuidV5(
            self::LIFECYCLE_IDEMPOTENCY_NAMESPACE,
            self::lifecycleIdempotencyCanonicalName($input),
        );
    }

    public static function canonicalComponent(string $value): string
    {
        if (! mb_check_encoding($value, 'UTF-8')) {
            throw new InvalidArgumentException('UUID name components must be valid UTF-8.');
        }

        return strlen($value).':'.$value;
    }

    private static function uuidV5(string $namespace, string $name): string
    {
        self::assertCanonicalUuid($namespace);

        $namespaceBytes = hex2bin(str_replace('-', '', $namespace));

        if ($namespaceBytes === false) {
            throw new InvalidArgumentException('The UUID namespace could not be decoded.');
        }

        $hash = sha1($namespaceBytes.$name, true);
        $uuidBytes = substr($hash, 0, 16);
        $uuidBytes[6] = chr((ord($uuidBytes[6]) & 0x0F) | 0x50);
        $uuidBytes[8] = chr((ord($uuidBytes[8]) & 0x3F) | 0x80);
        $hex = bin2hex($uuidBytes);

        return substr($hex, 0, 8).'-'
            .substr($hex, 8, 4).'-'
            .substr($hex, 12, 4).'-'
            .substr($hex, 16, 4).'-'
            .substr($hex, 20, 12);
    }

    private static function assertCanonicalUuid(string $uuid): void
    {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $uuid) !== 1) {
            throw new InvalidArgumentException('A canonical UUID is required.');
        }
    }
}
