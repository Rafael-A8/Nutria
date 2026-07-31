<?php

namespace App\Nutrition\Infrastructure\Catalog\Import;

use App\Nutrition\Application\Catalog\Import\Enums\CatalogImportReviewReferenceTarget;
use App\Nutrition\Application\Catalog\Import\Exceptions\LegacyNutritionImportReviewException;
use App\Nutrition\Application\Catalog\Import\ValueObjects\CatalogImportPreflightResult;
use App\Nutrition\Infrastructure\Catalog\Eloquent\FoodAlias;
use App\Nutrition\Infrastructure\Catalog\Eloquent\FoodReference;
use App\Nutrition\Infrastructure\Catalog\Eloquent\FoodReferenceVersion;
use App\Nutrition\Infrastructure\Catalog\Eloquent\FoodReferenceVersionSource;
use App\Nutrition\Infrastructure\Catalog\Eloquent\FoodSource;
use BackedEnum;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;

final class ReadOnlyCatalogImportPreflight
{
    /**
     * @param  list<array<string, mixed>>  $candidates
     */
    public function inspect(array $candidates): CatalogImportPreflightResult
    {
        $candidatesByKey = $this->validatedCandidates($candidates);
        $queryKinds = [];
        $catalogCounts = [
            'aliases' => $this->count(FoodAlias::class, 'count_aliases', $queryKinds),
            'reference_version_sources' => $this->count(
                FoodReferenceVersionSource::class,
                'count_reference_version_sources',
                $queryKinds,
            ),
            'reference_versions' => $this->count(
                FoodReferenceVersion::class,
                'count_reference_versions',
                $queryKinds,
            ),
            'references' => $this->count(FoodReference::class, 'count_references', $queryKinds),
            'sources' => $this->count(FoodSource::class, 'count_sources', $queryKinds),
        ];
        $matchesByCandidate = array_fill_keys(array_keys($candidatesByKey), []);
        $conflictsByCandidate = array_fill_keys(array_keys($candidatesByKey), []);
        $evidenceCounts = [
            'normalized_alias' => 0,
            'normalized_canonical_name' => 0,
            'public_uuid' => 0,
            'stable_key' => 0,
            'total' => 0,
        ];
        $conflictCounts = [
            'immutable_field' => 0,
            'public_uuid' => 0,
            'stable_key' => 0,
            'total' => 0,
        ];

        $referenceRows = $this->exactReferenceRows($candidatesByKey, $queryKinds);
        $this->mapReferenceEvidence(
            candidatesByKey: $candidatesByKey,
            referenceRows: $referenceRows,
            matchesByCandidate: $matchesByCandidate,
            conflictsByCandidate: $conflictsByCandidate,
            evidenceCounts: $evidenceCounts,
            conflictCounts: $conflictCounts,
        );

        $canonicalRows = $this->exactCanonicalRows($candidatesByKey, $queryKinds);
        $sourceRows = $this->sourceRowsForVersions($canonicalRows, $queryKinds);
        $this->mapCanonicalEvidence(
            candidatesByKey: $candidatesByKey,
            canonicalRows: $canonicalRows,
            sourceRows: $sourceRows,
            matchesByCandidate: $matchesByCandidate,
            evidenceCounts: $evidenceCounts,
        );

        $aliasRows = $this->exactAliasRows($candidatesByKey, $queryKinds);
        $this->mapAliasEvidence(
            candidatesByKey: $candidatesByKey,
            aliasRows: $aliasRows,
            matchesByCandidate: $matchesByCandidate,
            evidenceCounts: $evidenceCounts,
        );

        foreach ($matchesByCandidate as &$matches) {
            usort($matches, fn (array $left, array $right): int => $this->compareEvidence($left, $right));
        }
        unset($matches);

        foreach ($conflictsByCandidate as &$conflicts) {
            usort(
                $conflicts,
                fn (array $left, array $right): int => strcmp($left['conflict_type'], $right['conflict_type'])
                    ?: strcmp($left['existing_reference_public_id'] ?? '', $right['existing_reference_public_id'] ?? ''),
            );
        }
        unset($conflicts);

        $evidenceCounts['total'] = array_sum(array_map('count', $matchesByCandidate));
        $conflictCounts['total'] = array_sum(array_map('count', $conflictsByCandidate));

        return new CatalogImportPreflightResult(
            catalogCounts: $catalogCounts,
            matchesByCandidate: $matchesByCandidate,
            conflictsByCandidate: $conflictsByCandidate,
            evidenceCounts: $evidenceCounts,
            conflictCounts: $conflictCounts,
            queryCount: count($queryKinds),
            queryKinds: $queryKinds,
        );
    }

