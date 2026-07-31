<?php

use App\Nutrition\Application\Catalog\Import\ApprovedLegacyNutritionReviewManifestValidator;
use App\Nutrition\Application\Catalog\Import\CatalogImportPreflightReport;
use App\Nutrition\Application\Catalog\Import\CatalogImportResolutionDocumentValidator;
use App\Nutrition\Application\Catalog\Import\CatalogImportReviewEligibilityValidator;
use App\Nutrition\Application\Catalog\Import\CatalogImportReviewTemplateGenerator;
use App\Nutrition\Application\Catalog\Import\Exceptions\LegacyNutritionImportReviewException;
use App\Nutrition\Application\Catalog\Import\LegacyNutritionReviewManifestLoader;
use App\Nutrition\Application\Catalog\Import\ValueObjects\CanonicalManifestChecksum;
use App\Nutrition\Application\Catalog\Import\ValueObjects\CatalogImportPreflightResult;
use App\Nutrition\Application\Catalog\Import\ValueObjects\CatalogImportReviewPreparationResult;
use App\Nutrition\Application\Catalog\Import\ValueObjects\LegacyNutritionReviewManifest;

const APPROVED_MANIFEST_SHA256_M244A = '4e5e5c3c505fca1d613ef8c3dee6bd066cd28876a49cd1b47dd543d9b4996ee2';

function approvedManifestPathM244a(): string
{
    return dirname(__DIR__, 5).'/storage/app/m243-run-1-manifest.json';
}

