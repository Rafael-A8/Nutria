<?php

use App\Nutrition\Application\Catalog\Import\ApprovedLegacyNutritionReviewManifestValidator;
use App\Nutrition\Application\Catalog\Import\CanonicalCatalogImportJson;
use App\Nutrition\Application\Catalog\Import\CatalogImportApprovalAttestationLoader;
use App\Nutrition\Application\Catalog\Import\CatalogImportDeterministicIdentity;
use App\Nutrition\Application\Catalog\Import\CatalogImportResolutionDocumentValidator;
use App\Nutrition\Application\Catalog\Import\CatalogImportReviewedResolutionLoader;
use App\Nutrition\Application\Catalog\Import\CatalogImportReviewedResolutionValidator;
use App\Nutrition\Application\Catalog\Import\CatalogImportReviewEligibilityValidator;
use App\Nutrition\Application\Catalog\Import\LegacyNutritionReviewManifestLoader;
use App\Nutrition\Application\Catalog\Import\ValueObjects\CatalogImportApplyPlanSchema;
use App\Nutrition\Application\Catalog\Import\ValueObjects\LegacyNutritionReviewManifest;
use App\Nutrition\Application\Catalog\Import\ValueObjects\LoadedCatalogImportReviewedResolution;

const MANIFEST_SHA256_M244E = '4e5e5c3c505fca1d613ef8c3dee6bd066cd28876a49cd1b47dd543d9b4996ee2';
const RESOLUTION_TEMPLATE_SHA256_M244E = 'b9c1d4ae30c70208bf57bea51e6a6824886e129ecda20afe632ea3f47d28889e';
const REVIEWED_RESOLUTION_SHA256_M244E = '8eb9db29c044712134c4597220bdb7e61b19f186395c3dcc289cfe31c0054a5d';
const APPROVAL_SHA256_M244E = '9207cb1f556f0e5a9216e9ee9f651f446994c05ebf2cf7bd8893d4754d5ac105';
const APPLY_PLAN_SHA256_M244E = '3bb9c7348f6f7386b1cd7667af7cd26527dcf429481410d00684d8dae48a0afb';
const SOURCE_SHA256_M244E = '0d3987cc616b40e878731ecda0127a5b0a065f9557977b5b8f4ec0091d4ecc21';
const APPROVAL_REASON_M244E = 'Approved first controlled catalog batch after human review of the M2.4.4c proposal and the M2.4.4d canonical resolution. The three selected records are approved only for controlled draft import with legacy primary/untrusted evidence and without nutritional verification.';

function projectRootM244e(): string
{
    return dirname(__DIR__, 5);
}

function manifestPathM244e(): string
{
    return projectRootM244e().'/resources/catalog-import/approved/legacy_config_nutrition_v1/'
        .'candidate-manifest-'.MANIFEST_SHA256_M244E.'.json';
}

function resolutionTemplatePathM244e(): string
{
    return projectRootM244e().'/resources/catalog-import/review/legacy_config_nutrition_v1/'
        .'resolution-template-'.RESOLUTION_TEMPLATE_SHA256_M244E.'.json';
}

function reviewedResolutionPathM244e(): string
{
    return projectRootM244e().'/resources/catalog-import/review/legacy_config_nutrition_v1/'
        .'reviewed-resolution-'.REVIEWED_RESOLUTION_SHA256_M244E.'.json';
}

function approvalPathM244e(): string
{
    return projectRootM244e().'/resources/catalog-import/approval/legacy_config_nutrition_v1/'
        .'resolution-approval-'.APPROVAL_SHA256_M244E.'.json';
}

function applyPlanPathM244e(): string
{
    return projectRootM244e().'/resources/catalog-import/apply-plan/legacy_config_nutrition_v1/'
        .'apply-plan-'.APPLY_PLAN_SHA256_M244E.'.json';
}

