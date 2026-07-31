<?php

use App\Nutrition\Application\Catalog\Import\ApprovedLegacyNutritionReviewManifestValidator;
use App\Nutrition\Application\Catalog\Import\CanonicalCatalogImportJson;

const APPROVED_MANIFEST_PATH_M244A1 = 'resources/catalog-import/approved/legacy_config_nutrition_v1/candidate-manifest-4e5e5c3c505fca1d613ef8c3dee6bd066cd28876a49cd1b47dd543d9b4996ee2.json';
const APPROVED_MANIFEST_SHA256_M244A1 = '4e5e5c3c505fca1d613ef8c3dee6bd066cd28876a49cd1b47dd543d9b4996ee2';
const APPROVED_SOURCE_SHA256_M244A1 = '0d3987cc616b40e878731ecda0127a5b0a065f9557977b5b8f4ec0091d4ecc21';

it('preserves the exact approved candidate manifest as a durable tracked artifact', function () {
    $artifactPath = dirname(__DIR__, 5).'/'.APPROVED_MANIFEST_PATH_M244A1;

    expect(is_file($artifactPath))->toBeTrue();

    $artifactBytes = file_get_contents($artifactPath);

    expect($artifactBytes)->toBeString()
        ->and(strlen($artifactBytes))->toBe(228137)
        ->and(hash('sha256', $artifactBytes))->toBe(APPROVED_MANIFEST_SHA256_M244A1);

    $manifest = json_decode($artifactBytes, true, flags: JSON_THROW_ON_ERROR);
    $records = $manifest['records'];
    $unresolvedIdentities = array_filter(
        $records,
        fn (array $record): bool => ($record['identity_resolution']['status'] ?? null) === 'unresolved',
    );
    $selectedCandidates = array_filter(
        $records,
        fn (array $record): bool => ($record['selected_for_apply'] ?? null) === true,
    );

    expect($manifest['schema'])->toBe('nutria.catalog-import-manifest/1')
        ->and($manifest['source']['checksum']['digest'])->toBe(APPROVED_SOURCE_SHA256_M244A1)
        ->and($records)->toHaveCount(106)
        ->and($unresolvedIdentities)->toHaveCount(106)
        ->and($selectedCandidates)->toHaveCount(0)
        ->and(CanonicalCatalogImportJson::serializeManifest($manifest))->toBe($artifactBytes);

    (new ApprovedLegacyNutritionReviewManifestValidator)->validate($manifest);
});