    /**
     * @param  class-string<FoodAlias|FoodReference|FoodReferenceVersion|FoodReferenceVersionSource|FoodSource>  $model
     * @param  list<string>  $queryKinds
     */
    private function count(string $model, string $queryKind, array &$queryKinds): int
    {
        $queryKinds[] = $queryKind;

        return $model::query()->count();
    }

    /**
     * @param  list<array<string, mixed>>  $candidates
     * @return array<string, array<string, mixed>>
     */
    private function validatedCandidates(array $candidates): array
    {
        if (! array_is_list($candidates) || $candidates === []) {
            throw new LegacyNutritionImportReviewException('Catalog preflight requires a nonempty candidate list.');
        }

        $candidatesByKey = [];

        foreach ($candidates as $candidate) {
            $sourceRecordKey = $candidate['source_record_key'] ?? null;
            $normalizedCanonicalName = $candidate['normalized_canonical_name'] ?? null;
            $normalizedAliases = $candidate['normalized_aliases'] ?? null;

            if (
                ! is_string($sourceRecordKey)
                || trim($sourceRecordKey) === ''
                || isset($candidatesByKey[$sourceRecordKey])
                || ! is_string($normalizedCanonicalName)
                || trim($normalizedCanonicalName) === ''
                || ! is_array($normalizedAliases)
                || ! array_is_list($normalizedAliases)
            ) {
                throw new LegacyNutritionImportReviewException('Catalog preflight candidate evidence is malformed.');
            }

            $candidatesByKey[$sourceRecordKey] = $candidate;
        }

        ksort($candidatesByKey, SORT_STRING);

        return $candidatesByKey;
    }

    /**
     * @param  array<string, array<string, mixed>>  $candidatesByKey
     * @param  list<string>  $queryKinds
     * @return Collection<int, object>
     */
    private function exactReferenceRows(array $candidatesByKey, array &$queryKinds): Collection
    {
        $stableKeys = $this->candidateValues($candidatesByKey, 'stable_key');
        $publicIds = $this->candidateValues($candidatesByKey, 'existing_reference_public_id');

        if ($stableKeys === [] && $publicIds === []) {
            return collect();
        }

        $queryKinds[] = 'exact_reference_identity_matches';

        return FoodReference::query()
            ->select([
                'public_id',
                'stable_key',
                'visibility',
                'owner_user_id',
                'is_generic',
                'archived_at',
            ])
            ->where(function ($query) use ($stableKeys, $publicIds): void {
                if ($stableKeys !== []) {
                    $query->whereIn('stable_key', $stableKeys);
                }

                if ($publicIds !== []) {
                    $method = $stableKeys === [] ? 'whereIn' : 'orWhereIn';
                    $query->{$method}('public_id', $publicIds);
                }
            })
            ->orderBy('public_id')
            ->get();
    }

