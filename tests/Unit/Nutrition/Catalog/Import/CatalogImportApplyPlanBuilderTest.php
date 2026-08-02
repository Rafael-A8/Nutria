<?php

use App\Nutrition\Application\Catalog\Import\CatalogImportApplyPlanBuilder;
use App\Nutrition\Application\Catalog\Import\CatalogImportApplyPlanReport;
use App\Nutrition\Application\Catalog\Import\CatalogImportApprovalAttestationLoader;
use App\Nutrition\Application\Catalog\Import\CatalogImportDeterministicIdentity;
use App\Nutrition\Application\Catalog\Import\Exceptions\LegacyNutritionApplyPlanException;
use App\Nutrition\Application\Catalog\Import\ValueObjects\CatalogImportApplyPlanSnapshot;

require_once dirname(__DIR__, 4).'/Support/CatalogImportM244bFixtures.php';

/** @return array{mixed, mixed, mixed, list<string>} */
function loadedPlanningDocumentsM244b(
    ?string $selectedKey = 'abacate',
    array $overrides = [],
    array $aliasDecisionOverrides = [],
): array {
    $temporaryPaths = [];
    $resolutionBytes = canonicalDocumentBytesM244b(reviewedResolutionDocumentM244b(
        $selectedKey,
        $overrides,
        $aliasDecisionOverrides,
    ));
    $resolutionPath = temporaryDocumentM244b($resolutionBytes);
    $temporaryPaths[] = $resolutionPath;
    $manifest = approvedManifestM244b();
    $resolution = reviewedResolutionLoaderM244b()->load(
        $resolutionPath,
        hash('sha256', $resolutionBytes),
        resolutionTemplatePathM244b(),
        $manifest,
    );
    $approvalBytes = canonicalDocumentBytesM244b(approvalDocumentM244b($resolution->checksum->digest));
    $approvalPath = temporaryDocumentM244b($approvalBytes);
    $temporaryPaths[] = $approvalPath;
    $approval = (new CatalogImportApprovalAttestationLoader)->load(
        $approvalPath,
        hash('sha256', $approvalBytes),
        $manifest,
        $resolution,
    );

    return [$manifest, $resolution, $approval, $temporaryPaths];
}

function emptySnapshotM244b(): CatalogImportApplyPlanSnapshot
{
    return new CatalogImportApplyPlanSnapshot(
        catalogCounts: ['aliases' => 0, 'reference_version_sources' => 0, 'reference_versions' => 0, 'references' => 0, 'sources' => 0],
        source: null,
        referencesByPublicId: [],
        referencesByStableKey: [],
        versionsByReferencePublicId: [],
        aliasesByReferencePublicId: [],
        sourceLinksByVersionPublicId: [],
        fingerprint: str_repeat('a', 64),
        queryCount: 11,
        queryKinds: array_map(fn (int $number): string => "select_{$number}", range(1, 11)),
    );
}

/** @param list<array<string, mixed>> $aliases */
function existingReferenceSnapshotForAliasM244b(string $referencePublicId, array $aliases = []): CatalogImportApplyPlanSnapshot
{
    $reference = [
        'archived' => false,
        'is_generic' => true,
        'owner_user_id' => null,
        'public_id' => $referencePublicId,
        'stable_key' => 'synthetic-abacate',
        'visibility' => 'global',
    ];
    $predecessor = [
        'archived' => false,
        'canonical_name' => 'Prior synthetic food',
        'classification' => 'synthetic_test_food',
        'energy_basis_grams' => 100,
        'energy_kcal' => 100,
        'locale' => 'pt-BR',
        'lifecycle_state' => [
            'activated' => false,
            'deactivated' => false,
            'published' => false,
            'reviewed' => false,
            'submitted' => false,
            'withdrawn' => false,
        ],
        'normalized_canonical_name' => 'prior synthetic food',
        'nutrient_values' => null,
        'predecessor_public_id' => null,
        'preparation_key' => null,
        'provenance' => ['synthetic' => true],
        'public_id' => 'bbbbbbbb-bbbb-5bbb-8bbb-bbbbbbbbbbbb',
        'reference_public_id' => $referencePublicId,
        'review_status' => 'draft',
        'version_number' => 1,
    ];

    return new CatalogImportApplyPlanSnapshot(
        catalogCounts: [
            'aliases' => count($aliases),
            'reference_version_sources' => 0,
            'reference_versions' => 1,
            'references' => 1,
            'sources' => 0,
        ],
        source: null,
        referencesByPublicId: [$referencePublicId => $reference],
        referencesByStableKey: ['synthetic-abacate' => $reference],
        versionsByReferencePublicId: [$referencePublicId => [$predecessor]],
        aliasesByReferencePublicId: $aliases === [] ? [] : [$referencePublicId => $aliases],
        sourceLinksByVersionPublicId: [],
        fingerprint: str_repeat('e', 64),
        queryCount: 13,
        queryKinds: ['select'],
    );
}

