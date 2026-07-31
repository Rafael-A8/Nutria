<?php

namespace App\Nutrition\Application\Catalog\Import;

use App\Nutrition\Application\Catalog\Import\Enums\CatalogImportAliasReviewStatus;
use App\Nutrition\Application\Catalog\Import\Enums\CatalogImportOwnerDecisionStatus;
use App\Nutrition\Application\Catalog\Import\Enums\CatalogImportReviewPreparationStatus;
use App\Nutrition\Application\Catalog\Import\Enums\CatalogImportReviewReferenceTarget;
use App\Nutrition\Application\Catalog\Import\Exceptions\LegacyNutritionImportReviewException;
use App\Nutrition\Application\Catalog\Import\ValueObjects\CanonicalManifestChecksum;
use App\Nutrition\Application\Catalog\Import\ValueObjects\CatalogImportManifestSchema;
use App\Nutrition\Application\Catalog\Import\ValueObjects\CatalogImportPreflightResult;
use App\Nutrition\Application\Catalog\Import\ValueObjects\CatalogImportResolutionSchema;
use App\Nutrition\Application\Catalog\Import\ValueObjects\CatalogImportReviewPreparationResult;
use App\Nutrition\Application\Catalog\Import\ValueObjects\LegacyCatalogArtifactDescriptor;
use App\Nutrition\Application\Catalog\Import\ValueObjects\LegacyNutritionReviewManifest;
use JsonException;

final class CatalogImportReviewTemplateGenerator
{
    public function generate(
        LegacyNutritionReviewManifest $manifest,
        CatalogImportPreflightResult $preflight,
        CatalogImportPreflightReport $preflightReport,
    ): CatalogImportReviewPreparationResult {
        $reviewEntries = array_map(
            fn (array $record): array => $this->reviewEntry($record, $preflight),
            $manifest->records(),
        );
        usort(
            $reviewEntries,
            fn (array $left, array $right): int => strcmp($left['source_record_key'], $right['source_record_key']),
        );

        $resolutionDocument = [
            'candidate_manifest' => [
                'logical_artifact_id' => LegacyCatalogArtifactDescriptor::ARTIFACT_ID,
                'manifest_schema' => CatalogImportManifestSchema::IDENTIFIER,
                'manifest_sha256' => $manifest->checksum->digest,
                'source_sha256' => $manifest->sourceChecksum(),
            ],
            'review_entries' => $reviewEntries,
            'schema' => CatalogImportResolutionSchema::IDENTIFIER,
            'summary' => [
                'approved_apply_plan_records' => 0,
                'resolved_candidate_identities' => 0,
                'review_entries' => count($reviewEntries),
                'selected_candidates' => 0,
                'unresolved_candidate_identities' => count($reviewEntries),
            ],
        ];

        try {
            $canonicalBytes = CanonicalCatalogImportJson::serializeSemanticGraph($resolutionDocument);
        } catch (JsonException $exception) {
            throw new LegacyNutritionImportReviewException(
                'The editorial resolution template could not be serialized canonically.',
                previous: $exception,
            );
        }

        $resolutionChecksum = CanonicalManifestChecksum::fromCanonicalBytes($canonicalBytes);

        return new CatalogImportReviewPreparationResult(
            resolutionDocument: $resolutionDocument,
            canonicalResolutionBytes: $canonicalBytes,
            resolutionChecksum: $resolutionChecksum,
            preflight: $preflight,
            preflightReportBytes: $preflightReport->render(
                manifest: $manifest,
                preflight: $preflight,
                resolutionChecksum: $resolutionChecksum,
            ),
        );
    }

    /**
     * @param  array<string, mixed>  $record
     * @return array<string, mixed>
     */
    private function reviewEntry(array $record, CatalogImportPreflightResult $preflight): array
    {
        $aliasGroups = $record['aliases'];
        usort(
            $aliasGroups,
            fn (array $left, array $right): int => strcmp($left['normalized_alias'], $right['normalized_alias']),
        );
        $aliasDecisions = array_map(
            fn (array $aliasGroup): array => [
                'alias_kind' => null,
                'normalized_alias' => $aliasGroup['normalized_alias'],
                'raw_variants' => $aliasGroup['raw_variants'],
                'representative_raw_alias' => $aliasGroup['representative_raw_alias'],
                'source_alias_ordinals' => $aliasGroup['source_alias_ordinals'],
                'status' => CatalogImportAliasReviewStatus::Unresolved->value,
            ],
            $aliasGroups,
        );
        $conflictDecisions = array_map(
            fn (array $conflict): array => [
                ...$conflict,
                'editorial_resolution' => null,
                'resolution_status' => 'unresolved',
            ],
            $preflight->conflictsFor($record['source_record_key']),
        );

        return [
            'alias_decisions' => $aliasDecisions,
            'candidate_classification' => $record['candidate_classification'],
            'calorie_shape' => $record['calorie_representation'],
            'canonical_name' => $record['source_record_key'],
            'catalog_classification' => null,
            'collision_information' => $record['collision_information'],
            'confidence' => $record['confidence'],
            'declared_legacy_source' => [
                'authority_status' => 'untrusted',
                'declared_value' => $record['declared_source'],
                'evidence_role' => 'primary',
            ],
            'editorial_notes' => null,
            'existing_reference_public_id' => null,
            'is_generic' => null,
            'issue_codes' => $record['issue_codes'],
            'normalized_alias_groups' => $aliasGroups,
            'owner_user_id' => null,
            'owner_user_id_decision' => CatalogImportOwnerDecisionStatus::Unresolved->value,
            'planned_reference_uuid' => [
                'authority' => 'non_authoritative',
                'public_id' => $record['planning_identity']['public_id'],
            ],
            'possible_exact_catalog_matches' => $preflight->matchesFor($record['source_record_key']),
            'preflight_conflict_decisions' => $conflictDecisions,
            'preparation_decision' => [
                'preparation_key' => null,
                'status' => CatalogImportReviewPreparationStatus::Unresolved->value,
            ],
            'provenance' => $record['provenance'],
            'raw_aliases' => $record['raw_aliases_in_source_order'],
            'reference_target' => CatalogImportReviewReferenceTarget::Unresolved->value,
            'reference_visibility' => null,
            'selected_for_apply' => false,
            'source_ordinal' => $record['source_ordinal'],
            'source_record_key' => $record['source_record_key'],
            'stable_key' => null,
            'version_locale' => null,
        ];
    }
}