    /**
     * @param  array<string, array<string, mixed>>  $candidatesByKey
     * @param  list<string>  $queryKinds
     * @return Collection<int, object>
     */
    private function exactCanonicalRows(array $candidatesByKey, array &$queryKinds): Collection
    {
        $normalizedNames = $this->candidateValues($candidatesByKey, 'normalized_canonical_name');

        if ($normalizedNames === []) {
            return collect();
        }

        $versionTable = (new FoodReferenceVersion)->getTable();
        $referenceTable = (new FoodReference)->getTable();
        $queryKinds[] = 'exact_current_canonical_name_matches';

        return FoodReferenceVersion::query()
            ->join($referenceTable, "{$referenceTable}.id", '=', "{$versionTable}.food_reference_id")
            ->select([
                "{$versionTable}.id as version_internal_id",
                "{$versionTable}.public_id as reference_version_public_id",
                "{$versionTable}.normalized_canonical_name",
                "{$versionTable}.version_number",
                "{$versionTable}.locale",
                "{$versionTable}.classification",
                "{$versionTable}.preparation_key",
                "{$referenceTable}.public_id as reference_public_id",
                "{$referenceTable}.stable_key",
                "{$referenceTable}.visibility",
                "{$referenceTable}.owner_user_id",
                "{$referenceTable}.is_generic",
                "{$referenceTable}.archived_at",
            ])
            ->whereIn("{$versionTable}.normalized_canonical_name", $normalizedNames)
            ->whereNotExists(function (Builder $query) use ($versionTable): void {
                $query->selectRaw('1')
                    ->from("{$versionTable} as newer_versions")
                    ->whereColumn('newer_versions.food_reference_id', "{$versionTable}.food_reference_id")
                    ->whereColumn('newer_versions.version_number', '>', "{$versionTable}.version_number");
            })
            ->orderBy("{$referenceTable}.public_id")
            ->orderBy("{$versionTable}.public_id")
            ->get();
    }

    /**
     * @param  array<string, array<string, mixed>>  $candidatesByKey
     * @param  list<string>  $queryKinds
     * @return Collection<int, object>
     */
    private function exactAliasRows(array $candidatesByKey, array &$queryKinds): Collection
    {
        $normalizedAliases = [];

        foreach ($candidatesByKey as $candidate) {
            foreach ($candidate['normalized_aliases'] as $normalizedAlias) {
                if (is_string($normalizedAlias) && trim($normalizedAlias) !== '') {
                    $normalizedAliases[$normalizedAlias] = true;
                }
            }
        }

        if ($normalizedAliases === []) {
            return collect();
        }

        $aliasTable = (new FoodAlias)->getTable();
        $referenceTable = (new FoodReference)->getTable();
        $sourceTable = (new FoodSource)->getTable();
        $queryKinds[] = 'exact_current_alias_matches';

        return FoodAlias::query()
            ->join($referenceTable, "{$referenceTable}.id", '=', "{$aliasTable}.food_reference_id")
            ->leftJoin($sourceTable, "{$sourceTable}.id", '=', "{$aliasTable}.food_source_id")
            ->select([
                "{$aliasTable}.public_id as alias_public_id",
                "{$aliasTable}.lineage_id",
                "{$aliasTable}.normalized_alias",
                "{$aliasTable}.revision_number",
                "{$aliasTable}.locale",
                "{$aliasTable}.alias_kind",
                "{$referenceTable}.public_id as reference_public_id",
                "{$referenceTable}.stable_key",
                "{$referenceTable}.visibility",
                "{$referenceTable}.owner_user_id",
                "{$referenceTable}.is_generic",
                "{$referenceTable}.archived_at",
                "{$sourceTable}.public_id as source_public_id",
                "{$sourceTable}.kind as source_kind",
                "{$sourceTable}.authority_status as source_authority_status",
            ])
            ->whereIn("{$aliasTable}.normalized_alias", array_keys($normalizedAliases))
            ->whereNotExists(function (Builder $query) use ($aliasTable): void {
                $query->selectRaw('1')
                    ->from("{$aliasTable} as newer_aliases")
                    ->whereColumn('newer_aliases.lineage_id', "{$aliasTable}.lineage_id")
                    ->whereColumn('newer_aliases.revision_number', '>', "{$aliasTable}.revision_number");
            })
            ->orderBy("{$referenceTable}.public_id")
            ->orderBy("{$aliasTable}.normalized_alias")
            ->orderBy("{$aliasTable}.public_id")
            ->get();
    }