function approvedManifestM244e(): LegacyNutritionReviewManifest
{
    return (new LegacyNutritionReviewManifestLoader(
        new ApprovedLegacyNutritionReviewManifestValidator,
    ))->load(manifestPathM244e(), MANIFEST_SHA256_M244E);
}

function reviewedResolutionM244e(): LoadedCatalogImportReviewedResolution
{
    return (new CatalogImportReviewedResolutionLoader(
        new CatalogImportReviewedResolutionValidator(
            new CatalogImportResolutionDocumentValidator,
            new CatalogImportReviewEligibilityValidator,
        ),
    ))->load(
        reviewedResolutionPathM244e(),
        REVIEWED_RESOLUTION_SHA256_M244E,
        resolutionTemplatePathM244e(),
        approvedManifestM244e(),
    );
}

/** @return list<string> */
function recursiveKeysM244e(mixed $value): array
{
    if (! is_array($value)) {
        return [];
    }

    $keys = [];

    foreach ($value as $key => $item) {
        if (is_string($key)) {
            $keys[] = $key;
        }

        $keys = [...$keys, ...recursiveKeysM244e($item)];
    }

    return array_values(array_unique($keys));
}

/** @return list<string> */
function recursiveUuidsM244e(mixed $value): array
{
    if (is_string($value) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $value) === 1) {
        return [$value];
    }

    if (! is_array($value)) {
        return [];
    }

    $uuids = [];

    foreach ($value as $item) {
        $uuids = [...$uuids, ...recursiveUuidsM244e($item)];
    }

    $uuids = array_values(array_unique($uuids));
    sort($uuids, SORT_STRING);

    return $uuids;
}

it('preserves the exact canonical first-batch approval attestation', function () {
    $path = approvalPathM244e();
    $bytes = file_get_contents($path);
    $document = json_decode($bytes, true, flags: JSON_THROW_ON_ERROR);
    $manifest = approvedManifestM244e();
    $resolution = reviewedResolutionM244e();
    $approval = (new CatalogImportApprovalAttestationLoader)->load(
        $path,
        APPROVAL_SHA256_M244E,
        $manifest,
        $resolution,
    );

    expect(is_file($path))->toBeTrue()
        ->and(basename($path))->toBe('resolution-approval-'.APPROVAL_SHA256_M244E.'.json')
        ->and(strlen($bytes))->toBe(685)
        ->and(hash('sha256', $bytes))->toBe(APPROVAL_SHA256_M244E)
        ->and(CanonicalCatalogImportJson::serializeSemanticGraph($document))->toBe($bytes)
        ->and($document)->toBe([
            'approval_reason' => APPROVAL_REASON_M244E,
            'approved_at' => '2026-08-02T19:56:00.000000Z',
            'candidate_manifest_sha256' => MANIFEST_SHA256_M244E,
            'logical_artifact_id' => 'legacy_config_nutrition_v1',
            'reviewed_resolution_sha256' => REVIEWED_RESOLUTION_SHA256_M244E,
            'reviewer_reference' => 'human-reviewer:project-owner',
            'schema' => 'nutria.catalog-import-resolution-approval/1',
        ])
        ->and($approval->canonicalBytes)->toBe($bytes)
        ->and($approval->document)->toBe($document)
        ->and($approval->checksum->digest)->toBe(APPROVAL_SHA256_M244E);
});