it('plans a deterministic absent graph with frozen nutrition aliases source link and draft operations', function () {
    [$manifest, $resolution, $approval, $paths] = loadedPlanningDocumentsM244b();

    try {
        $builder = new CatalogImportApplyPlanBuilder(new CatalogImportApplyPlanReport);
        $first = $builder->build($manifest, $resolution, $approval, emptySnapshotM244b(), ['abacate' => 'abacate']);
        $second = $builder->build($manifest, $resolution, $approval, emptySnapshotM244b(), ['abacate' => 'abacate']);
        $candidate = $first->plan['selected_candidate_plans'][0];
        $aliasPlan = $candidate['alias_plans'][0];
        $alias = $aliasPlan['semantic_entity'];
        $expectedLineageId = CatalogImportDeterministicIdentity::aliasLineageId(
            $candidate['reference_plan']['semantic_entity']['public_id'],
            'pt-BR',
            'abacate',
        );
        $expectedRevisionId = CatalogImportDeterministicIdentity::aliasRevisionPublicId($expectedLineageId, 1);
        $aliasLifecycleTemplates = array_values(array_filter(
            $candidate['lifecycle_operation_templates'],
            fn (array $operation): bool => $operation['subject_type'] === 'food_alias',
        ));
        $report = json_decode($first->reportBytes, true, flags: JSON_THROW_ON_ERROR);
        $plannedLineages = count(array_filter(
            $candidate['alias_plans'],
            fn (array $plan): bool => $plan['action'] === 'create_lineage',
        ));
        $plannedRevisions = count(array_filter(
            $candidate['alias_plans'],
            fn (array $plan): bool => in_array($plan['action'], ['create_lineage', 'create_revision'], true),
        ));

        expect($second->canonicalPlanBytes)->toBe($first->canonicalPlanBytes)
            ->and($second->checksum->digest)->toBe($first->checksum->digest)
            ->and($first->plan['source_plan']['action'])->toBe('create')
            ->and($first->plan['source_plan']['semantic_entity'])->not->toHaveKey('code')
            ->and($candidate['reference_plan']['semantic_entity']['public_id'])->toBe(
                $resolution->selectedEntries[0]['planned_reference_uuid']['public_id'],
            )
            ->and($candidate['reference_plan']['semantic_entity']['stable_key'])->toBe('synthetic-abacate')
            ->and($candidate['version_plan']['nutritional_representation'])->toBe([
                'energy_basis_grams' => 100,
                'energy_kcal' => 160,
                'kind' => 'calories_per_100g',
            ])
            ->and($candidate['version_plan']['semantic_entity']['review_status'])->toBe('draft')
            ->and($aliasPlan['action'])->toBe('create_lineage')
            ->and($aliasPlan['outcome'])->toBe('planned')
            ->and($alias)->toMatchArray([
                'alias_kind' => 'common',
                'display_alias' => 'abacate',
                'lineage_id' => $expectedLineageId,
                'locale' => 'pt-BR',
                'normalized_alias' => 'abacate',
                'predecessor_public_id' => null,
                'public_id' => $expectedRevisionId,
                'reference_public_id' => $candidate['reference_plan']['semantic_entity']['public_id'],
                'review_status' => 'draft',
                'revision_number' => 1,
                'source_public_id' => CatalogImportApplyPlanBuilder::SOURCE_PUBLIC_ID,
                'source_record_key' => 'abacate',
            ])
            ->and($alias['provenance']['raw_variants'])->toBe(['abacate'])
            ->and($aliasLifecycleTemplates)->toBe([[
                'initial_state' => 'draft',
                'operation' => 'create_draft',
                'subject_public_id' => $expectedRevisionId,
                'subject_type' => 'food_alias',
            ]])
            ->and($first->counts['planned_alias_lineages'])->toBe(1)
            ->and($first->counts['planned_alias_revisions'])->toBe(1)
            ->and($report['counts']['planned_alias_lineages'])->toBe($plannedLineages)->toBe(1)
            ->and($report['counts']['planned_alias_revisions'])->toBe($plannedRevisions)->toBe(1)
            ->and($candidate['source_link_plan']['semantic_entity'])->toMatchArray([
                'role' => 'primary',
                'source_authority_status' => 'untrusted',
                'source_record_key' => 'abacate',
            ])
            ->and($first->canonicalPlanBytes)->not->toContain('portion', 'actor_id', 'event_public_id', projectRootM244b());
    } finally {
        foreach ($paths as $path) {
            unlink($path);
        }
    }
});