    /**
     * @param  Collection<int, object>  $canonicalRows
     * @param  list<string>  $queryKinds
     * @return array<int, list<array<string, mixed>>>
     */
    private function sourceRowsForVersions(Collection $canonicalRows, array &$queryKinds): array
    {
        $versionInternalIds = $canonicalRows->pluck('version_internal_id')->unique()->values()->all();

        if ($versionInternalIds === []) {
            return [];
        }

        $linkTable = (new FoodReferenceVersionSource)->getTable();
        $sourceTable = (new FoodSource)->getTable();
        $queryKinds[] = 'canonical_match_source_associations';
        $rows = FoodReferenceVersionSource::query()
            ->join($sourceTable, "{$sourceTable}.id", '=', "{$linkTable}.food_source_id")
            ->select([
                "{$linkTable}.food_reference_version_id",
                "{$linkTable}.role",
                "{$linkTable}.source_record_key",
                "{$sourceTable}.public_id as source_public_id",
                "{$sourceTable}.kind as source_kind",
                "{$sourceTable}.authority_status as source_authority_status",
            ])
            ->whereIn("{$linkTable}.food_reference_version_id", $versionInternalIds)
            ->orderBy("{$linkTable}.food_reference_version_id")
            ->orderBy("{$linkTable}.role")
            ->orderBy("{$sourceTable}.public_id")
            ->get();
        $groupedRows = [];

        foreach ($rows as $row) {
            $groupedRows[$row->food_reference_version_id][] = [
                'authority_status' => $this->scalarValue($row->source_authority_status),
                'kind' => $this->scalarValue($row->source_kind),
                'public_id' => $row->source_public_id,
                'role' => $this->scalarValue($row->role),
                'source_record_key' => $row->source_record_key,
            ];
        }

        return $groupedRows;
    }

    /**
     * @param  array<string, array<string, mixed>>  $candidatesByKey
     * @param  Collection<int, object>  $referenceRows
     * @param  array<string, list<array<string, mixed>>>  $matchesByCandidate
     * @param  array<string, list<array<string, mixed>>>  $conflictsByCandidate
     * @param  array<string, int>  $evidenceCounts
     * @param  array<string, int>  $conflictCounts
     */
    private function mapReferenceEvidence(
        array $candidatesByKey,
        Collection $referenceRows,
        array &$matchesByCandidate,
        array &$conflictsByCandidate,
        array &$evidenceCounts,
        array &$conflictCounts,
    ): void {
        foreach ($candidatesByKey as $sourceRecordKey => $candidate) {
            $matchedRequestedPublicId = false;

            foreach ($referenceRows as $reference) {
                $matchedStableKey = is_string($candidate['stable_key'] ?? null)
                    && $candidate['stable_key'] === $reference->stable_key;
                $matchedPublicId = is_string($candidate['existing_reference_public_id'] ?? null)
                    && $candidate['existing_reference_public_id'] === $reference->public_id;
                $matchedRequestedPublicId = $matchedRequestedPublicId || $matchedPublicId;

                foreach ([
                    'stable_key' => $matchedStableKey,
                    'public_uuid' => $matchedPublicId,
                ] as $evidenceType => $matched) {
                    if (! $matched) {
                        continue;
                    }

                    $matchesByCandidate[$sourceRecordKey][] = [
                        'evidence_type' => $evidenceType,
                        'existing_reference' => $this->referenceEvidence($reference),
                        'matched_value' => $evidenceType === 'stable_key'
                            ? $reference->stable_key
                            : $reference->public_id,
                    ];
                    $this->increment($evidenceCounts, $evidenceType);
                }

                if (
                    $matchedStableKey
                    && ($candidate['reference_target'] ?? null) === CatalogImportReviewReferenceTarget::NewReference->value
                ) {
                    $conflictsByCandidate[$sourceRecordKey][] = [
                        'conflict_type' => 'stable_key_conflict',
                        'existing_reference_public_id' => $reference->public_id,
                        'immutable_field_conflict' => true,
                        'stable_key' => $reference->stable_key,
                    ];
                    $this->increment($conflictCounts, 'stable_key');
                    $this->increment($conflictCounts, 'immutable_field');
                }

                if (
                    $matchedStableKey
                    && is_string($candidate['existing_reference_public_id'] ?? null)
                    && $candidate['existing_reference_public_id'] !== $reference->public_id
                ) {
                    $conflictsByCandidate[$sourceRecordKey][] = [
                        'conflict_type' => 'stable_key_public_uuid_mismatch',
                        'existing_reference_public_id' => $reference->public_id,
                        'immutable_field_conflict' => true,
                        'stable_key' => $reference->stable_key,
                    ];
                    $this->increment($conflictCounts, 'stable_key');
                    $this->increment($conflictCounts, 'immutable_field');
                }

                if ($matchedPublicId) {
                    $this->appendImmutableConflicts(
                        sourceRecordKey: $sourceRecordKey,
                        candidate: $candidate,
                        reference: $reference,
                        conflictsByCandidate: $conflictsByCandidate,
                        conflictCounts: $conflictCounts,
                    );
                }
            }

            if (
                ($candidate['reference_target'] ?? null) === CatalogImportReviewReferenceTarget::ExistingReference->value
                && is_string($candidate['existing_reference_public_id'] ?? null)
                && ! $matchedRequestedPublicId
            ) {
                $conflictsByCandidate[$sourceRecordKey][] = [
                    'conflict_type' => 'public_uuid_not_found',
                    'immutable_field_conflict' => false,
                    'requested_public_id' => $candidate['existing_reference_public_id'],
                ];
                $this->increment($conflictCounts, 'public_uuid');
            }
        }
    }

