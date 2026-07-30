<?php

namespace App\Nutrition\Application\Catalog\Import;

use App\Nutrition\Application\Catalog\Import\Enums\CatalogImportCandidateClassification;
use App\Nutrition\Application\Catalog\Import\Enums\CatalogImportIssueCode;
use App\Nutrition\Application\Catalog\Import\Exceptions\LegacyNutritionImportPlanningException;
use App\Nutrition\Application\Catalog\Import\ValueObjects\CanonicalManifestChecksum;
use App\Nutrition\Application\Catalog\Import\ValueObjects\CatalogImportCandidateDecision;
use App\Nutrition\Application\Catalog\Import\ValueObjects\CatalogImportIdentityResolution;
use App\Nutrition\Application\Catalog\Import\ValueObjects\CatalogImportIssueSet;
use App\Nutrition\Application\Catalog\Import\ValueObjects\CatalogImportManifestSchema;
use App\Nutrition\Application\Catalog\Import\ValueObjects\CatalogImportSelection;
use App\Nutrition\Application\Catalog\Import\ValueObjects\LegacyCatalogArtifactDescriptor;
use App\Nutrition\Application\Catalog\Import\ValueObjects\LegacyNutritionPlanningResult;
use App\Nutrition\Application\Catalog\Import\ValueObjects\LoadedLegacyNutritionSource;
use App\Nutrition\Application\Catalog\NormalizeFoodText;
use JsonException;

final class LegacyNutritionCandidateManifestGenerator
{
    private const APPROVED_SOURCE_SHA256 = '0d3987cc616b40e878731ecda0127a5b0a065f9557977b5b8f4ec0091d4ecc21';

    private const APPROVED_SOURCE_BYTE_SIZE = 25676;

    /** @var list<string> */
    private const RECORD_FIELDS = [
        'aliases',
        'calories_per_100g',
        'default_calories',
        'default_grams',
        'source',
        'confidence',
        'high_variation',
        'variation_note',
        'is_cooking_fat',
    ];

    /** @var list<string> */
    private const GENERIC_ALIASES = [
        'carne',
        'peixe',
        'massa',
        'pao',
        'farinha',
        'leite',
        'iogurte',
        'queijo',
        'suco natural',
        'bolo',
    ];

    /** @var list<string> */
    private const BRAND_ALIASES = [
        'coca',
        'coca cola',
        'amstel',
        'amistel',
    ];

    /** @var list<string> */
    private const PREPARATION_TERMS = [
        'cozido',
        'cozida',
        'grelhado',
        'grelhada',
        'assado',
        'assada',
        'frito',
        'frita',
        'refogado',
        'refogada',
        'enlatado',
        'enlatada',
        'temperado',
        'temperada',
        'torrado',
        'torrada',
        'natural',
        'sem acucar',
        'com acucar',
        'com leite',
    ];

    public function __construct(private NormalizeFoodText $normalizeFoodText) {}

