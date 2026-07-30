<?php

use App\Nutrition\Application\Catalog\Import\CatalogImportDeterministicIdentity;
use App\Nutrition\Application\Catalog\Import\Enums\CatalogImportIssueCode;
use App\Nutrition\Application\Catalog\Import\LegacyNutritionCandidateManifestGenerator;
use App\Nutrition\Application\Catalog\Import\LegacyNutritionCommitReader;
use App\Nutrition\Application\Catalog\Import\LegacyNutritionSourceLoader;
use App\Nutrition\Application\Catalog\Import\LegacyNutritionSummaryReport;
use App\Nutrition\Application\Catalog\Import\ValueObjects\LegacyNutritionPlanningResult;
use App\Nutrition\Application\Catalog\Import\ValueObjects\SourceArtifactChecksum;
use App\Nutrition\Application\Catalog\NormalizeFoodText;

function legacyNutritionPlanningResultM243(): LegacyNutritionPlanningResult
{
    static $result;

    if ($result instanceof LegacyNutritionPlanningResult) {
        return $result;
    }

    $repositoryRoot = dirname(__DIR__, 5);
    $source = (new LegacyNutritionSourceLoader)->load(
        repositoryRoot: $repositoryRoot,
        artifactPath: 'config/nutrition.php',
        expectedChecksum: new SourceArtifactChecksum(
            'sha256',
            '0d3987cc616b40e878731ecda0127a5b0a065f9557977b5b8f4ec0091d4ecc21',
        ),
    );

    return $result = (new LegacyNutritionCandidateManifestGenerator(new NormalizeFoodText))->generate(
        source: $source,
        repositoryCommit: (new LegacyNutritionCommitReader)->resolve($repositoryRoot),
    );
}

/** @return array<string, mixed> */
function legacyNutritionManifestRecordM243(string $sourceRecordKey): array
{
    foreach (legacyNutritionPlanningResultM243()->manifest['records'] as $record) {
        if ($record['source_record_key'] === $sourceRecordKey) {
            return $record;
        }
    }

    throw new RuntimeException("Missing fixture record {$sourceRecordKey}.");
}

it('reproduces the complete approved source inventory', function () {
    $summary = legacyNutritionPlanningResultM243()->manifest['extraction_summary'];

    expect($summary)->toBe([
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
    ]);
});

it('classifies exactly the three frozen structurally eligible candidates as valid', function () {
    $records = legacyNutritionPlanningResultM243()->manifest['records'];
    $validCandidateKeys = array_column(
        array_values(array_filter(
            $records,
            fn (array $record): bool => $record['candidate_classification'] === 'valid_candidate',
        )),
        'source_record_key',
    );

    expect($validCandidateKeys)->toBe([
        'leite condensado',
        'doce de leite',
        'creme de leite',
    ]);
});

it('keeps every identity unresolved and every candidate unselected', function () {
    $records = legacyNutritionPlanningResultM243()->manifest['records'];

    expect($records)->toHaveCount(106);

    foreach ($records as $record) {
        expect($record['identity_resolution'])->toMatchArray([
            'alias_identities' => [],
            'classification' => null,
            'existing_reference_public_id' => null,
            'is_generic' => null,
            'preparation' => null,
            'reference_target' => null,
            'reference_visibility' => null,
            'stable_key' => null,
            'status' => 'unresolved',
            'version_locale' => null,
        ])->and($record['selected_for_apply'])->toBeFalse()
            ->and($record['valid_explicit_portions'])->toBe([]);
    }
});

it('does not select even a valid candidate or infer its conceptual decisions', function () {
    $record = legacyNutritionManifestRecordM243('leite condensado');

    expect($record['candidate_classification'])->toBe('valid_candidate')
        ->and($record['selected_for_apply'])->toBeFalse()
        ->and($record['identity_resolution']['stable_key'])->toBeNull()
        ->and($record['identity_resolution']['is_generic'])->toBeNull()
        ->and($record['identity_resolution']['preparation'])->toBeNull()
        ->and($record['identity_resolution']['alias_identities'])->toBe([]);
});

it('preserves raw aliases, their source order, and every original payload field', function () {
    $record = legacyNutritionManifestRecordM243('café sem açúcar');
    $payload = array_column($record['legacy_payload_fields'], 'value', 'field');

    expect($record['raw_aliases_in_source_order'])->toBe([
        'cafe',
        'café',
        'cafezinho',
        'cafe sem acucar',
        'café sem açúcar',
    ])->and($payload)->toBe([
        'aliases' => [
            'cafe',
            'café',
            'cafezinho',
            'cafe sem acucar',
            'café sem açúcar',
        ],
        'default_grams' => 50,
        'default_calories' => 2,
    ]);
});

it('keeps normalized variants together without merging food candidates', function () {
    $manifest = legacyNutritionPlanningResultM243()->manifest;
    $record = legacyNutritionManifestRecordM243('grão-de-bico cozido');
    $collision = $record['collision_information']['within_candidate_groups'][0];

    expect($manifest['records'])->toHaveCount(106)
        ->and($manifest['collision_information']['cross_candidate_groups'])->toBe([])
        ->and($collision)->toBe([
            'duplicate_normalized_occurrences' => 3,
            'normalized_alias' => 'grao de bico',
            'raw_variants' => [
                'grao de bico',
                'grao-de-bico',
                'grão de bico',
                'grão-de-bico',
            ],
        ])->and($record['issue_codes'])->toContain(
            'normalization_collision',
            'duplicate_alias',
        );
});