it('plans no lineage revision or alias lifecycle operation for an excluded alias', function () {
    [$manifest, $resolution, $approval, $paths] = loadedPlanningDocumentsM244b(
        'abacate',
        [],
        ['alias_kind' => null, 'status' => 'exclude'],
    );

    try {
        $result = (new CatalogImportApplyPlanBuilder(new CatalogImportApplyPlanReport))->build(
            $manifest,
            $resolution,
            $approval,
            emptySnapshotM244b(),
            ['abacate' => 'abacate'],
        );
        $candidate = $result->plan['selected_candidate_plans'][0];
        $aliasLifecycleTemplates = array_filter(
            $candidate['lifecycle_operation_templates'],
            fn (array $operation): bool => $operation['subject_type'] === 'food_alias',
        );

        expect($candidate['alias_plans'])->toBe([[
            'action' => 'excluded',
            'normalized_alias' => 'abacate',
            'outcome' => 'unchanged',
        ]])
            ->and($result->counts['planned_alias_lineages'])->toBe(0)
            ->and($result->counts['planned_alias_revisions'])->toBe(0)
            ->and($aliasLifecycleTemplates)->toBe([]);
    } finally {
        foreach ($paths as $path) {
            unlink($path);
        }
    }
});

it('plans one next revision and no lineage for a compatible change to an existing alias lineage', function () {
    $referencePublicId = 'aaaaaaaa-aaaa-5aaa-8aaa-aaaaaaaaaaaa';
    [$manifest, $resolution, $approval, $paths] = loadedPlanningDocumentsM244b('abacate', [
        'existing_reference_public_id' => $referencePublicId,
        'reference_target' => 'existing_reference',
    ]);

    try {
        $builder = new CatalogImportApplyPlanBuilder(new CatalogImportApplyPlanReport);
        $initial = $builder->build(
            $manifest,
            $resolution,
            $approval,
            existingReferenceSnapshotForAliasM244b($referencePublicId),
            ['abacate' => 'abacate'],
        );
        $currentAlias = $initial->plan['selected_candidate_plans'][0]['alias_plans'][0]['semantic_entity'];
        $currentAlias['alias_kind'] = 'brand';
        $result = $builder->build(
            $manifest,
            $resolution,
            $approval,
            existingReferenceSnapshotForAliasM244b($referencePublicId, [$currentAlias]),
            ['abacate' => 'abacate'],
        );
        $candidate = $result->plan['selected_candidate_plans'][0];
        $aliasPlan = $candidate['alias_plans'][0];
        $alias = $aliasPlan['semantic_entity'];
        $expectedRevisionId = CatalogImportDeterministicIdentity::aliasRevisionPublicId(
            $currentAlias['lineage_id'],
            2,
        );
        $aliasLifecycleTemplates = array_values(array_filter(
            $candidate['lifecycle_operation_templates'],
            fn (array $operation): bool => $operation['subject_type'] === 'food_alias',
        ));
        $report = json_decode($result->reportBytes, true, flags: JSON_THROW_ON_ERROR);

        expect($aliasPlan['action'])->toBe('create_revision')
            ->and($aliasPlan['outcome'])->toBe('planned')
            ->and($alias['lineage_id'])->toBe($currentAlias['lineage_id'])
            ->and($alias['revision_number'])->toBe(2)
            ->and($alias['predecessor_public_id'])->toBe($currentAlias['public_id'])
            ->and($alias['public_id'])->toBe($expectedRevisionId)
            ->and($alias['review_status'])->toBe('draft')
            ->and($aliasLifecycleTemplates)->toBe([[
                'initial_state' => 'draft',
                'operation' => 'create_draft',
                'subject_public_id' => $expectedRevisionId,
                'subject_type' => 'food_alias',
            ]])
            ->and($result->counts['planned_alias_lineages'])->toBe(0)
            ->and($result->counts['planned_alias_revisions'])->toBe(1)
            ->and($report['counts']['planned_alias_lineages'])->toBe(0)
            ->and($report['counts']['planned_alias_revisions'])->toBe(1);
    } finally {
        foreach ($paths as $path) {
            unlink($path);
        }
    }
});