it('preserves the exact canonical first real apply plan', function () {
    $path = applyPlanPathM244e();
    $bytes = file_get_contents($path);
    $plan = json_decode($bytes, true, flags: JSON_THROW_ON_ERROR);
    $plansBySourceKey = [];

    foreach ($plan['selected_candidate_plans'] as $candidatePlan) {
        $plansBySourceKey[$candidatePlan['source_record_key']] = $candidatePlan;
    }

    $expectedCandidates = [
        'creme de leite' => [
            'alias_lineage_id' => '93b0b1c3-6a5d-540f-8c34-38b00be3d0e6',
            'alias_public_id' => 'c649dff7-5a65-55a2-83f8-7820356bae16',
            'energy_kcal' => 221,
            'reference_public_id' => '8e502950-ca1d-557d-8c26-c734b795f238',
            'stable_key' => 'creme-de-leite',
            'version_public_id' => '3fb0ee74-8421-5be3-84ce-db9a3aae95bb',
        ],
        'doce de leite' => [
            'alias_lineage_id' => 'ace8e351-2de6-5bb9-a12e-3933cbbae45e',
            'alias_public_id' => '050bfbe4-dc5c-5909-9c03-439a511104e9',
            'energy_kcal' => 306,
            'excluded_alias' => 'doce leite',
            'reference_public_id' => '0d3adf20-be25-5439-b89b-98e86e78c995',
            'stable_key' => 'doce-de-leite',
            'version_public_id' => 'bb86f7e2-e776-51d9-b503-d9430455035a',
        ],
        'leite condensado' => [
            'alias_lineage_id' => '63036985-520e-5cbb-8970-74fe9cb41d18',
            'alias_public_id' => 'c8a9ff8f-7cdf-578f-a372-109b36c5d880',
            'energy_kcal' => 313,
            'excluded_alias' => 'leite condesado',
            'reference_public_id' => '6e360841-51b8-5deb-a201-38debd0f03dc',
            'stable_key' => 'leite-condensado',
            'version_public_id' => '9d04432d-a814-5f9e-af1f-0165c6fc8dcf',
        ],
    ];
    $plannedAliasLineages = 0;
    $plannedAliasRevisions = 0;
    $excludedAliasGroups = 0;
    $lifecycleOperations = [$plan['source_plan']['lifecycle_operation_template']['operation']];

    expect(is_file($path))->toBeTrue()
        ->and(basename($path))->toBe('apply-plan-'.APPLY_PLAN_SHA256_M244E.'.json')
        ->and(strlen($bytes))->toBe(12465)
        ->and(hash('sha256', $bytes))->toBe(APPLY_PLAN_SHA256_M244E)
        ->and(CanonicalCatalogImportJson::serializeSemanticGraph($plan))->toBe($bytes)
        ->and($plan['schema'])->toBe('nutria.catalog-import-apply-plan/1')
        ->and(fn () => new CatalogImportApplyPlanSchema($plan['schema']))->not->toThrow(InvalidArgumentException::class)
        ->and($plan['candidate_manifest_sha256'])->toBe(MANIFEST_SHA256_M244E)
        ->and($plan['reviewed_resolution_sha256'])->toBe(REVIEWED_RESOLUTION_SHA256_M244E)
        ->and($plan['approval_attestation_sha256'])->toBe(APPROVAL_SHA256_M244E)
        ->and($plan['source_sha256'])->toBe(SOURCE_SHA256_M244E)
        ->and($plan['logical_artifact_id'])->toBe('legacy_config_nutrition_v1')
        ->and($plan['selected_candidate_count'])->toBe(3)
        ->and(array_column($plan['selected_candidate_plans'], 'source_record_key'))->toBe(array_keys($expectedCandidates))
        ->and($plan['catalog_preflight']['catalog_counts'])->toBe([
            'aliases' => 0,
            'reference_version_sources' => 0,
            'reference_versions' => 0,
            'references' => 0,
            'sources' => 0,
        ])
        ->and($plan['catalog_preflight']['fingerprint'])->toBe('9acc2620ad35c63d051f96333efdbcc5a8bdd8bde43fe085e4709626024ca8b6')
        ->and($plan['source_plan']['action'])->toBe('create')
        ->and($plan['source_plan']['outcome'])->toBe('planned')
        ->and($plan['source_plan']['lifecycle_operation_template'])->toBe([
            'operation' => 'create_source',
            'subject_public_id' => 'ead17ec3-6176-5f48-b25c-6f4ce3ce9907',
            'subject_type' => 'food_source',
        ])
        ->and($plan['source_plan']['semantic_entity'])->toMatchArray([
            'authority_status' => 'untrusted',
            'checksum' => SOURCE_SHA256_M244E,
            'checksum_algorithm' => 'sha256',
            'kind' => 'legacy_config',
            'owner_user_id' => null,
            'public_id' => 'ead17ec3-6176-5f48-b25c-6f4ce3ce9907',
            'visibility' => 'global',
        ])
        ->and($plan['source_plan']['semantic_entity'])->not->toHaveKey('code');

    foreach ($expectedCandidates as $sourceRecordKey => $expected) {
        $candidatePlan = $plansBySourceKey[$sourceRecordKey];
        $includedAliases = array_values(array_filter(
            $candidatePlan['alias_plans'],
            fn (array $aliasPlan): bool => $aliasPlan['action'] !== 'excluded',
        ));
        $excludedAliases = array_values(array_filter(
            $candidatePlan['alias_plans'],
            fn (array $aliasPlan): bool => $aliasPlan['action'] === 'excluded',
        ));
        $includedAlias = $includedAliases[0]['semantic_entity'];

        $plannedAliasLineages += count($includedAliases);
        $plannedAliasRevisions += count(array_filter(
            $includedAliases,
            fn (array $aliasPlan): bool => $aliasPlan['semantic_entity']['revision_number'] === 1,
        ));
        $excludedAliasGroups += count($excludedAliases);
        $lifecycleOperations = [
            ...$lifecycleOperations,
            ...array_column($candidatePlan['lifecycle_operation_templates'], 'operation'),
        ];

        expect($candidatePlan['candidate_classification'])->toBe('valid_candidate')
            ->and($candidatePlan['graph_outcome'])->toBe('planned')
            ->and($candidatePlan['reference_plan']['action'])->toBe('create')
            ->and($candidatePlan['reference_plan']['outcome'])->toBe('planned')
            ->and($candidatePlan['reference_plan']['semantic_entity'])->toMatchArray([
                'archived' => false,
                'is_generic' => true,
                'owner_user_id' => null,
                'public_id' => $expected['reference_public_id'],
                'stable_key' => $expected['stable_key'],
                'visibility' => 'global',
            ])
            ->and($candidatePlan['version_plan']['action'])->toBe('create')
            ->and($candidatePlan['version_plan']['outcome'])->toBe('planned')
            ->and($candidatePlan['version_plan']['semantic_entity'])->toMatchArray([
                'classification' => 'food',
                'energy_basis_grams' => 100,
                'energy_kcal' => $expected['energy_kcal'],
                'locale' => 'pt-BR',
                'nutrient_values' => null,
                'predecessor_public_id' => null,
                'preparation_key' => null,
                'public_id' => $expected['version_public_id'],
                'reference_public_id' => $expected['reference_public_id'],
                'review_status' => 'draft',
                'version_number' => 1,
            ])
            ->and($candidatePlan['version_plan']['nutritional_representation'])->toBe([
                'energy_basis_grams' => 100,
                'energy_kcal' => $expected['energy_kcal'],
                'kind' => 'calories_per_100g',
            ])
            ->and($candidatePlan['source_link_plan']['action'])->toBe('create')
            ->and($candidatePlan['source_link_plan']['outcome'])->toBe('planned')
            ->and($candidatePlan['source_link_plan']['semantic_entity'])->toMatchArray([
                'role' => 'primary',
                'source_authority_status' => 'untrusted',
                'source_public_id' => 'ead17ec3-6176-5f48-b25c-6f4ce3ce9907',
                'source_record_key' => $sourceRecordKey,
                'version_public_id' => $expected['version_public_id'],
            ])
            ->and($includedAliases)->toHaveCount(1)
            ->and($includedAliases[0]['action'])->toBe('create_lineage')
            ->and($includedAliases[0]['outcome'])->toBe('planned')
            ->and($includedAlias)->toMatchArray([
                'alias_kind' => 'common',
                'archived' => false,
                'display_alias' => $sourceRecordKey,
                'lineage_id' => $expected['alias_lineage_id'],
                'locale' => 'pt-BR',
                'normalized_alias' => $sourceRecordKey,
                'predecessor_public_id' => null,
                'public_id' => $expected['alias_public_id'],
                'reference_public_id' => $expected['reference_public_id'],
                'review_status' => 'draft',
                'revision_number' => 1,
                'source_public_id' => 'ead17ec3-6176-5f48-b25c-6f4ce3ce9907',
                'source_record_key' => $sourceRecordKey,
            ])
            ->and($includedAlias['provenance']['raw_variants'])->toBe([$sourceRecordKey])
            ->and($includedAlias['lifecycle_state'])->toBe([
                'activated' => false,
                'deactivated' => false,
                'published' => false,
                'reviewed' => false,
                'submitted' => false,
                'withdrawn' => false,
            ])
            ->and(CatalogImportDeterministicIdentity::referenceVersionPublicId($expected['reference_public_id'], 1))->toBe($expected['version_public_id'])
            ->and(CatalogImportDeterministicIdentity::aliasLineageId($expected['reference_public_id'], 'pt-BR', $sourceRecordKey))->toBe($expected['alias_lineage_id'])
            ->and(CatalogImportDeterministicIdentity::aliasRevisionPublicId($expected['alias_lineage_id'], 1))->toBe($expected['alias_public_id']);

        if (isset($expected['excluded_alias'])) {
            expect($excludedAliases)->toBe([[
                'action' => 'excluded',
                'normalized_alias' => $expected['excluded_alias'],
                'outcome' => 'unchanged',
            ]]);
        } else {
            expect($excludedAliases)->toBe([]);
        }
    }

    $operationCounts = array_count_values($lifecycleOperations);
    $forbiddenKeys = [
        'absolute_path',
        'actor_id',
        'actor_reference',
        'approved_at',
        'created_at',
        'database_id',
        'event_public_id',
        'executed_at',
        'generated_at',
        'internal_id',
        'machine_path',
        'occurred_at',
        'pid',
        'process_id',
        'reviewer_reference',
        'secret',
        'sql',
        'updated_at',
    ];
    $expectedUuids = [
        '050bfbe4-dc5c-5909-9c03-439a511104e9',
        '0d3adf20-be25-5439-b89b-98e86e78c995',
        '3fb0ee74-8421-5be3-84ce-db9a3aae95bb',
        '63036985-520e-5cbb-8970-74fe9cb41d18',
        '6e360841-51b8-5deb-a201-38debd0f03dc',
        '8e502950-ca1d-557d-8c26-c734b795f238',
        '93b0b1c3-6a5d-540f-8c34-38b00be3d0e6',
        '9d04432d-a814-5f9e-af1f-0165c6fc8dcf',
        'ace8e351-2de6-5bb9-a12e-3933cbbae45e',
        'bb86f7e2-e776-51d9-b503-d9430455035a',
        'c649dff7-5a65-55a2-83f8-7820356bae16',
        'c8a9ff8f-7cdf-578f-a372-109b36c5d880',
        'ead17ec3-6176-5f48-b25c-6f4ce3ce9907',
    ];

    expect($plannedAliasLineages)->toBe(3)
        ->and($plannedAliasRevisions)->toBe(3)
        ->and($excludedAliasGroups)->toBe(2)
        ->and(array_filter($plan['selected_candidate_plans'], fn (array $candidatePlan): bool => $candidatePlan['graph_outcome'] !== 'planned'))->toBe([])
        ->and(array_intersect(recursiveKeysM244e($plan), $forbiddenKeys))->toBe([])
        ->and(recursiveUuidsM244e($plan))->toBe($expectedUuids)
        ->and($bytes)->not->toContain(projectRootM244e(), '/home/', 'SELECT ', 'INSERT ', 'UPDATE ', 'DELETE ')
        ->and($operationCounts)->toBe([
            'create_source' => 1,
            'create_reference' => 3,
            'create_draft' => 6,
        ]);
});