it('emits only non-authoritative planned reference identities and no child identities', function () {
    $records = legacyNutritionPlanningResultM243()->manifest['records'];

    foreach ($records as $record) {
        expect($record['planning_identity']['authority'])->toBe('non_authoritative')
            ->and($record['planning_identity']['kind'])->toBe('planned_new_reference_uuid')
            ->and($record['planning_identity'])->not->toHaveKeys([
                'stable_key',
                'version_public_id',
                'alias_lineage_id',
                'alias_public_id',
            ]);
    }
});

it('uses the committed descriptor and corrected UTF-8 deterministic identities', function () {
    $manifest = legacyNutritionPlanningResultM243()->manifest;
    $record = legacyNutritionManifestRecordM243('leite condensado');

    expect($manifest['schema'])->toBe('nutria.catalog-import-manifest/1')
        ->and($manifest['logical_artifact'])->toBe([
            'artifact_id' => 'legacy_config_nutrition_v1',
            'artifact_path' => 'config/nutrition.php',
        ])->and($manifest['source'])->toMatchArray([
            'authority_status' => 'untrusted',
            'kind' => 'legacy_config',
            'owner_user_id' => null,
            'visibility' => 'global',
        ])->and($manifest['source_identity'])->toBe([
            'canonical_name' => 'v1|artifact|26:legacy_config_nutrition_v1',
            'public_id' => 'ead17ec3-6176-5f48-b25c-6f4ce3ce9907',
        ])->and($record['planning_identity']['canonical_name'])
        ->toBe('v1|artifact|26:legacy_config_nutrition_v1|record_key|16:leite condensado')
        ->and($record['planning_identity']['public_id'])
        ->toBe('6e360841-51b8-5deb-a201-38debd0f03dc')
        ->and(CatalogImportDeterministicIdentity::canonicalComponent('café'))->toBe('5:café');
});

it('produces byte-identical canonical manifests and checksums for identical inputs', function () {
    $repositoryRoot = dirname(__DIR__, 5);
    $source = (new LegacyNutritionSourceLoader)->load(
        $repositoryRoot,
        'config/nutrition.php',
        new SourceArtifactChecksum(
            'sha256',
            '0d3987cc616b40e878731ecda0127a5b0a065f9557977b5b8f4ec0091d4ecc21',
        ),
    );
    $generator = new LegacyNutritionCandidateManifestGenerator(new NormalizeFoodText);
    $commit = (new LegacyNutritionCommitReader)->resolve($repositoryRoot);
    $first = $generator->generate($source, $commit);
    $second = $generator->generate($source, $commit);

    expect($second->canonicalManifestBytes)->toBe($first->canonicalManifestBytes)
        ->and($second->manifestChecksum->digest)->toBe($first->manifestChecksum->digest);
});

it('keeps canonical record, issue, alias, and collision ordering deterministic', function () {
    $manifest = json_decode(
        legacyNutritionPlanningResultM243()->canonicalManifestBytes,
        true,
        flags: JSON_THROW_ON_ERROR,
    );
    $recordKeys = array_column($manifest['records'], 'source_record_key');
    $issueOrder = array_flip(array_column(CatalogImportIssueCode::cases(), 'value'));

    expect($recordKeys)->toBe(array_values(array_unique($recordKeys)))
        ->and($recordKeys)->toBe((function (array $keys): array {
            sort($keys, SORT_STRING);

            return $keys;
        })($recordKeys))
        ->and(legacyNutritionManifestRecordM243('arroz branco cozido')['source_ordinal'])->toBe(1)
        ->and(legacyNutritionManifestRecordM243('whey protein')['source_ordinal'])->toBe(106);

    foreach ($manifest['records'] as $record) {
        $issues = array_map(fn (string $issue): int => $issueOrder[$issue], $record['issue_codes']);
        $aliases = $record['aliases'];
        $sortedAliases = $aliases;
        usort(
            $sortedAliases,
            fn (array $left, array $right): int => strcmp(
                json_encode($left, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                json_encode($right, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ),
        );

        expect($issues)->toBe((function (array $values): array {
            sort($values);

            return $values;
        })($issues))->and($aliases)->toBe($sortedAliases);

        $withinCollisionAliases = array_column(
            $record['collision_information']['within_candidate_groups'],
            'normalized_alias',
        );
        $sortedCollisionAliases = $withinCollisionAliases;
        sort($sortedCollisionAliases, SORT_STRING);

        expect($withinCollisionAliases)->toBe($sortedCollisionAliases);
    }
});

it('excludes machine paths timestamps and self-referential checksum data', function () {
    $canonicalBytes = legacyNutritionPlanningResultM243()->canonicalManifestBytes;

    expect($canonicalBytes)->not->toContain(
        dirname(__DIR__, 5),
        '/home/',
        'generated_at',
        'executed_at',
        'manifest_checksum',
    );
});

it('renders a separate payload-free summary report with all required totals', function () {
    $result = legacyNutritionPlanningResultM243();
    $report = json_decode(
        (new LegacyNutritionSummaryReport)->render($result),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    expect($report)->toMatchArray([
        'source_path' => 'config/nutrition.php',
        'byte_size' => 25676,
        'manifest_checksum' => [
            'algorithm' => 'sha256',
            'digest' => $result->manifestChecksum->digest,
        ],
        'output_status' => 'written',
    ])->and($report['candidate_counts']['total'])->toBe(106)
        ->and($report['identity_counts']['unresolved'])->toBe(106)
        ->and($report['identity_counts']['selected'])->toBe(0)
        ->and(json_encode($report, JSON_THROW_ON_ERROR))->not->toContain(
            'legacy_payload_fields',
            'raw_aliases_in_source_order',
        );
});
