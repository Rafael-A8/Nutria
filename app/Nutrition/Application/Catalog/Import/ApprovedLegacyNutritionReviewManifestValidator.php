<?php

namespace App\Nutrition\Application\Catalog\Import;

use App\Nutrition\Application\Catalog\Import\Enums\CatalogImportCandidateClassification;
use App\Nutrition\Application\Catalog\Import\Enums\CatalogImportIdentityResolutionStatus;
use App\Nutrition\Application\Catalog\Import\Enums\CatalogImportIssueCode;
use App\Nutrition\Application\Catalog\Import\Exceptions\LegacyNutritionImportReviewException;
use App\Nutrition\Application\Catalog\Import\ValueObjects\CatalogImportManifestSchema;
use App\Nutrition\Application\Catalog\Import\ValueObjects\LegacyCatalogArtifactDescriptor;

final class ApprovedLegacyNutritionReviewManifestValidator
{
    public const SOURCE_SHA256 = '0d3987cc616b40e878731ecda0127a5b0a065f9557977b5b8f4ec0091d4ecc21';

    /** @param array<string, mixed> $manifest */
    public function validate(array $manifest): void
    {
        if (($manifest['schema'] ?? null) !== CatalogImportManifestSchema::IDENTIFIER) {
            throw new LegacyNutritionImportReviewException('The candidate manifest schema is malformed or unsupported.');
        }

        if (
            ($manifest['logical_artifact']['artifact_id'] ?? null) !== LegacyCatalogArtifactDescriptor::ARTIFACT_ID
            || ($manifest['source']['checksum']['algorithm'] ?? null) !== 'sha256'
            || ($manifest['source']['checksum']['digest'] ?? null) !== self::SOURCE_SHA256
        ) {
            throw new LegacyNutritionImportReviewException('The candidate manifest source identity or checksum has drifted.');
        }

        if (($manifest['extraction_summary'] ?? null) != $this->expectedInventory()) {
            throw new LegacyNutritionImportReviewException('The candidate manifest frozen inventory has drifted.');
        }

        $records = $manifest['records'] ?? null;

        if (! is_array($records) || ! array_is_list($records) || count($records) !== 106) {
            throw new LegacyNutritionImportReviewException('The candidate manifest must contain exactly 106 records.');
        }

        $sourceRecordKeys = [];
        $sourceOrdinals = [];
        $classificationCounts = array_fill_keys(
            array_column(CatalogImportCandidateClassification::cases(), 'value'),
            0,
        );
        $allowedIssues = array_column(CatalogImportIssueCode::cases(), 'value');

        foreach ($records as $record) {
            if (! is_array($record) || array_is_list($record)) {
                throw new LegacyNutritionImportReviewException('Every candidate manifest record must be an object.');
            }

            $sourceRecordKey = $record['source_record_key'] ?? null;
            $sourceOrdinal = $record['source_ordinal'] ?? null;
            $classification = $record['candidate_classification'] ?? null;

            if (
                ! is_string($sourceRecordKey)
                || trim($sourceRecordKey) === ''
                || ! is_int($sourceOrdinal)
                || $sourceOrdinal < 1
                || isset($sourceRecordKeys[$sourceRecordKey])
                || isset($sourceOrdinals[$sourceOrdinal])
            ) {
                throw new LegacyNutritionImportReviewException('Candidate manifest record identities are malformed or duplicated.');
            }

            if (! is_string($classification) || ! array_key_exists($classification, $classificationCounts)) {
                throw new LegacyNutritionImportReviewException('A candidate classification is malformed or unsupported.');
            }

            $sourceRecordKeys[$sourceRecordKey] = true;
            $sourceOrdinals[$sourceOrdinal] = true;
            $classificationCounts[$classification]++;

            if (
                ($record['identity_resolution']['status'] ?? null) !== CatalogImportIdentityResolutionStatus::Unresolved->value
                || ($record['selected_for_apply'] ?? null) !== false
                || ($record['identity_resolution']['alias_identities'] ?? null) !== []
            ) {
                throw new LegacyNutritionImportReviewException('Every input candidate identity must remain unresolved and unselected.');
            }

            foreach ([
                'classification',
                'existing_reference_public_id',
                'is_generic',
                'preparation',
                'reference_target',
                'reference_visibility',
                'stable_key',
                'version_locale',
            ] as $unresolvedField) {
                if (($record['identity_resolution'][$unresolvedField] ?? null) !== null) {
                    throw new LegacyNutritionImportReviewException('Input candidate identity decisions must not be pre-populated.');
                }
            }

            if (
                ! is_array($record['issue_codes'] ?? null)
                || ! array_is_list($record['issue_codes'])
                || array_diff($record['issue_codes'], $allowedIssues) !== []
                || ($record['planning_identity']['authority'] ?? null) !== 'non_authoritative'
                || ($record['planning_identity']['kind'] ?? null) !== 'planned_new_reference_uuid'
            ) {
                throw new LegacyNutritionImportReviewException('Candidate issue or planning identity evidence is malformed.');
            }

            foreach ($record['aliases'] ?? [] as $alias) {
                if (
                    ! is_array($alias)
                    || ! is_string($alias['normalized_alias'] ?? null)
                    || ! is_array($alias['raw_variants'] ?? null)
                ) {
                    throw new LegacyNutritionImportReviewException('Candidate normalized alias evidence is malformed.');
                }
            }
        }

        $actualOrdinals = array_keys($sourceOrdinals);
        sort($actualOrdinals);

        if (
            $actualOrdinals !== range(1, 106)
            || $classificationCounts !== [
                'valid_candidate' => 3,
                'suspicious_candidate' => 103,
                'invalid_candidate' => 0,
            ]
        ) {
            throw new LegacyNutritionImportReviewException('Candidate ordering or classification inventory has drifted.');
        }
    }

    /** @return array<string, mixed> */
    private function expectedInventory(): array
    {
        return [
            'aliases' => [
                'duplicate_normalized_occurrences' => 36,
                'normalized' => 191,
                'raw' => 227,
            ],
            'calorie_shapes' => [
                'calories_per_100g' => 90,
                'default_calories' => 16,
                'valid_explicit_portions' => 0,
            ],
            'candidates' => [
                'valid_candidate' => 3,
                'suspicious_candidate' => 103,
                'invalid_candidate' => 0,
                'total' => 106,
            ],
            'collisions' => [
                'cross_candidate_groups' => 0,
                'normalized_groups' => 32,
                'references_containing_groups' => 26,
            ],
            'identity_resolution' => [
                'planned_child_identities' => 0,
                'planned_new_reference_identities' => 106,
                'planned_source_identities' => 1,
                'selected' => 0,
                'unresolved' => 106,
            ],
            'source_declarations' => [
                'missing' => 95,
                'taco' => 10,
                'app_estimate' => 1,
            ],
        ];
    }
}
