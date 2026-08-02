<?php

namespace App\Nutrition\Application\Catalog\Import;

use App\Nutrition\Application\Catalog\Import\Exceptions\LegacyNutritionApplyPlanException;
use App\Nutrition\Application\Catalog\Import\ValueObjects\CanonicalManifestChecksum;
use App\Nutrition\Application\Catalog\Import\ValueObjects\CatalogImportApplyPlanResult;
use App\Nutrition\Application\Catalog\Import\ValueObjects\CatalogImportApplyPlanSchema;
use App\Nutrition\Application\Catalog\Import\ValueObjects\CatalogImportApplyPlanSnapshot;
use App\Nutrition\Application\Catalog\Import\ValueObjects\CatalogImportSemanticGraph;
use App\Nutrition\Application\Catalog\Import\ValueObjects\LegacyCatalogArtifactDescriptor;
use App\Nutrition\Application\Catalog\Import\ValueObjects\LegacyNutritionReviewManifest;
use App\Nutrition\Application\Catalog\Import\ValueObjects\LoadedCatalogImportApproval;
use App\Nutrition\Application\Catalog\Import\ValueObjects\LoadedCatalogImportReviewedResolution;
use App\Nutrition\Domain\Catalog\Lifecycle\Enums\CatalogLifecycleOperation;

final class CatalogImportApplyPlanBuilder
{
    public const SOURCE_PUBLIC_ID = 'ead17ec3-6176-5f48-b25c-6f4ce3ce9907';

    public function __construct(private CatalogImportApplyPlanReport $report) {}

    /**
     * @param  array<string, string>  $normalizedCanonicalNames
     */
    public function build(
        LegacyNutritionReviewManifest $manifest,
        LoadedCatalogImportReviewedResolution $resolution,
        LoadedCatalogImportApproval $approval,
        CatalogImportApplyPlanSnapshot $snapshot,
        array $normalizedCanonicalNames,
    ): CatalogImportApplyPlanResult {
        if ($resolution->selectedEntries === []) {
            throw new LegacyNutritionApplyPlanException(
                'no_candidates_selected',
                'no_candidates_selected: the reviewed resolution selected no candidates.',
            );
        }

        $sourceSemantic = $this->sourceSemantic($manifest);
        $sourceAction = $this->entityAction($snapshot->source, $sourceSemantic, 'source');
        $candidatePlans = [];
        $counts = $this->initialCounts($resolution);

        if ($sourceAction === 'create') {
            $counts['planned_sources'] = 1;
        }

        foreach ($resolution->selectedEntries as $entry) {
            $candidatePlan = $this->candidatePlan(
                entry: $entry,
                manifest: $manifest,
                snapshot: $snapshot,
                sourceSemantic: $sourceSemantic,
                normalizedCanonicalName: $normalizedCanonicalNames[$entry['source_record_key']] ?? '',
            );
            $candidatePlans[] = $candidatePlan;
            $this->accumulateCounts($counts, $candidatePlan);
        }

        usort($candidatePlans, fn (array $left, array $right): int => strcmp(
            $left['source_record_key'],
            $right['source_record_key'],
        ));

        $plan = [
            'approval_attestation_sha256' => $approval->checksum->digest,
            'candidate_manifest_sha256' => $manifest->checksum->digest,
            'catalog_preflight' => [
                'catalog_counts' => $snapshot->catalogCounts,
                'fingerprint' => $snapshot->fingerprint,
            ],
            'logical_artifact_id' => LegacyCatalogArtifactDescriptor::ARTIFACT_ID,
            'reviewed_resolution_sha256' => $resolution->checksum->digest,
            'schema' => CatalogImportApplyPlanSchema::IDENTIFIER,
            'selected_candidate_count' => count($candidatePlans),
            'selected_candidate_plans' => $candidatePlans,
            'source_plan' => [
                'action' => $sourceAction,
                'lifecycle_operation_template' => $sourceAction === 'create'
                    ? ['operation' => CatalogLifecycleOperation::CreateSource->value, 'subject_public_id' => self::SOURCE_PUBLIC_ID, 'subject_type' => 'food_source']
                    : null,
                'outcome' => $sourceAction === 'create' ? 'planned' : 'unchanged',
                'semantic_entity' => $sourceSemantic,
            ],
            'source_sha256' => $manifest->sourceChecksum(),
        ];

        try {
            $canonicalBytes = CanonicalCatalogImportJson::serializeSemanticGraph($plan);
        } catch (\Throwable $exception) {
            throw new LegacyNutritionApplyPlanException('invalid_apply_plan', 'The apply plan could not be serialized canonically.', $exception);
        }

        $checksum = CanonicalManifestChecksum::fromCanonicalBytes($canonicalBytes);

        return new CatalogImportApplyPlanResult(
            plan: $plan,
            canonicalPlanBytes: $canonicalBytes,
            checksum: $checksum,
            reportBytes: $this->report->render(
                manifest: $manifest,
                resolution: $resolution,
                approval: $approval,
                snapshot: $snapshot,
                applyPlanChecksum: $checksum,
                counts: $counts,
            ),
            counts: $counts,
        );
    }