    /**
     * @param  array<string, array<string, mixed>>  $candidatesByKey
     * @param  Collection<int, object>  $canonicalRows
     * @param  array<int, list<array<string, mixed>>>  $sourceRows
     * @param  array<string, list<array<string, mixed>>>  $matchesByCandidate
     * @param  array<string, int>  $evidenceCounts
     */
    private function mapCanonicalEvidence(
        array $candidatesByKey,
        Collection $canonicalRows,
        array $sourceRows,
        array &$matchesByCandidate,
        array &$evidenceCounts,
    ): void {
        foreach ($candidatesByKey as $sourceRecordKey => $candidate) {
            foreach ($canonicalRows as $version) {
                if ($candidate['normalized_canonical_name'] !== $version->normalized_canonical_name) {
                    continue;
                }

                $matchesByCandidate[$sourceRecordKey][] = [
                    'evidence_type' => 'normalized_canonical_name',
                    'existing_reference' => $this->referenceEvidence($version),
                    'matched_value' => $version->normalized_canonical_name,
                    'reference_version' => [
                        'classification' => $this->scalarValue($version->classification),
                        'locale' => $version->locale,
                        'preparation_key' => $version->preparation_key,
                        'public_id' => $version->reference_version_public_id,
                        'version_number' => $version->version_number,
                    ],
                    'source_associations' => $sourceRows[$version->version_internal_id] ?? [],
                ];
                $this->increment($evidenceCounts, 'normalized_canonical_name');
            }
        }
    }