/** @return array<string, mixed> */
function approvedManifestArrayM244a(): array
{
    return json_decode(
        file_get_contents(approvedManifestPathM244a()),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
}

function loadedApprovedManifestM244a(): LegacyNutritionReviewManifest
{
    return (new LegacyNutritionReviewManifestLoader(
        new ApprovedLegacyNutritionReviewManifestValidator,
    ))->load(approvedManifestPathM244a(), APPROVED_MANIFEST_SHA256_M244A);
}

function emptyPreflightM244a(): CatalogImportPreflightResult
{
    $candidateKeys = array_column(loadedApprovedManifestM244a()->records(), 'source_record_key');

    return new CatalogImportPreflightResult(
        catalogCounts: [
            'aliases' => 0,
            'reference_version_sources' => 0,
            'reference_versions' => 0,
            'references' => 0,
            'sources' => 0,
        ],
        matchesByCandidate: array_fill_keys($candidateKeys, []),
        conflictsByCandidate: array_fill_keys($candidateKeys, []),
        evidenceCounts: [
            'normalized_alias' => 0,
            'normalized_canonical_name' => 0,
            'public_uuid' => 0,
            'stable_key' => 0,
            'total' => 0,
        ],
        conflictCounts: [
            'immutable_field' => 0,
            'public_uuid' => 0,
            'stable_key' => 0,
            'total' => 0,
        ],
        queryCount: 7,
        queryKinds: [
            'count_aliases',
            'count_reference_version_sources',
            'count_reference_versions',
            'count_references',
            'count_sources',
            'exact_current_canonical_name_matches',
            'exact_current_alias_matches',
        ],
    );
}

function reviewPacketM244a(): CatalogImportReviewPreparationResult
{
    return (new CatalogImportReviewTemplateGenerator)->generate(
        loadedApprovedManifestM244a(),
        emptyPreflightM244a(),
        new CatalogImportPreflightReport,
    );
}

/** @return array<string, mixed> */
function resolvedReviewEntryM244a(): array
{
    return [
        'alias_decisions' => [[
            'alias_kind' => 'common',
            'normalized_alias' => 'leite condensado',
            'raw_variants' => ['leite condensado'],
            'status' => 'include',
        ]],
        'candidate_classification' => 'suspicious_candidate',
        'catalog_classification' => 'dairy_product',
        'existing_reference_public_id' => null,
        'is_generic' => false,
        'owner_user_id' => null,
        'owner_user_id_decision' => 'explicit_null',
        'preflight_conflict_decisions' => [],
        'preparation_decision' => [
            'preparation_key' => null,
            'status' => 'explicit_null',
        ],
        'reference_target' => 'new_reference',
        'reference_visibility' => 'global',
        'selected_for_apply' => false,
        'stable_key' => 'dairy-condensed-milk',
        'version_locale' => 'pt-BR',
    ];
}

it('accepts only the exact historical M2.4.3 candidate manifest bytes', function () {
    $loaded = loadedApprovedManifestM244a();

    expect(hash('sha256', $loaded->canonicalBytes))->toBe(APPROVED_MANIFEST_SHA256_M244A)
        ->and(strlen($loaded->canonicalBytes))->toBe(228137)
        ->and($loaded->manifest['schema'])->toBe('nutria.catalog-import-manifest/1')
        ->and($loaded->sourceChecksum())
        ->toBe('0d3987cc616b40e878731ecda0127a5b0a065f9557977b5b8f4ec0091d4ecc21')
        ->and($loaded->records())->toHaveCount(106);
});

it('rejects a candidate manifest checksum other than the approved checksum', function () {
    expect(fn () => (new LegacyNutritionReviewManifestLoader(
        new ApprovedLegacyNutritionReviewManifestValidator,
    ))->load(approvedManifestPathM244a(), str_repeat('0', 64)))
        ->toThrow(LegacyNutritionImportReviewException::class, 'approved M2.4.3 checksum');
});

it('fails closed for every frozen candidate-manifest drift', function (callable $mutate, string $message) {
    $manifest = approvedManifestArrayM244a();
    $mutate($manifest);

    expect(fn () => (new ApprovedLegacyNutritionReviewManifestValidator)->validate($manifest))
        ->toThrow(LegacyNutritionImportReviewException::class, $message);
})->with([
    'unknown schema' => [
        fn (array &$manifest) => $manifest['schema'] = 'nutria.catalog-import-manifest/2',
        'schema',
    ],
    'candidate count' => [
        fn (array &$manifest) => array_pop($manifest['records']),
        '106 records',
    ],
    'source checksum' => [
        fn (array &$manifest) => $manifest['source']['checksum']['digest'] = str_repeat('0', 64),
        'source identity or checksum',
    ],
    'selected candidate' => [
        fn (array &$manifest) => $manifest['records'][0]['selected_for_apply'] = true,
        'unresolved and unselected',
    ],
    'resolved identity' => [
        fn (array &$manifest) => $manifest['records'][0]['identity_resolution']['status'] = 'resolved',
        'unresolved and unselected',
    ],
]);

it('generates one completely unresolved review entry per candidate', function () {
    $result = reviewPacketM244a();
    $document = $result->resolutionDocument;

    expect($document['schema'])->toBe('nutria.catalog-import-resolution/1')
        ->and($document['candidate_manifest'])->toBe([
            'logical_artifact_id' => 'legacy_config_nutrition_v1',
            'manifest_schema' => 'nutria.catalog-import-manifest/1',
            'manifest_sha256' => APPROVED_MANIFEST_SHA256_M244A,
            'source_sha256' => '0d3987cc616b40e878731ecda0127a5b0a065f9557977b5b8f4ec0091d4ecc21',
        ])
        ->and($document['review_entries'])->toHaveCount(106)
        ->and($document['summary'])->toBe([
            'approved_apply_plan_records' => 0,
            'resolved_candidate_identities' => 0,
            'review_entries' => 106,
            'selected_candidates' => 0,
            'unresolved_candidate_identities' => 106,
        ]);

    foreach ($document['review_entries'] as $entry) {
        expect($entry['reference_target'])->toBe('unresolved')
            ->and($entry['stable_key'])->toBeNull()
            ->and($entry['existing_reference_public_id'])->toBeNull()
            ->and($entry['reference_visibility'])->toBeNull()
            ->and($entry['owner_user_id'])->toBeNull()
            ->and($entry['owner_user_id_decision'])->toBe('unresolved')
            ->and($entry['is_generic'])->toBeNull()
            ->and($entry['version_locale'])->toBeNull()
            ->and($entry['catalog_classification'])->toBeNull()
            ->and($entry['preparation_decision'])->toBe([
                'preparation_key' => null,
                'status' => 'unresolved',
            ])
            ->and($entry['selected_for_apply'])->toBeFalse()
            ->and($entry)->not->toHaveKeys([
                'alias_lineage_id',
                'alias_public_id',
                'reference_version_public_id',
            ]);

        foreach ($entry['alias_decisions'] as $aliasDecision) {
            expect($aliasDecision['status'])->toBe('unresolved')
                ->and($aliasDecision['alias_kind'])->toBeNull();
        }
    }
});

it('preserves exact classification issues aliases calories provenance and planned UUID evidence', function () {
    $manifestRecord = collect(loadedApprovedManifestM244a()->records())
        ->firstWhere('source_record_key', 'leite condensado');
    $reviewEntry = collect(reviewPacketM244a()->resolutionDocument['review_entries'])
        ->firstWhere('source_record_key', 'leite condensado');

    expect($reviewEntry['candidate_classification'])->toBe($manifestRecord['candidate_classification'])
        ->and($reviewEntry['issue_codes'])->toBe($manifestRecord['issue_codes'])
        ->and($reviewEntry['raw_aliases'])->toBe($manifestRecord['raw_aliases_in_source_order'])
        ->and($reviewEntry['normalized_alias_groups'])->toBe($manifestRecord['aliases'])
        ->and($reviewEntry['calorie_shape'])->toBe($manifestRecord['calorie_representation'])
        ->and($reviewEntry['provenance'])->toBe($manifestRecord['provenance'])
        ->and($reviewEntry['planned_reference_uuid'])->toBe([
            'authority' => 'non_authoritative',
            'public_id' => $manifestRecord['planning_identity']['public_id'],
        ]);
});

it('produces byte-identical templates and checksums without execution data', function () {
    $first = reviewPacketM244a();
    $second = reviewPacketM244a();

    expect($second->canonicalResolutionBytes)->toBe($first->canonicalResolutionBytes)
        ->and($second->resolutionChecksum->digest)->toBe($first->resolutionChecksum->digest)
        ->and($first->canonicalResolutionBytes)->not->toContain(
            dirname(__DIR__, 5),
            'generated_at',
            'executed_at',
            'actor_id',
            'database_id',
            'internal_id',
        );
});

it('fails closed for resolution schemas and candidate-manifest checksum rebinding', function () {
    $document = reviewPacketM244a()->resolutionDocument;
    $validator = new CatalogImportResolutionDocumentValidator;
    $checksum = new CanonicalManifestChecksum('sha256', APPROVED_MANIFEST_SHA256_M244A);
    $validator->validate($document, $checksum);
    $document['schema'] = 'nutria.catalog-import-resolution/2';

    expect(fn () => $validator->validate($document, $checksum))
        ->toThrow(LegacyNutritionImportReviewException::class, 'schema');

    $document = reviewPacketM244a()->resolutionDocument;
    $document['candidate_manifest']['manifest_sha256'] = str_repeat('0', 64);

    expect(fn () => $validator->validate($document, $checksum))
        ->toThrow(LegacyNutritionImportReviewException::class, 'different candidate manifest');

    $document = reviewPacketM244a()->resolutionDocument;
    $document['candidate_manifest']['source_sha256'] = str_repeat('0', 64);

    expect(fn () => $validator->validate($document, $checksum))
        ->toThrow(LegacyNutritionImportReviewException::class, 'different candidate manifest');

    $document = reviewPacketM244a()->resolutionDocument;
    array_pop($document['review_entries']);

    expect(fn () => $validator->validate($document, $checksum))
        ->toThrow(LegacyNutritionImportReviewException::class, 'exactly 106');
});

it('keeps unresolved and classification-only entries ineligible', function () {
    $validator = new CatalogImportReviewEligibilityValidator;
    $entry = collect(reviewPacketM244a()->resolutionDocument['review_entries'])
        ->firstWhere('candidate_classification', 'valid_candidate');

    expect($validator->evaluate($entry)['eligible'])->toBeFalse()
        ->and($entry['candidate_classification'])->toBe('valid_candidate')
        ->and($entry['selected_for_apply'])->toBeFalse();
});

it('allows suspicious eligibility only after all decisions while never selecting automatically', function () {
    $result = (new CatalogImportReviewEligibilityValidator)->evaluate(resolvedReviewEntryM244a());

    expect($result)->toBe([
        'eligible' => true,
        'reasons' => [],
        'selected_for_apply' => false,
    ]);
});

it('never makes an invalid candidate eligible', function () {
    $entry = resolvedReviewEntryM244a();
    $entry['candidate_classification'] = 'invalid_candidate';
    $result = (new CatalogImportReviewEligibilityValidator)->evaluate($entry);

    expect($result['eligible'])->toBeFalse()
        ->and($result['reasons'])->toContain('invalid_candidate');
});

it('distinguishes unresolved preparation from an explicit null decision', function () {
    $validator = new CatalogImportReviewEligibilityValidator;
    $explicitNull = resolvedReviewEntryM244a();
    $unresolved = $explicitNull;
    $unresolved['preparation_decision'] = [
        'preparation_key' => null,
        'status' => 'unresolved',
    ];

    expect($validator->evaluate($explicitNull)['eligible'])->toBeTrue()
        ->and($validator->evaluate($unresolved)['reasons'])
        ->toContain('preparation_unresolved_or_invalid');
});

it('requires kinds only for included aliases', function () {
    $validator = new CatalogImportReviewEligibilityValidator;
    $includedWithoutKind = resolvedReviewEntryM244a();
    $includedWithoutKind['alias_decisions'][0]['alias_kind'] = null;
    $excluded = resolvedReviewEntryM244a();
    $excluded['alias_decisions'][0]['status'] = 'exclude';
    $excluded['alias_decisions'][0]['alias_kind'] = null;

    expect($validator->evaluate($includedWithoutKind)['eligible'])->toBeFalse()
        ->and($validator->evaluate($excluded)['eligible'])->toBeTrue();
});

it('requires the inherited explicit global null-owner decision', function () {
    $entry = resolvedReviewEntryM244a();
    $entry['owner_user_id'] = 123;
    $entry['owner_user_id_decision'] = 'resolved_value';
    $result = (new CatalogImportReviewEligibilityValidator)->evaluate($entry);

    expect($result['eligible'])->toBeFalse()
        ->and($result['reasons'])->toContain('owner_user_id_unresolved_or_unsupported');
});

it('enforces public UUID direction for existing and new targets', function () {
    $validator = new CatalogImportReviewEligibilityValidator;
    $existing = resolvedReviewEntryM244a();
    $existing['reference_target'] = 'existing_reference';
    $newWithExistingUuid = resolvedReviewEntryM244a();
    $newWithExistingUuid['existing_reference_public_id'] = 'aaaaaaaa-aaaa-5aaa-8aaa-aaaaaaaaaaaa';

    expect($validator->evaluate($existing)['reasons'])->toContain('existing_reference_public_id_required')
        ->and($validator->evaluate($newWithExistingUuid)['reasons'])
        ->toContain('new_reference_rejects_existing_public_id');

    $existing['existing_reference_public_id'] = 'aaaaaaaa-aaaa-5aaa-8aaa-aaaaaaaaaaaa';

    expect($validator->evaluate($existing)['eligible'])->toBeTrue();
});

it('rejects source-dependent commit-dependent and machine-path stable keys', function (string $stableKey) {
    $entry = resolvedReviewEntryM244a();
    $entry['stable_key'] = $stableKey;
    $result = (new CatalogImportReviewEligibilityValidator)->evaluate($entry);

    expect($result['reasons'])->toContain('stable_key_invalid_or_source_dependent');
})->with([
    'artifact id' => 'legacy_config_nutrition_v1:milk',
    'source kind' => 'legacy_config:milk',
    'source filename' => 'nutrition.php:milk',
    'repository commit' => 'milk-'.str_repeat('a', 40),
    'Linux path' => '/home/user/milk',
    'Windows path' => 'C:\\Users\\user\\milk',
]);

it('blocks immutable and unresolved preflight conflicts', function () {
    $entry = resolvedReviewEntryM244a();
    $entry['preflight_conflict_decisions'] = [[
        'immutable_field_conflict' => true,
        'resolution_status' => 'unresolved',
    ]];
    $result = (new CatalogImportReviewEligibilityValidator)->evaluate($entry);

    expect($result['eligible'])->toBeFalse()
        ->and($result['reasons'])->toContain(
            'immutable_field_conflict',
            'preflight_conflict_unresolved',
        );
});