    /**
     * @param  array<string, mixed>  $entry
     * @param  array<string, mixed>  $sourceSemantic
     * @return array<string, mixed>
     */
    private function candidatePlan(
        array $entry,
        LegacyNutritionReviewManifest $manifest,
        CatalogImportApplyPlanSnapshot $snapshot,
        array $sourceSemantic,
        string $normalizedCanonicalName,
    ): array {
        if ($normalizedCanonicalName === '') {
            throw new LegacyNutritionApplyPlanException('unsupported_candidate_semantics', 'A selected canonical name could not be normalized.');
        }

        $isNewReference = $entry['reference_target'] === 'new_reference';
        $referencePublicId = $isNewReference
            ? $entry['planned_reference_uuid']['public_id']
            : $entry['existing_reference_public_id'];
        $referenceSemantic = [
            'archived' => false,
            'is_generic' => $entry['is_generic'],
            'owner_user_id' => $entry['owner_user_id'],
            'public_id' => $referencePublicId,
            'stable_key' => $entry['stable_key'],
            'visibility' => $entry['reference_visibility'],
        ];
        $persistedByPublicId = $snapshot->referencesByPublicId[$referencePublicId] ?? null;
        $persistedByStableKey = $snapshot->referencesByStableKey[$entry['stable_key']] ?? null;

        if ($isNewReference) {
            if ($persistedByPublicId !== null && $persistedByPublicId !== $referenceSemantic) {
                $this->conflict('The planned new-reference UUID already has different semantics.');
            }

            if ($persistedByStableKey !== null && $persistedByStableKey !== $referenceSemantic) {
                $this->conflict('The selected new-reference stable key already belongs to another identity.');
            }

            $referenceAction = $persistedByPublicId === null && $persistedByStableKey === null ? 'create' : 'unchanged';
        } else {
            if ($persistedByPublicId === null) {
                $this->conflict('The selected existing reference public UUID was not found.');
            }

            if ($persistedByPublicId !== $referenceSemantic || $persistedByStableKey !== $persistedByPublicId) {
                $this->conflict('The selected existing reference immutable semantics have drifted.');
            }

            $referenceAction = 'unchanged';
        }

        $existingVersions = $snapshot->versionsByReferencePublicId[$referencePublicId] ?? [];
        $versionNumber = $isNewReference ? 1 : $this->nextVersionNumber($existingVersions);
        $predecessor = $isNewReference || $existingVersions === [] ? null : $existingVersions[array_key_last($existingVersions)];
        $versionPublicId = CatalogImportDeterministicIdentity::referenceVersionPublicId($referencePublicId, $versionNumber);
        $nutrition = $this->nutrition($entry['calorie_shape']);
        $versionProvenance = [
            'artifact_id' => LegacyCatalogArtifactDescriptor::ARTIFACT_ID,
            'calorie_representation_kind' => $nutrition['kind'],
            'issue_codes' => $entry['issue_codes'],
            'source_checksum' => ['algorithm' => 'sha256', 'digest' => $manifest->sourceChecksum()],
            'source_record_key' => $entry['source_record_key'],
        ];
        $versionSemantic = [
            'canonical_name' => $entry['canonical_name'],
            'classification' => $entry['catalog_classification'],
            'energy_basis_grams' => $nutrition['energy_basis_grams'],
            'energy_kcal' => $nutrition['energy_kcal'],
            'locale' => $entry['version_locale'],
            'lifecycle_state' => [
                'activated' => false,
                'deactivated' => false,
                'published' => false,
                'reviewed' => false,
                'submitted' => false,
                'withdrawn' => false,
            ],
            'normalized_canonical_name' => $normalizedCanonicalName,
            'nutrient_values' => null,
            'predecessor_public_id' => $predecessor['public_id'] ?? null,
            'preparation_key' => $entry['preparation_decision']['preparation_key'],
            'provenance' => $versionProvenance,
            'public_id' => $versionPublicId,
            'reference_public_id' => $referencePublicId,
            'review_status' => 'draft',
            'version_number' => $versionNumber,
        ];
        $persistedVersion = $this->versionByPublicId($existingVersions, $versionPublicId);

        if ($persistedVersion !== null && $persistedVersion !== ['archived' => false, ...$versionSemantic]) {
            $this->conflict('The deterministic version identity has different semantic content.');
        }

        if ($isNewReference && $referenceAction === 'unchanged' && $persistedVersion === null) {
            $this->conflict('The selected new-reference graph is only partially present.');
        }

        if ($isNewReference && count($existingVersions) > 1) {
            $this->conflict('The selected new-reference graph contains unexpected additional versions.');
        }

        $versionAction = $persistedVersion === null ? 'create' : 'unchanged';
        $sourceLinkSemantic = [
            'evidence_metadata' => [
                'artifact_id' => LegacyCatalogArtifactDescriptor::ARTIFACT_ID,
                'source_checksum' => ['algorithm' => 'sha256', 'digest' => $manifest->sourceChecksum()],
            ],
            'role' => 'primary',
            'source_authority_status' => 'untrusted',
            'source_public_id' => self::SOURCE_PUBLIC_ID,
            'source_record_key' => $entry['source_record_key'],
            'version_public_id' => $versionPublicId,
        ];
        $existingLinks = $snapshot->sourceLinksByVersionPublicId[$versionPublicId] ?? [];
        $sourceLinkAction = $this->sourceLinkAction($existingLinks, $sourceLinkSemantic);
        $aliasPlans = $this->aliasPlans($entry, $referencePublicId, $snapshot, $manifest);
        $graphAliases = array_values(array_map(
            fn (array $plan): array => $plan['semantic_entity'],
            array_filter($aliasPlans, fn (array $plan): bool => $plan['action'] !== 'excluded'),
        ));
        $lifecycle = $this->lifecycleTemplates(
            $referenceAction,
            $referencePublicId,
            $versionAction,
            $versionPublicId,
            $aliasPlans,
        );
        $graph = new CatalogImportSemanticGraph(
            source: $sourceSemantic,
            reference: $referenceSemantic,
            version: [...$versionSemantic, 'nutritional_representation' => $nutrition],
            sourceLink: $sourceLinkSemantic,
            aliases: $graphAliases,
            initialLifecycleStates: ['aliases' => 'draft', 'version' => 'draft'],
            provenance: [
                'approval_attestation_required' => true,
                'artifact_id' => LegacyCatalogArtifactDescriptor::ARTIFACT_ID,
                'source_record_key' => $entry['source_record_key'],
            ],
        );
        $allAliasesUnchanged = true;

        foreach ($aliasPlans as $aliasPlan) {
            if (! in_array($aliasPlan['action'], ['unchanged', 'excluded'], true)) {
                $allAliasesUnchanged = false;
                break;
            }
        }

        $allUnchanged = $referenceAction === 'unchanged'
            && $versionAction === 'unchanged'
            && $sourceLinkAction === 'unchanged'
            && $allAliasesUnchanged;

        if ($isNewReference && $referenceAction === 'unchanged' && ! $allUnchanged) {
            $this->conflict('The selected new-reference semantic graph is partial or incompatible.');
        }

        return [
            'alias_plans' => $aliasPlans,
            'candidate_classification' => $entry['candidate_classification'],
            'graph_fingerprint' => CatalogImportSemanticGraphFingerprint::forGraph($graph),
            'graph_outcome' => $allUnchanged ? 'no_op' : 'planned',
            'issue_codes' => $entry['issue_codes'],
            'lifecycle_operation_templates' => $lifecycle,
            'reference_plan' => [
                'action' => $referenceAction,
                'outcome' => $referenceAction === 'create' ? 'planned' : 'unchanged',
                'semantic_entity' => $referenceSemantic,
            ],
            'source_link_plan' => [
                'action' => $sourceLinkAction,
                'outcome' => $sourceLinkAction === 'create' ? 'planned' : 'unchanged',
                'semantic_entity' => $sourceLinkSemantic,
            ],
            'source_record_key' => $entry['source_record_key'],
            'version_plan' => [
                'action' => $versionAction,
                'nutritional_representation' => $nutrition,
                'outcome' => $versionAction === 'create' ? 'planned' : 'unchanged',
                'semantic_entity' => $versionSemantic,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function sourceSemantic(LegacyNutritionReviewManifest $manifest): array
    {
        $source = $manifest->manifest['source'];

        if (CatalogImportDeterministicIdentity::sourcePublicId() !== self::SOURCE_PUBLIC_ID) {
            throw new LegacyNutritionApplyPlanException('source_identity_conflict', 'The committed legacy source identity drifted.');
        }

        return [
            'archived' => false,
            'authority_status' => 'untrusted',
            'checksum' => $manifest->sourceChecksum(),
            'checksum_algorithm' => 'sha256',
            'citation' => null,
            'edition' => null,
            'kind' => 'legacy_config',
            'license' => null,
            'metadata' => $source['metadata'],
            'owner_user_id' => null,
            'public_id' => self::SOURCE_PUBLIC_ID,
            'publisher' => null,
            'retrieved_at' => null,
            'source_uri' => null,
            'title' => $source['title'],
            'visibility' => 'global',
        ];
    }

    /** @param array<string, mixed>|null $persisted @param array<string, mixed> $expected */
    private function entityAction(?array $persisted, array $expected, string $entity): string
    {
        if ($persisted === null) {
            return 'create';
        }

        if ($persisted !== $expected) {
            $this->conflict("The deterministic {$entity} identity has different semantic content.");
        }

        return 'unchanged';
    }

    /** @param array<string, mixed> $shape @return array<string, int|string> */
    private function nutrition(array $shape): array
    {
        if (($shape['kind'] ?? null) === 'calories_per_100g' && isset($shape['calories_per_100g'])) {
            return [
                'energy_basis_grams' => 100,
                'energy_kcal' => $shape['calories_per_100g'],
                'kind' => 'calories_per_100g',
            ];
        }

        if (
            ($shape['kind'] ?? null) === 'default_calories'
            && isset($shape['default_calories'], $shape['default_grams'])
            && $shape['default_grams'] > 0
        ) {
            return [
                'energy_basis_grams' => $shape['default_grams'],
                'energy_kcal' => $shape['default_calories'],
                'kind' => 'default_calories',
            ];
        }

        throw new LegacyNutritionApplyPlanException(
            'unsupported_candidate_semantics',
            'A selected nutritional representation cannot be represented by the committed catalog schema.',
        );
    }

    /** @param list<array<string, mixed>> $versions */
    private function nextVersionNumber(array $versions): int
    {
        $maximum = 0;

        foreach ($versions as $version) {
            $maximum = max($maximum, $version['version_number']);
        }

        return $maximum + 1;
    }

    /** @param list<array<string, mixed>> $versions @return array<string, mixed>|null */
    private function versionByPublicId(array $versions, string $publicId): ?array
    {
        foreach ($versions as $version) {
            if ($version['public_id'] === $publicId) {
                return $version;
            }
        }

        return null;
    }

    /**
     * @param  list<array<string, mixed>>  $existingLinks
     * @param  array<string, mixed>  $expected
     */
    private function sourceLinkAction(array $existingLinks, array $expected): string
    {
        if ($existingLinks === []) {
            return 'create';
        }

        if (count($existingLinks) !== 1 || $existingLinks[0] !== $expected) {
            $this->conflict('The selected version source association is different or ambiguous.');
        }

        return 'unchanged';
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return list<array<string, mixed>>
     */
    private function aliasPlans(
        array $entry,
        string $referencePublicId,
        CatalogImportApplyPlanSnapshot $snapshot,
        LegacyNutritionReviewManifest $manifest,
    ): array {
        $plans = [];
        $allExistingAliases = [];

        foreach ($snapshot->aliasesByReferencePublicId as $aliases) {
            $allExistingAliases = [...$allExistingAliases, ...$aliases];
        }

        foreach ($entry['alias_decisions'] as $decision) {
            if ($decision['status'] === 'exclude') {
                $plans[] = [
                    'action' => 'excluded',
                    'normalized_alias' => $decision['normalized_alias'],
                    'outcome' => 'unchanged',
                ];

                continue;
            }

            $lineageId = CatalogImportDeterministicIdentity::aliasLineageId(
                $referencePublicId,
                $entry['version_locale'],
                $decision['normalized_alias'],
            );
            $lineage = array_values(array_filter(
                $allExistingAliases,
                fn (array $alias): bool => $alias['lineage_id'] === $lineageId,
            ));
            usort($lineage, fn (array $left, array $right): int => $left['revision_number'] <=> $right['revision_number']);
            $current = $lineage === [] ? null : $lineage[array_key_last($lineage)];

            if (
                $current !== null
                && (
                    $current['reference_public_id'] !== $referencePublicId
                    || $current['locale'] !== $entry['version_locale']
                    || $current['normalized_alias'] !== $decision['normalized_alias']
                )
            ) {
                $this->conflict('An alias lineage identity has conflicting immutable semantics.');
            }

            $revisionNumber = $current === null ? 1 : $current['revision_number'];
            $provenance = [
                'artifact_id' => LegacyCatalogArtifactDescriptor::ARTIFACT_ID,
                'raw_variants' => $decision['raw_variants'],
                'source_checksum' => ['algorithm' => 'sha256', 'digest' => $manifest->sourceChecksum()],
                'source_record_key' => $entry['source_record_key'],
            ];
            $base = [
                'alias_kind' => $decision['alias_kind'],
                'archived' => false,
                'display_alias' => $decision['representative_raw_alias'],
                'lineage_id' => $lineageId,
                'locale' => $entry['version_locale'],
                'lifecycle_state' => [
                    'activated' => false,
                    'deactivated' => false,
                    'published' => false,
                    'reviewed' => false,
                    'submitted' => false,
                    'withdrawn' => false,
                ],
                'normalized_alias' => $decision['normalized_alias'],
                'provenance' => $provenance,
                'reference_public_id' => $referencePublicId,
                'review_status' => 'draft',
                'source_public_id' => self::SOURCE_PUBLIC_ID,
                'source_record_key' => $entry['source_record_key'],
            ];
            $currentComparable = $current === null ? null : array_intersect_key($current, [...$base, 'predecessor_public_id' => null, 'public_id' => null, 'revision_number' => null]);
            $expectedCurrent = $current === null ? null : [
                ...$base,
                'predecessor_public_id' => $current['predecessor_public_id'],
                'public_id' => $current['public_id'],
                'revision_number' => $current['revision_number'],
            ];

            if ($current !== null && $this->semanticEquals($currentComparable, $expectedCurrent)) {
                $action = 'unchanged';
                $semantic = $expectedCurrent;
            } else {
                $action = $current === null ? 'create_lineage' : 'create_revision';
                $revisionNumber = $current === null ? 1 : $current['revision_number'] + 1;
                $semantic = [
                    ...$base,
                    'predecessor_public_id' => $current['public_id'] ?? null,
                    'public_id' => CatalogImportDeterministicIdentity::aliasRevisionPublicId($lineageId, $revisionNumber),
                    'revision_number' => $revisionNumber,
                ];

                foreach ($allExistingAliases as $existingAlias) {
                    if ($existingAlias['public_id'] === $semantic['public_id'] && $existingAlias !== $semantic) {
                        $this->conflict('A deterministic alias revision UUID already has different semantics.');
                    }
                }
            }

            $plans[] = [
                'action' => $action,
                'outcome' => $action === 'unchanged' ? 'unchanged' : 'planned',
                'semantic_entity' => $semantic,
            ];
        }

        usort($plans, fn (array $left, array $right): int => strcmp(
            $left['semantic_entity']['normalized_alias'] ?? $left['normalized_alias'],
            $right['semantic_entity']['normalized_alias'] ?? $right['normalized_alias'],
        ));

        return $plans;
    }

    /**
     * @param  list<array<string, mixed>>  $aliasPlans
     * @return list<array<string, string>>
     */
    private function lifecycleTemplates(
        string $referenceAction,
        string $referencePublicId,
        string $versionAction,
        string $versionPublicId,
        array $aliasPlans,
    ): array {
        $operations = [];

        if ($referenceAction === 'create') {
            $operations[] = [
                'operation' => CatalogLifecycleOperation::CreateReference->value,
                'subject_public_id' => $referencePublicId,
                'subject_type' => 'food_reference',
            ];
        }

        if ($versionAction === 'create') {
            $operations[] = [
                'initial_state' => 'draft',
                'operation' => CatalogLifecycleOperation::CreateDraft->value,
                'subject_public_id' => $versionPublicId,
                'subject_type' => 'food_reference_version',
            ];
        }

        foreach ($aliasPlans as $aliasPlan) {
            if (in_array($aliasPlan['action'], ['create_lineage', 'create_revision'], true)) {
                $operations[] = [
                    'initial_state' => 'draft',
                    'operation' => CatalogLifecycleOperation::CreateDraft->value,
                    'subject_public_id' => $aliasPlan['semantic_entity']['public_id'],
                    'subject_type' => 'food_alias',
                ];
            }
        }

        return $operations;
    }

    /** @return array<string, int> */
    private function initialCounts(LoadedCatalogImportReviewedResolution $resolution): array
    {
        $total = count($resolution->document['review_entries']);
        $selected = count($resolution->selectedEntries);

        return [
            'conflicts' => 0,
            'no_op_graphs' => 0,
            'omitted_unresolved' => $total - $resolution->eligibleEntryCount,
            'omitted_unselected' => $total - $selected,
            'planned_alias_lineages' => 0,
            'planned_alias_revisions' => 0,
            'planned_references' => 0,
            'planned_source_links' => 0,
            'planned_sources' => 0,
            'planned_versions' => 0,
            'selected_candidates' => $selected,
            'selected_suspicious' => 0,
            'selected_valid' => 0,
            'unchanged_graphs' => 0,
        ];
    }

    /** @param array<string, int> $counts @param array<string, mixed> $plan */
    private function accumulateCounts(array &$counts, array $plan): void
    {
        $classificationKey = $plan['candidate_classification'] === 'valid_candidate'
            ? 'selected_valid'
            : 'selected_suspicious';
        $counts[$classificationKey]++;
        $counts['planned_references'] += $plan['reference_plan']['action'] === 'create' ? 1 : 0;
        $counts['planned_versions'] += $plan['version_plan']['action'] === 'create' ? 1 : 0;
        $counts['planned_source_links'] += $plan['source_link_plan']['action'] === 'create' ? 1 : 0;
        $counts['no_op_graphs'] += $plan['graph_outcome'] === 'no_op' ? 1 : 0;
        $counts['unchanged_graphs'] += $plan['graph_outcome'] === 'no_op' ? 1 : 0;

        foreach ($plan['alias_plans'] as $aliasPlan) {
            $counts['planned_alias_lineages'] += $aliasPlan['action'] === 'create_lineage' ? 1 : 0;
            $counts['planned_alias_revisions'] += in_array(
                $aliasPlan['action'],
                ['create_lineage', 'create_revision'],
                true,
            ) ? 1 : 0;
        }
    }

    private function conflict(string $message): never
    {
        throw new LegacyNutritionApplyPlanException('catalog_conflict', $message);
    }

    private function semanticEquals(mixed $left, mixed $right): bool
    {
        return CanonicalCatalogImportJson::serializeSemanticGraph(['value' => $left])
            === CanonicalCatalogImportJson::serializeSemanticGraph(['value' => $right]);
    }
}