    /**
     * @param  array<string, array<string, mixed>>  $candidatesByKey
     * @param  Collection<int, object>  $aliasRows
     * @param  array<string, list<array<string, mixed>>>  $matchesByCandidate
     * @param  array<string, int>  $evidenceCounts
     */
    private function mapAliasEvidence(
        array $candidatesByKey,
        Collection $aliasRows,
        array &$matchesByCandidate,
        array &$evidenceCounts,
    ): void {
        foreach ($candidatesByKey as $sourceRecordKey => $candidate) {
            foreach ($aliasRows as $alias) {
                if (! in_array($alias->normalized_alias, $candidate['normalized_aliases'], true)) {
                    continue;
                }

                $sourceAssociation = $alias->source_public_id === null
                    ? []
                    : [[
                        'authority_status' => $this->scalarValue($alias->source_authority_status),
                        'kind' => $this->scalarValue($alias->source_kind),
                        'public_id' => $alias->source_public_id,
                    ]];
                $matchesByCandidate[$sourceRecordKey][] = [
                    'alias' => [
                        'alias_kind' => $this->scalarValue($alias->alias_kind),
                        'locale' => $alias->locale,
                        'public_id' => $alias->alias_public_id,
                        'revision_number' => $alias->revision_number,
                    ],
                    'evidence_type' => 'normalized_alias',
                    'existing_reference' => $this->referenceEvidence($alias),
                    'matched_value' => $alias->normalized_alias,
                    'source_associations' => $sourceAssociation,
                ];
                $this->increment($evidenceCounts, 'normalized_alias');
            }
        }
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @param  array<string, list<array<string, mixed>>>  $conflictsByCandidate
     * @param  array<string, int>  $conflictCounts
     */
    private function appendImmutableConflicts(
        string $sourceRecordKey,
        array $candidate,
        object $reference,
        array &$conflictsByCandidate,
        array &$conflictCounts,
    ): void {
        $proposedFields = [
            'stable_key' => [
                'explicit' => is_string($candidate['stable_key'] ?? null),
                'value' => $candidate['stable_key'] ?? null,
            ],
            'visibility' => [
                'explicit' => is_string($candidate['reference_visibility'] ?? null),
                'value' => $candidate['reference_visibility'] ?? null,
            ],
            'owner_user_id' => [
                'explicit' => in_array(
                    $candidate['owner_user_id_decision'] ?? null,
                    ['explicit_null', 'resolved_value'],
                    true,
                ),
                'value' => $candidate['owner_user_id'] ?? null,
            ],
            'is_generic' => [
                'explicit' => is_bool($candidate['is_generic'] ?? null),
                'value' => $candidate['is_generic'] ?? null,
            ],
        ];

        foreach ($proposedFields as $field => $proposed) {
            $persistedValue = $reference->{$field};

            if ($persistedValue instanceof BackedEnum) {
                $persistedValue = $persistedValue->value;
            }

            if (! $proposed['explicit'] || $proposed['value'] === $persistedValue) {
                continue;
            }

            $conflictsByCandidate[$sourceRecordKey][] = [
                'conflict_type' => 'immutable_field_conflict',
                'existing_reference_public_id' => $reference->public_id,
                'field' => $field,
                'immutable_field_conflict' => true,
            ];
            $this->increment($conflictCounts, 'immutable_field');
        }
    }

    /** @return array<string, mixed> */
    private function referenceEvidence(object $reference): array
    {
        return [
            'archived' => $reference->archived_at !== null,
            'is_generic' => (bool) $reference->is_generic,
            'public_id' => $reference->reference_public_id ?? $reference->public_id,
            'stable_key' => $reference->stable_key,
            'visibility' => $this->scalarValue($reference->visibility),
        ];
    }

    private function scalarValue(mixed $value): mixed
    {
        return $value instanceof BackedEnum ? $value->value : $value;
    }

    /**
     * @param  array<string, array<string, mixed>>  $candidatesByKey
     * @return list<string>
     */
    private function candidateValues(array $candidatesByKey, string $field): array
    {
        $values = [];

        foreach ($candidatesByKey as $candidate) {
            $value = $candidate[$field] ?? null;

            if (is_string($value) && trim($value) !== '') {
                $values[$value] = true;
            }
        }

        $values = array_keys($values);
        sort($values, SORT_STRING);

        return $values;
    }

    /** @param array<string, int> $counts */
    private function increment(array &$counts, string $kind): void
    {
        $counts[$kind]++;
        $counts['total']++;
    }

    /** @param array<string, mixed> $left
     * @param  array<string, mixed>  $right
     */
    private function compareEvidence(array $left, array $right): int
    {
        return strcmp(
            $left['existing_reference']['public_id'],
            $right['existing_reference']['public_id'],
        )
            ?: strcmp($left['evidence_type'], $right['evidence_type'])
            ?: strcmp((string) $left['matched_value'], (string) $right['matched_value']);
    }
}