    public function generate(
        LoadedLegacyNutritionSource $source,
        string $repositoryCommit,
    ): LegacyNutritionPlanningResult {
        $this->assertApprovedSourceEvidence($source);
        $references = $this->referencesFromPayload($source->payload);
        $candidateContexts = [];
        $normalizedAliasOwners = [];

        foreach ($references as $sourceRecordKey => $record) {
            if (! is_string($sourceRecordKey) || ! is_array($record)) {
                throw new LegacyNutritionImportPlanningException('The approved legacy references have an unsupported shape.');
            }

            $candidateContext = $this->candidateContext(
                sourceRecordKey: $sourceRecordKey,
                sourceOrdinal: count($candidateContexts) + 1,
                record: $record,
            );
            $candidateContexts[] = $candidateContext;

            foreach ($candidateContext['alias_groups'] as $aliasGroup) {
                $normalizedAliasOwners[$aliasGroup['normalized_alias']][$sourceRecordKey] = [
                    'raw_variants' => $aliasGroup['raw_variants'],
                    'source_alias_ordinals' => $aliasGroup['source_alias_ordinals'],
                ];
            }
        }

        $crossCandidateCollisions = $this->crossCandidateCollisions($normalizedAliasOwners);
        $crossCollisionKeys = [];

        foreach ($crossCandidateCollisions as $collision) {
            foreach ($collision['source_record_keys'] as $sourceRecordKey) {
                $crossCollisionKeys[$sourceRecordKey] = true;
            }
        }

        $records = [];
        $classificationCounts = array_fill_keys(
            array_column(CatalogImportCandidateClassification::cases(), 'value'),
            0,
        );
        $sourceDeclarationCounts = [
            'missing' => 0,
            'taco' => 0,
            'app_estimate' => 0,
        ];
        $calorieShapeCounts = [
            'calories_per_100g' => 0,
            'default_calories' => 0,
            'valid_explicit_portions' => 0,
        ];
        $rawAliasCount = 0;
        $normalizedAliasCount = 0;
        $withinCollisionGroupCount = 0;
        $referencesWithWithinCollisions = 0;
        $duplicateNormalizedOccurrences = 0;

        foreach ($candidateContexts as $candidateContext) {
            $hasCrossCollision = isset($crossCollisionKeys[$candidateContext['source_record_key']]);
            $record = $this->manifestRecord($source, $candidateContext, $hasCrossCollision);
            $records[] = $record;
            $classificationCounts[$record['candidate_classification']]++;
            $rawAliasCount += count($candidateContext['raw_aliases']);
            $normalizedAliasCount += count($candidateContext['alias_groups']);
            $withinCollisionGroupCount += count($candidateContext['within_candidate_collisions']);

            if ($candidateContext['within_candidate_collisions'] !== []) {
                $referencesWithWithinCollisions++;
            }

            foreach ($candidateContext['within_candidate_collisions'] as $collision) {
                $duplicateNormalizedOccurrences += $collision['duplicate_normalized_occurrences'];
            }

            $declaredSource = $candidateContext['record']['source'] ?? null;

            if ($declaredSource === null) {
                $sourceDeclarationCounts['missing']++;
            } elseif (isset($sourceDeclarationCounts[$declaredSource])) {
                $sourceDeclarationCounts[$declaredSource]++;
            }

            if (array_key_exists('calories_per_100g', $candidateContext['record'])) {
                $calorieShapeCounts['calories_per_100g']++;
            }

            if (array_key_exists('default_calories', $candidateContext['record'])) {
                $calorieShapeCounts['default_calories']++;
            }
        }

        $inventory = [
            'aliases' => [
                'duplicate_normalized_occurrences' => $duplicateNormalizedOccurrences,
                'normalized' => $normalizedAliasCount,
                'raw' => $rawAliasCount,
            ],
            'calorie_shapes' => $calorieShapeCounts,
            'candidates' => [
                ...$classificationCounts,
                'total' => count($records),
            ],
            'collisions' => [
                'cross_candidate_groups' => count($crossCandidateCollisions),
                'normalized_groups' => $withinCollisionGroupCount,
                'references_containing_groups' => $referencesWithWithinCollisions,
            ],
            'identity_resolution' => [
                'planned_child_identities' => 0,
                'planned_new_reference_identities' => count($records),
                'planned_source_identities' => 1,
                'selected' => 0,
                'unresolved' => count($records),
            ],
            'source_declarations' => $sourceDeclarationCounts,
        ];

        $this->assertApprovedInventory($inventory);

        $descriptor = new LegacyCatalogArtifactDescriptor(
            title: 'Nutri legacy nutrition configuration',
            checksum: $source->checksum,
            artifactPath: $source->artifactPath,
            sourceFormat: 'php_return_array',
            byteSize: $source->byteSize(),
            repositoryCommit: $repositoryCommit,
        );
        $manifest = [
            'collision_information' => [
                'cross_candidate_groups' => $crossCandidateCollisions,
                'within_candidate_summary' => [
                    'duplicate_normalized_occurrences' => $duplicateNormalizedOccurrences,
                    'normalized_collision_groups' => $withinCollisionGroupCount,
                    'references_containing_collision_groups' => $referencesWithWithinCollisions,
                ],
            ],
            'extraction_summary' => $inventory,
            'logical_artifact' => [
                'artifact_id' => LegacyCatalogArtifactDescriptor::ARTIFACT_ID,
                'artifact_path' => $source->artifactPath,
            ],
            'records' => $records,
            'schema' => CatalogImportManifestSchema::IDENTIFIER,
            'source' => $descriptor->toCanonicalArray(),
            'source_identity' => [
                'canonical_name' => CatalogImportDeterministicIdentity::sourceCanonicalName(),
                'public_id' => CatalogImportDeterministicIdentity::sourcePublicId(),
            ],
        ];

        try {
            $canonicalManifestBytes = CanonicalCatalogImportJson::serializeManifest($manifest);
        } catch (JsonException $exception) {
            throw new LegacyNutritionImportPlanningException(
                'The candidate manifest could not be serialized canonically.',
                previous: $exception,
            );
        }

        $manifestChecksum = CanonicalManifestChecksum::fromCanonicalBytes($canonicalManifestBytes);
        $summary = [
            'alias_counts' => $inventory['aliases'],
            'byte_size' => $source->byteSize(),
            'calorie_shape_counts' => $calorieShapeCounts,
            'candidate_counts' => $inventory['candidates'],
            'collision_counts' => $inventory['collisions'],
            'identity_counts' => $inventory['identity_resolution'],
            'source_checksum' => [
                'algorithm' => $source->checksum->algorithm,
                'digest' => $source->checksum->digest,
            ],
            'source_declaration_counts' => $sourceDeclarationCounts,
            'source_path' => $source->artifactPath,
        ];

        return new LegacyNutritionPlanningResult(
            manifest: $manifest,
            canonicalManifestBytes: $canonicalManifestBytes,
            manifestChecksum: $manifestChecksum,
            summary: $summary,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, array<string, mixed>>
     */
    private function referencesFromPayload(array $payload): array
    {
        if (array_keys($payload) !== ['estimation']) {
            throw new LegacyNutritionImportPlanningException('The approved legacy source top-level shape has drifted.');
        }

        $estimation = $payload['estimation'];

        if (
            ! is_array($estimation)
            || array_is_list($estimation)
            || array_keys($estimation) !== ['preparation_retention_factor', 'measurements', 'references']
            || ! is_float($estimation['preparation_retention_factor'])
            || ! is_array($estimation['measurements'])
            || array_is_list($estimation['measurements'])
            || ! is_array($estimation['references'])
            || array_is_list($estimation['references'])
        ) {
            throw new LegacyNutritionImportPlanningException('The approved legacy estimation shape has drifted.');
        }

        return $estimation['references'];
    }

    /**
     * @param  array<string, mixed>  $record
     * @return array{
     *     alias_groups: list<array{
     *         normalized_alias: string,
     *         raw_variants: list<string>,
     *         representative_raw_alias: string,
     *         source_alias_ordinals: list<int>
     *     }>,
     *     raw_aliases: list<string>,
     *     record: array<string, mixed>,
     *     source_ordinal: int,
     *     source_record_key: string,
     *     structural_issues: list<string>,
     *     within_candidate_collisions: list<array{
     *         duplicate_normalized_occurrences: int,
     *         normalized_alias: string,
     *         raw_variants: list<string>
     *     }>
     * }
     */
    private function candidateContext(string $sourceRecordKey, int $sourceOrdinal, array $record): array
    {
        $structuralIssues = $this->structuralIssues($sourceRecordKey, $record);
        $rawAliases = isset($record['aliases']) && is_array($record['aliases']) && array_is_list($record['aliases'])
            ? array_values(array_filter($record['aliases'], is_string(...)))
            : [];
        $groupedAliases = [];

        foreach ($rawAliases as $aliasOrdinal => $rawAlias) {
            if (trim($rawAlias) === '' || ! mb_check_encoding($rawAlias, 'UTF-8')) {
                continue;
            }

            $normalizedAlias = $this->normalizeFoodText->normalize($rawAlias)->value;
            $groupedAliases[$normalizedAlias][] = [
                'raw_alias' => $rawAlias,
                'source_alias_ordinal' => $aliasOrdinal + 1,
            ];
        }

        ksort($groupedAliases, SORT_STRING);
        $aliasGroups = [];
        $withinCandidateCollisions = [];

        foreach ($groupedAliases as $normalizedAlias => $occurrences) {
            $sortedOccurrences = $occurrences;
            usort(
                $sortedOccurrences,
                fn (array $left, array $right): int => strcmp($left['raw_alias'], $right['raw_alias'])
                    ?: ($left['source_alias_ordinal'] <=> $right['source_alias_ordinal']),
            );
            $rawVariants = array_column($sortedOccurrences, 'raw_alias');
            $sourceAliasOrdinals = array_column($occurrences, 'source_alias_ordinal');
            $aliasGroups[] = [
                'normalized_alias' => $normalizedAlias,
                'raw_variants' => $rawVariants,
                'representative_raw_alias' => $rawVariants[0],
                'source_alias_ordinals' => $sourceAliasOrdinals,
            ];

            if (count($occurrences) > 1) {
                $withinCandidateCollisions[] = [
                    'duplicate_normalized_occurrences' => count($occurrences) - 1,
                    'normalized_alias' => $normalizedAlias,
                    'raw_variants' => $rawVariants,
                ];
            }
        }

        return [
            'alias_groups' => $aliasGroups,
            'raw_aliases' => $rawAliases,
            'record' => $record,
            'source_ordinal' => $sourceOrdinal,
            'source_record_key' => $sourceRecordKey,
            'structural_issues' => $structuralIssues,
            'within_candidate_collisions' => $withinCandidateCollisions,
        ];
    }

    /**
     * @param  array<string, mixed>  $record
     * @return list<string>
     */
    private function structuralIssues(string $sourceRecordKey, array $record): array
    {
        $issues = [];

        if (
            trim($sourceRecordKey) === ''
            || trim($sourceRecordKey) !== $sourceRecordKey
            || ! mb_check_encoding($sourceRecordKey, 'UTF-8')
        ) {
            $issues[] = 'source_record_key_invalid';
        }

        if (array_is_list($record) || array_diff(array_keys($record), self::RECORD_FIELDS) !== []) {
            $issues[] = 'record_fields_invalid';
        }

        if (
            ! isset($record['aliases'])
            || ! is_array($record['aliases'])
            || ! array_is_list($record['aliases'])
            || $record['aliases'] === []
        ) {
            $issues[] = 'aliases_invalid';
        } else {
            foreach ($record['aliases'] as $alias) {
                if (
                    ! is_string($alias)
                    || trim($alias) === ''
                    || trim($alias) !== $alias
                    || ! mb_check_encoding($alias, 'UTF-8')
                ) {
                    $issues[] = 'aliases_invalid';
                    break;
                }
            }
        }

        $hasPerHundredCalories = array_key_exists('calories_per_100g', $record);
        $hasDefaultCalories = array_key_exists('default_calories', $record);

        if ($hasPerHundredCalories === $hasDefaultCalories) {
            $issues[] = 'nutritional_shape_invalid';
        }

        foreach (['calories_per_100g', 'default_calories', 'default_grams'] as $positiveIntegerField) {
            if (
                ($positiveIntegerField === 'default_grams' || array_key_exists($positiveIntegerField, $record))
                && (! isset($record[$positiveIntegerField])
                    || ! is_int($record[$positiveIntegerField])
                    || $record[$positiveIntegerField] < 1)
            ) {
                $issues[] = "{$positiveIntegerField}_invalid";
            }
        }

        foreach (['source', 'confidence', 'variation_note'] as $textField) {
            if (
                array_key_exists($textField, $record)
                && (! is_string($record[$textField])
                    || trim($record[$textField]) === ''
                    || trim($record[$textField]) !== $record[$textField]
                    || ! mb_check_encoding($record[$textField], 'UTF-8'))
            ) {
                $issues[] = "{$textField}_invalid";
            }
        }

        foreach (['high_variation', 'is_cooking_fat'] as $booleanField) {
            if (array_key_exists($booleanField, $record) && ! is_bool($record[$booleanField])) {
                $issues[] = "{$booleanField}_invalid";
            }
        }

        return array_values(array_unique($issues));
    }

    /**
     * @param  array<string, array<string, array{raw_variants: list<string>, source_alias_ordinals: list<int>}>>  $normalizedAliasOwners
     * @return list<array{
     *     normalized_alias: string,
     *     occurrences: list<array{raw_variants: list<string>, source_alias_ordinals: list<int>, source_record_key: string}>,
     *     source_record_keys: list<string>
     * }>
     */
    private function crossCandidateCollisions(array $normalizedAliasOwners): array
    {
        ksort($normalizedAliasOwners, SORT_STRING);
        $collisions = [];

        foreach ($normalizedAliasOwners as $normalizedAlias => $owners) {
            if (count($owners) < 2) {
                continue;
            }

            ksort($owners, SORT_STRING);
            $occurrences = [];

            foreach ($owners as $sourceRecordKey => $owner) {
                $occurrences[] = [
                    ...$owner,
                    'source_record_key' => $sourceRecordKey,
                ];
            }

            $collisions[] = [
                'normalized_alias' => $normalizedAlias,
                'occurrences' => $occurrences,
                'source_record_keys' => array_keys($owners),
            ];
        }

        return $collisions;
    }

    /**
     * @param  array{
     *     alias_groups: list<array{
     *         normalized_alias: string,
     *         raw_variants: list<string>,
     *         representative_raw_alias: string,
     *         source_alias_ordinals: list<int>
     *     }>,
     *     raw_aliases: list<string>,
     *     record: array<string, mixed>,
     *     source_ordinal: int,
     *     source_record_key: string,
     *     structural_issues: list<string>,
     *     within_candidate_collisions: list<array{
     *         duplicate_normalized_occurrences: int,
     *         normalized_alias: string,
     *         raw_variants: list<string>
     *     }>
     * }  $candidateContext
     * @return array<string, mixed>
     */
    private function manifestRecord(
        LoadedLegacyNutritionSource $source,
        array $candidateContext,
        bool $hasCrossCollision,
    ): array {
        $classificationReasons = $this->classificationReasons($candidateContext, $hasCrossCollision);
        $classification = $candidateContext['structural_issues'] !== []
            ? CatalogImportCandidateClassification::InvalidCandidate
            : ($classificationReasons === []
                ? CatalogImportCandidateClassification::ValidCandidate
                : CatalogImportCandidateClassification::SuspiciousCandidate);
        $identityIssues = new CatalogImportIssueSet([
            CatalogImportIssueCode::ConceptualIdentityUnresolved,
            CatalogImportIssueCode::GenericityUnresolved,
            CatalogImportIssueCode::ClassificationUnresolved,
            CatalogImportIssueCode::PreparationUnresolved,
            CatalogImportIssueCode::AliasKindUnresolved,
        ]);
        $allIssues = new CatalogImportIssueSet([
            ...$this->candidateIssueCodes($candidateContext, $hasCrossCollision),
            ...$identityIssues->all(),
        ]);
        $decision = new CatalogImportCandidateDecision(
            classification: $classification,
            identityResolution: CatalogImportIdentityResolution::unresolved($identityIssues),
            selection: new CatalogImportSelection(false),
            issues: $allIssues,
        );
        $sourceRecordKey = $candidateContext['source_record_key'];

        return [
            'aliases' => $candidateContext['alias_groups'],
            'calorie_representation' => $this->calorieRepresentation($candidateContext['record']),
            'candidate_classification' => $decision->classification->value,
            'classification_reasons' => $classificationReasons,
            'collision_information' => [
                'cross_candidate_collision' => $hasCrossCollision,
                'within_candidate_groups' => $candidateContext['within_candidate_collisions'],
            ],
            'confidence' => $candidateContext['record']['confidence'] ?? null,
            'declared_source' => $candidateContext['record']['source'] ?? null,
            'identity_resolution' => [
                'alias_identities' => [],
                'classification' => null,
                'existing_reference_public_id' => null,
                'is_generic' => null,
                'issues' => $decision->identityResolution->issues->values(),
                'preparation' => null,
                'reference_target' => null,
                'reference_visibility' => null,
                'stable_key' => null,
                'status' => $decision->identityResolution->status->value,
                'version_locale' => null,
            ],
            'issue_codes' => $decision->issues->values(),
            'legacy_payload_fields' => $this->legacyPayloadFields($candidateContext['record']),
            'planning_identity' => [
                'authority' => 'non_authoritative',
                'canonical_name' => CatalogImportDeterministicIdentity::plannedReferenceCanonicalName($sourceRecordKey),
                'kind' => 'planned_new_reference_uuid',
                'public_id' => CatalogImportDeterministicIdentity::plannedReferencePublicId($sourceRecordKey),
            ],
            'provenance' => [
                'artifact_id' => LegacyCatalogArtifactDescriptor::ARTIFACT_ID,
                'artifact_path' => $source->artifactPath,
                'source_checksum' => [
                    'algorithm' => $source->checksum->algorithm,
                    'digest' => $source->checksum->digest,
                ],
                'source_ordinal' => $candidateContext['source_ordinal'],
                'source_record_key' => $sourceRecordKey,
            ],
            'raw_aliases_in_source_order' => $candidateContext['raw_aliases'],
            'selected_for_apply' => $decision->selection->selectedForApply,
            'source_ordinal' => $candidateContext['source_ordinal'],
            'source_record_key' => $sourceRecordKey,
            'valid_explicit_portions' => [],
        ];
    }

    /**
     * @param  array{
     *     alias_groups: list<array{
     *         normalized_alias: string,
     *         raw_variants: list<string>,
     *         representative_raw_alias: string,
     *         source_alias_ordinals: list<int>
     *     }>,
     *     raw_aliases: list<string>,
     *     record: array<string, mixed>,
     *     source_ordinal: int,
     *     source_record_key: string,
     *     structural_issues: list<string>,
     *     within_candidate_collisions: list<array{
     *         duplicate_normalized_occurrences: int,
     *         normalized_alias: string,
     *         raw_variants: list<string>
     *     }>
     * }  $candidateContext
     * @return list<string>
     */
    private function classificationReasons(array $candidateContext, bool $hasCrossCollision): array
    {
        if ($candidateContext['structural_issues'] !== []) {
            return ['structural_shape_invalid'];
        }

        $record = $candidateContext['record'];
        $normalizedAliases = array_column($candidateContext['alias_groups'], 'normalized_alias');
        $normalizedIdentityTexts = [
            $this->normalizeFoodText->normalize($candidateContext['source_record_key'])->value,
            ...$normalizedAliases,
        ];
        $reasons = [];

        if (! array_key_exists('source', $record)) {
            $reasons[] = 'source_declaration_missing';
        }

        if (($record['source'] ?? null) === 'app_estimate') {
            $reasons[] = 'application_estimate';
        }

        if (array_key_exists('default_calories', $record)) {
            $reasons[] = 'default_calories_assumption';
        }

        if (($record['high_variation'] ?? false) === true) {
            $reasons[] = 'high_variation';
        }

        if (($record['is_cooking_fat'] ?? false) === true) {
            $reasons[] = 'cooking_fat_role';
        }

        if (array_intersect($normalizedAliases, self::GENERIC_ALIASES) !== []) {
            $reasons[] = 'generic_or_ambiguous_alias';
        }

        if (array_intersect($normalizedAliases, self::BRAND_ALIASES) !== []) {
            $reasons[] = 'brand_alias';
        }

        if ($this->containsPreparationTerm($normalizedIdentityTexts)) {
            $reasons[] = 'preparation_or_recipe_identity';
        }

        if (str_contains($candidateContext['source_record_key'], '/')) {
            $reasons[] = 'combined_identity';
        }

        if ($candidateContext['within_candidate_collisions'] !== []) {
            $reasons[] = 'within_candidate_normalization_collision';
        }

        if ($hasCrossCollision) {
            $reasons[] = 'cross_candidate_normalization_collision';
        }

        return $reasons;
    }

    /**
     * @param  array{
     *     alias_groups: list<array{
     *         normalized_alias: string,
     *         raw_variants: list<string>,
     *         representative_raw_alias: string,
     *         source_alias_ordinals: list<int>
     *     }>,
     *     raw_aliases: list<string>,
     *     record: array<string, mixed>,
     *     source_ordinal: int,
     *     source_record_key: string,
     *     structural_issues: list<string>,
     *     within_candidate_collisions: list<array{
     *         duplicate_normalized_occurrences: int,
     *         normalized_alias: string,
     *         raw_variants: list<string>
     *     }>
     * }  $candidateContext
     * @return list<CatalogImportIssueCode>
     */
    private function candidateIssueCodes(array $candidateContext, bool $hasCrossCollision): array
    {
        $record = $candidateContext['record'];
        $normalizedAliases = array_column($candidateContext['alias_groups'], 'normalized_alias');
        $normalizedIdentityTexts = [
            $this->normalizeFoodText->normalize($candidateContext['source_record_key'])->value,
            ...$normalizedAliases,
        ];
        $issues = [CatalogImportIssueCode::SourceUntrusted];

        if ($candidateContext['structural_issues'] !== []) {
            $issues[] = CatalogImportIssueCode::StructuralShapeInvalid;
        }

        if (! array_key_exists('source', $record)) {
            $issues[] = CatalogImportIssueCode::SourceDeclarationMissing;
        }

        if (($record['source'] ?? null) === 'app_estimate') {
            $issues[] = CatalogImportIssueCode::ApplicationEstimate;
        }

        if (array_key_exists('default_calories', $record)) {
            $issues[] = CatalogImportIssueCode::DefaultCaloriesAssumption;
        }

        if ($candidateContext['within_candidate_collisions'] !== [] || $hasCrossCollision) {
            $issues[] = CatalogImportIssueCode::NormalizationCollision;
        }

        if ($candidateContext['within_candidate_collisions'] !== []) {
            $issues[] = CatalogImportIssueCode::DuplicateAlias;
        }

        if (array_intersect($normalizedAliases, self::GENERIC_ALIASES) !== []) {
            $issues[] = CatalogImportIssueCode::GenericityUnresolved;
        }

        if (array_intersect($normalizedAliases, self::BRAND_ALIASES) !== []) {
            $issues[] = CatalogImportIssueCode::AliasKindUnresolved;
        }

        if ($this->containsPreparationTerm($normalizedIdentityTexts)) {
            $issues[] = CatalogImportIssueCode::PreparationUnresolved;
        }

        if (
            ($record['high_variation'] ?? false) === true
            || ($record['is_cooking_fat'] ?? false) === true
        ) {
            $issues[] = CatalogImportIssueCode::ClassificationUnresolved;
        }

        if (str_contains($candidateContext['source_record_key'], '/')) {
            $issues[] = CatalogImportIssueCode::ConceptualIdentityUnresolved;
        }

        return $issues;
    }

    /** @param list<string> $normalizedTexts */
    private function containsPreparationTerm(array $normalizedTexts): bool
    {
        foreach ($normalizedTexts as $normalizedText) {
            foreach (self::PREPARATION_TERMS as $preparationTerm) {
                if (
                    $normalizedText === $preparationTerm
                    || str_starts_with($normalizedText, $preparationTerm.' ')
                    || str_ends_with($normalizedText, ' '.$preparationTerm)
                    || str_contains($normalizedText, ' '.$preparationTerm.' ')
                ) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $record
     * @return array<string, int|string|null>
     */
    private function calorieRepresentation(array $record): array
    {
        if (array_key_exists('calories_per_100g', $record)) {
            return [
                'basis_grams' => 100,
                'calories_per_100g' => $record['calories_per_100g'],
                'default_grams' => $record['default_grams'] ?? null,
                'kind' => 'calories_per_100g',
            ];
        }

        return [
            'default_calories' => $record['default_calories'] ?? null,
            'default_grams' => $record['default_grams'] ?? null,
            'kind' => 'default_calories',
        ];
    }

    /**
     * @param  array<string, mixed>  $record
     * @return list<array{field: string, value: mixed}>
     */
    private function legacyPayloadFields(array $record): array
    {
        $fields = [];

        foreach ($record as $field => $value) {
            $fields[] = [
                'field' => $field,
                'value' => $value,
            ];
        }

        return $fields;
    }

    private function assertApprovedSourceEvidence(LoadedLegacyNutritionSource $source): void
    {
        if (
            $source->artifactPath !== LegacyNutritionSourceLoader::APPROVED_ARTIFACT_PATH
            || $source->checksum->digest !== self::APPROVED_SOURCE_SHA256
            || $source->byteSize() !== self::APPROVED_SOURCE_BYTE_SIZE
        ) {
            throw new LegacyNutritionImportPlanningException('The approved legacy source evidence has drifted.');
        }
    }

    /** @param array<string, mixed> $inventory */
    private function assertApprovedInventory(array $inventory): void
    {
        $expectedInventory = [
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
        ];

        if ($inventory !== $expectedInventory) {
            throw new LegacyNutritionImportPlanningException('The approved legacy source inventory has drifted.');
        }
    }
}
