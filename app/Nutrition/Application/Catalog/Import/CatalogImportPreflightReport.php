<?php

namespace App\Nutrition\Application\Catalog\Import;

use App\Nutrition\Application\Catalog\Import\Exceptions\LegacyNutritionImportReviewException;
use App\Nutrition\Application\Catalog\Import\ValueObjects\CanonicalManifestChecksum;
use App\Nutrition\Application\Catalog\Import\ValueObjects\CatalogImportPreflightResult;
use App\Nutrition\Application\Catalog\Import\ValueObjects\LegacyNutritionReviewManifest;
use JsonException;

final class CatalogImportPreflightReport
{
    public function render(
        LegacyNutritionReviewManifest $manifest,
        CatalogImportPreflightResult $preflight,
        CanonicalManifestChecksum $resolutionChecksum,
    ): string {
        $candidateEvidence = [];

        foreach (array_keys($preflight->matchesByCandidate + $preflight->conflictsByCandidate) as $sourceRecordKey) {
            $matches = $preflight->matchesFor($sourceRecordKey);
            $conflicts = $preflight->conflictsFor($sourceRecordKey);

            if ($matches !== [] || $conflicts !== []) {
                $candidateEvidence[] = [
                    'conflicts' => $conflicts,
                    'possible_exact_matches' => $matches,
                    'source_record_key' => $sourceRecordKey,
                ];
            }
        }

        usort(
            $candidateEvidence,
            fn (array $left, array $right): int => strcmp($left['source_record_key'], $right['source_record_key']),
        );

        try {
            return json_encode(
                [
                    'candidate_evidence' => $candidateEvidence,
                    'candidate_manifest' => [
                        'manifest_sha256' => $manifest->checksum->digest,
                        'source_sha256' => $manifest->sourceChecksum(),
                    ],
                    'catalog_counts' => $preflight->catalogCounts,
                    'conflict_counts' => $preflight->conflictCounts,
                    'evidence_counts' => $preflight->evidenceCounts,
                    'output_status' => 'prepared',
                    'read_only' => true,
                    'resolution_template_sha256' => $resolutionChecksum->digest,
                    'schema' => 'nutria.catalog-import-preflight/1',
                    'sql_verification' => [
                        'ddl_statements' => 0,
                        'query_count' => $preflight->queryCount,
                        'query_kinds' => $preflight->queryKinds,
                        'write_statements' => 0,
                    ],
                ],
                JSON_THROW_ON_ERROR
                    | JSON_PRETTY_PRINT
                    | JSON_UNESCAPED_SLASHES
                    | JSON_UNESCAPED_UNICODE,
            ).PHP_EOL;
        } catch (JsonException $exception) {
            throw new LegacyNutritionImportReviewException(
                'The catalog preflight report could not be encoded.',
                previous: $exception,
            );
        }
    }
}