it('preserves default calories without converting them to per-100g density', function () {
    $template = reviewedResolutionDocumentM244b(null);
    $key = null;

    foreach ($template['review_entries'] as $entry) {
        if ($entry['calorie_shape']['kind'] === 'default_calories') {
            $key = $entry['source_record_key'];
            break;
        }
    }

    [$manifest, $resolution, $approval, $paths] = loadedPlanningDocumentsM244b($key);

    try {
        $result = (new CatalogImportApplyPlanBuilder(new CatalogImportApplyPlanReport))->build(
            $manifest,
            $resolution,
            $approval,
            emptySnapshotM244b(),
            [$key => $key],
        );
        $shape = $resolution->selectedEntries[0]['calorie_shape'];

        expect($result->plan['selected_candidate_plans'][0]['version_plan']['nutritional_representation'])->toBe([
            'energy_basis_grams' => $shape['default_grams'],
            'energy_kcal' => $shape['default_calories'],
            'kind' => 'default_calories',
        ]);
    } finally {
        foreach ($paths as $path) {
            unlink($path);
        }
    }
});

it('reports an exact complete replay as no-op and rejects a partial graph', function () {
    [$manifest, $resolution, $approval, $paths] = loadedPlanningDocumentsM244b();

    try {
        $builder = new CatalogImportApplyPlanBuilder(new CatalogImportApplyPlanReport);
        $planned = $builder->build($manifest, $resolution, $approval, emptySnapshotM244b(), ['abacate' => 'abacate']);
        $candidate = $planned->plan['selected_candidate_plans'][0];
        $reference = $candidate['reference_plan']['semantic_entity'];
        $version = ['archived' => false, ...$candidate['version_plan']['semantic_entity']];
        $aliases = array_map(
            fn (array $aliasPlan): array => $aliasPlan['semantic_entity'],
            array_filter($candidate['alias_plans'], fn (array $aliasPlan): bool => $aliasPlan['action'] !== 'excluded'),
        );
        $complete = new CatalogImportApplyPlanSnapshot(
            catalogCounts: ['aliases' => count($aliases), 'reference_version_sources' => 1, 'reference_versions' => 1, 'references' => 1, 'sources' => 1],
            source: $planned->plan['source_plan']['semantic_entity'],
            referencesByPublicId: [$reference['public_id'] => $reference],
            referencesByStableKey: [$reference['stable_key'] => $reference],
            versionsByReferencePublicId: [$reference['public_id'] => [$version]],
            aliasesByReferencePublicId: [$reference['public_id'] => $aliases],
            sourceLinksByVersionPublicId: [$version['public_id'] => [$candidate['source_link_plan']['semantic_entity']]],
            fingerprint: str_repeat('b', 64),
            queryCount: 14,
            queryKinds: ['select'],
        );
        $replayed = $builder->build($manifest, $resolution, $approval, $complete, ['abacate' => 'abacate']);

        expect($replayed->plan['selected_candidate_plans'][0]['graph_outcome'])->toBe('no_op')
            ->and($replayed->plan['selected_candidate_plans'][0]['alias_plans'][0]['action'])->toBe('unchanged')
            ->and($replayed->counts['planned_alias_lineages'])->toBe(0)
            ->and($replayed->counts['planned_alias_revisions'])->toBe(0)
            ->and($replayed->counts['no_op_graphs'])->toBe(1);

        $partial = new CatalogImportApplyPlanSnapshot(
            catalogCounts: $complete->catalogCounts,
            source: $complete->source,
            referencesByPublicId: $complete->referencesByPublicId,
            referencesByStableKey: $complete->referencesByStableKey,
            versionsByReferencePublicId: [],
            aliasesByReferencePublicId: [],
            sourceLinksByVersionPublicId: [],
            fingerprint: str_repeat('c', 64),
            queryCount: 12,
            queryKinds: ['select'],
        );

        expect(fn () => $builder->build($manifest, $resolution, $approval, $partial, ['abacate' => 'abacate']))
            ->toThrow(LegacyNutritionApplyPlanException::class);
    } finally {
        foreach ($paths as $path) {
            unlink($path);
        }
    }
});

