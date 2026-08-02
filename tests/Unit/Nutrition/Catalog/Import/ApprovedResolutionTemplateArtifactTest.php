<?php

use App\Nutrition\Application\Catalog\Import\ApprovedLegacyNutritionReviewManifestValidator;
use App\Nutrition\Application\Catalog\Import\CanonicalCatalogImportJson;
use App\Nutrition\Application\Catalog\Import\CatalogImportResolutionDocumentValidator;
use App\Nutrition\Application\Catalog\Import\ValueObjects\CanonicalManifestChecksum;

require_once dirname(__DIR__, 4).'/Support/CatalogImportM244bFixtures.php';

it('preserves the exact unresolved M2.4.4a resolution template as a tracked canonical artifact', function () {
    $path = resolutionTemplatePathM244b();
    $bytes = file_get_contents($path);
    $document = json_decode($bytes, true, flags: JSON_THROW_ON_ERROR);

    expect(is_file($path))->toBeTrue()
        ->and(hash('sha256', $bytes))->toBe(RESOLUTION_TEMPLATE_SHA256_M244B)
        ->and(CanonicalCatalogImportJson::serializeSemanticGraph($document))->toBe($bytes)
        ->and($document['candidate_manifest'])->toBe([
            'logical_artifact_id' => 'legacy_config_nutrition_v1',
            'manifest_schema' => 'nutria.catalog-import-manifest/1',
            'manifest_sha256' => MANIFEST_SHA256_M244B,
            'source_sha256' => ApprovedLegacyNutritionReviewManifestValidator::SOURCE_SHA256,
        ])
        ->and($document['review_entries'])->toHaveCount(106)
        ->and(array_filter($document['review_entries'], fn (array $entry): bool => $entry['selected_for_apply']))->toBe([])
        ->and($document['summary'])->toBe([
            'approved_apply_plan_records' => 0,
            'resolved_candidate_identities' => 0,
            'review_entries' => 106,
            'selected_candidates' => 0,
            'unresolved_candidate_identities' => 106,
        ]);

    (new CatalogImportResolutionDocumentValidator)->validate(
        $document,
        new CanonicalManifestChecksum('sha256', MANIFEST_SHA256_M244B),
    );
});
