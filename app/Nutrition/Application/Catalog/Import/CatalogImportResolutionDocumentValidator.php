<?php

namespace App\Nutrition\Application\Catalog\Import;

use App\Nutrition\Application\Catalog\Import\Enums\CatalogImportAliasReviewStatus;
use App\Nutrition\Application\Catalog\Import\Enums\CatalogImportReviewPreparationStatus;
use App\Nutrition\Application\Catalog\Import\Exceptions\LegacyNutritionImportReviewException;
use App\Nutrition\Application\Catalog\Import\ValueObjects\CanonicalManifestChecksum;
use App\Nutrition\Application\Catalog\Import\ValueObjects\CatalogImportManifestSchema;
use App\Nutrition\Application\Catalog\Import\ValueObjects\CatalogImportResolutionSchema;
use App\Nutrition\Application\Catalog\Import\ValueObjects\LegacyCatalogArtifactDescriptor;
use InvalidArgumentException;

final class CatalogImportResolutionDocumentValidator
{
    /** @param array<string, mixed> $document */
    public function validate(array $document, CanonicalManifestChecksum $expectedManifestChecksum): void
    {
        try {
            new CatalogImportResolutionSchema(
                is_string($document['schema'] ?? null) ? $document['schema'] : '',
            );
        } catch (InvalidArgumentException $exception) {
            throw new LegacyNutritionImportReviewException(
                'The editorial resolution schema is malformed or unsupported.',
                previous: $exception,
            );
        }

        $binding = $document['candidate_manifest'] ?? null;

        if (
            ! is_array($binding)
            || ($binding['manifest_schema'] ?? null) !== CatalogImportManifestSchema::IDENTIFIER
            || ($binding['logical_artifact_id'] ?? null) !== LegacyCatalogArtifactDescriptor::ARTIFACT_ID
            || ($binding['manifest_sha256'] ?? null) !== $expectedManifestChecksum->digest
            || ($binding['source_sha256'] ?? null) !== ApprovedLegacyNutritionReviewManifestValidator::SOURCE_SHA256
        ) {
            throw new LegacyNutritionImportReviewException(
                'The editorial resolution document is bound to a different candidate manifest.',
            );
        }

        $entries = $document['review_entries'] ?? null;

        if (! is_array($entries) || ! array_is_list($entries) || count($entries) !== 106) {
            throw new LegacyNutritionImportReviewException(
                'The editorial resolution document requires exactly 106 review entries.',
            );
        }

        $sourceRecordKeys = [];

        foreach ($entries as $entry) {
            if (! is_array($entry) || array_is_list($entry)) {
                throw new LegacyNutritionImportReviewException('Every editorial review entry must be an object.');
            }

            $sourceRecordKey = $entry['source_record_key'] ?? null;

            if (
                ! is_string($sourceRecordKey)
                || trim($sourceRecordKey) === ''
                || isset($sourceRecordKeys[$sourceRecordKey])
            ) {
                throw new LegacyNutritionImportReviewException(
                    'Editorial review source record keys must be nonblank and unique.',
                );
            }

            $sourceRecordKeys[$sourceRecordKey] = true;
            $this->assertPreparationContract($entry['preparation_decision'] ?? null);
            $this->assertAliasContracts($entry['alias_decisions'] ?? null);
        }
    }

    private function assertPreparationContract(mixed $preparation): void
    {
        if (! is_array($preparation) || array_is_list($preparation)) {
            throw new LegacyNutritionImportReviewException('Preparation decisions must be explicit objects.');
        }

        $status = CatalogImportReviewPreparationStatus::tryFrom(
            is_string($preparation['status'] ?? null) ? $preparation['status'] : '',
        );
        $preparationKey = $preparation['preparation_key'] ?? null;

        if (
            $status === null
            || ($status === CatalogImportReviewPreparationStatus::Unresolved && $preparationKey !== null)
            || ($status === CatalogImportReviewPreparationStatus::ExplicitNull && $preparationKey !== null)
            || ($status === CatalogImportReviewPreparationStatus::ResolvedValue
                && (! is_string($preparationKey) || trim($preparationKey) === ''))
        ) {
            throw new LegacyNutritionImportReviewException('The preparation decision contract is malformed.');
        }
    }

    private function assertAliasContracts(mixed $aliases): void
    {
        if (! is_array($aliases) || ! array_is_list($aliases) || $aliases === []) {
            throw new LegacyNutritionImportReviewException('Every review entry requires alias decisions.');
        }

        foreach ($aliases as $alias) {
            $status = is_array($alias)
                ? CatalogImportAliasReviewStatus::tryFrom(
                    is_string($alias['status'] ?? null) ? $alias['status'] : '',
                )
                : null;
            $aliasKind = is_array($alias) ? ($alias['alias_kind'] ?? null) : null;

            if (
                $status === null
                || ($status === CatalogImportAliasReviewStatus::Unresolved && $aliasKind !== null)
                || ($status === CatalogImportAliasReviewStatus::Exclude && $aliasKind !== null)
                || ($status === CatalogImportAliasReviewStatus::Include
                    && ! in_array($aliasKind, ['common', 'generic', 'brand'], true))
            ) {
                throw new LegacyNutritionImportReviewException('An alias review decision is malformed.');
            }
        }
    }
}