it('rejects zero selections with the typed no_candidates_selected outcome', function () {
    [$manifest, $resolution, $approval, $paths] = loadedPlanningDocumentsM244b(null);

    try {
        (new CatalogImportApplyPlanBuilder(new CatalogImportApplyPlanReport))->build(
            $manifest,
            $resolution,
            $approval,
            emptySnapshotM244b(),
            [],
        );
    } catch (LegacyNutritionApplyPlanException $exception) {
        expect($exception->outcome)->toBe('no_candidates_selected');

        return;
    } finally {
        foreach ($paths as $path) {
            unlink($path);
        }
    }

    $this->fail('Expected typed zero-selection refusal.');
});

it('plans the deterministic next version for an exact existing reference without editing the predecessor', function () {
    $existingPublicId = 'aaaaaaaa-aaaa-5aaa-8aaa-aaaaaaaaaaaa';
    [$manifest, $resolution, $approval, $paths] = loadedPlanningDocumentsM244b('abacate', [
        'existing_reference_public_id' => $existingPublicId,
        'reference_target' => 'existing_reference',
    ]);
    $reference = [
        'archived' => false,
        'is_generic' => true,
        'owner_user_id' => null,
        'public_id' => $existingPublicId,
        'stable_key' => 'synthetic-abacate',
        'visibility' => 'global',
    ];
    $predecessor = [
        'archived' => false,
        'canonical_name' => 'Prior synthetic food',
        'classification' => 'synthetic_test_food',
        'energy_basis_grams' => 100,
        'energy_kcal' => 100,
        'locale' => 'pt-BR',
        'lifecycle_state' => [
            'activated' => false,
            'deactivated' => false,
            'published' => false,
            'reviewed' => false,
            'submitted' => false,
            'withdrawn' => false,
        ],
        'normalized_canonical_name' => 'prior synthetic food',
        'nutrient_values' => null,
        'predecessor_public_id' => null,
        'preparation_key' => null,
        'provenance' => ['synthetic' => true],
        'public_id' => 'bbbbbbbb-bbbb-5bbb-8bbb-bbbbbbbbbbbb',
        'reference_public_id' => $existingPublicId,
        'review_status' => 'draft',
        'version_number' => 1,
    ];
    $snapshot = new CatalogImportApplyPlanSnapshot(
        catalogCounts: ['aliases' => 0, 'reference_version_sources' => 0, 'reference_versions' => 1, 'references' => 1, 'sources' => 0],
        source: null,
        referencesByPublicId: [$existingPublicId => $reference],
        referencesByStableKey: ['synthetic-abacate' => $reference],
        versionsByReferencePublicId: [$existingPublicId => [$predecessor]],
        aliasesByReferencePublicId: [],
        sourceLinksByVersionPublicId: [],
        fingerprint: str_repeat('d', 64),
        queryCount: 13,
        queryKinds: ['select'],
    );

    try {
        $result = (new CatalogImportApplyPlanBuilder(new CatalogImportApplyPlanReport))->build(
            $manifest,
            $resolution,
            $approval,
            $snapshot,
            ['abacate' => 'abacate'],
        );
        $version = $result->plan['selected_candidate_plans'][0]['version_plan']['semantic_entity'];

        expect($version['version_number'])->toBe(2)
            ->and($version['predecessor_public_id'])->toBe($predecessor['public_id'])
            ->and($version['public_id'])->toBe(
                CatalogImportDeterministicIdentity::referenceVersionPublicId(
                    $existingPublicId,
                    2,
                ),
            )
            ->and($predecessor['version_number'])->toBe(1);
    } finally {
        foreach ($paths as $path) {
            unlink($path);
        }
    }
});
