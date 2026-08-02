<?php

namespace App\Nutrition\Application\Catalog\Import;

use App\Nutrition\Application\Catalog\Import\Exceptions\LegacyNutritionApplyPlanException;
use App\Nutrition\Application\Catalog\Import\ValueObjects\CanonicalManifestChecksum;
use App\Nutrition\Application\Catalog\Import\ValueObjects\CatalogImportApplyPlanSnapshot;
use App\Nutrition\Application\Catalog\Import\ValueObjects\LegacyNutritionReviewManifest;
use App\Nutrition\Application\Catalog\Import\ValueObjects\LoadedCatalogImportApproval;
use App\Nutrition\Application\Catalog\Import\ValueObjects\LoadedCatalogImportReviewedResolution;
use JsonException;

final class CatalogImportApplyPlanReport
{
    /** @param array<string, int> $counts */
    public function render(
        LegacyNutritionReviewManifest $manifest,
        LoadedCatalogImportReviewedResolution $resolution,
        LoadedCatalogImportApproval $approval,
        CatalogImportApplyPlanSnapshot $snapshot,
        CanonicalManifestChecksum $applyPlanChecksum,
        array $counts,
    ): string {
        $report = [
            'apply_plan_sha256' => $applyPlanChecksum->digest,
            'approval_attestation_sha256' => $approval->checksum->digest,
            'catalog_preflight_fingerprint' => $snapshot->fingerprint,
            'counts' => [
                ...$counts,
                'total_candidates' => count($resolution->document['review_entries']),
            ],
            'final_status' => 'approved_apply_plan_generated',
            'manifest_sha256' => $manifest->checksum->digest,
            'resolution_sha256' => $resolution->checksum->digest,
            'schema' => 'nutria.catalog-import-apply-plan-report/1',
            'source_sha256' => $manifest->sourceChecksum(),
            'sql_verification' => [
                'ddl_statements' => 0,
                'lock_statements' => 0,
                'query_count' => $snapshot->queryCount,
                'query_kinds' => $snapshot->queryKinds,
                'statement_classes' => ['select'],
                'write_statements' => 0,
            ],
        ];

        try {
            return json_encode(
                $report,
                JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            )."\n";
        } catch (JsonException $exception) {
            throw new LegacyNutritionApplyPlanException('invalid_apply_plan', 'The operational apply-plan report could not be encoded.', $exception);
        }
    }
}
