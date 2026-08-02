<?php

namespace App\Nutrition\Infrastructure\Catalog\Import;

use App\Nutrition\Application\Catalog\Import\CanonicalCatalogImportJson;
use App\Nutrition\Application\Catalog\Import\CatalogImportDeterministicIdentity;
use App\Nutrition\Application\Catalog\Import\ValueObjects\CatalogImportApplyPlanSnapshot;
use App\Nutrition\Application\Catalog\Import\ValueObjects\CatalogImportPreflightResult;
use App\Nutrition\Infrastructure\Catalog\Eloquent\FoodAlias;
use App\Nutrition\Infrastructure\Catalog\Eloquent\FoodReference;
use App\Nutrition\Infrastructure\Catalog\Eloquent\FoodReferenceVersion;
use App\Nutrition\Infrastructure\Catalog\Eloquent\FoodReferenceVersionSource;
use App\Nutrition\Infrastructure\Catalog\Eloquent\FoodSource;
use BackedEnum;

final class ReadOnlyCatalogImportApplyPlanPreflight
{
    /**
     * @param  list<array<string, mixed>>  $selectedEntries
     */
    public function inspect(
        array $selectedEntries,
        CatalogImportPreflightResult $committedPreflight,
    ): CatalogImportApplyPlanSnapshot {
        $queryKinds = [];
        $source = $this->source($queryKinds);
        [$referencesByPublicId, $referencesByStableKey, $referenceInternalIds] = $this->references(
            $selectedEntries,
            $queryKinds,
        );
        [$versionsByReferencePublicId, $versionInternalIds] = $this->versions($referenceInternalIds, $queryKinds);
        $aliasesByReferencePublicId = $this->aliases(
            $selectedEntries,
            $referenceInternalIds,
            $queryKinds,
        );
        $sourceLinksByVersionPublicId = $this->sourceLinks($versionInternalIds, $queryKinds);
        $queryKinds = [...$committedPreflight->queryKinds, ...$queryKinds];
        $snapshotPayload = [
            'aliases_by_reference_public_id' => $aliasesByReferencePublicId,
            'catalog_counts' => $committedPreflight->catalogCounts,
            'committed_exact_conflicts' => $committedPreflight->conflictsByCandidate,
            'committed_exact_matches' => $committedPreflight->matchesByCandidate,
            'references_by_public_id' => $referencesByPublicId,
            'source' => $source,
            'source_links_by_version_public_id' => $sourceLinksByVersionPublicId,
            'versions_by_reference_public_id' => $versionsByReferencePublicId,
        ];
        $fingerprint = hash('sha256', CanonicalCatalogImportJson::serializeSemanticGraph($snapshotPayload));

        return new CatalogImportApplyPlanSnapshot(
            catalogCounts: $committedPreflight->catalogCounts,
            source: $source,
            referencesByPublicId: $referencesByPublicId,
            referencesByStableKey: $referencesByStableKey,
            versionsByReferencePublicId: $versionsByReferencePublicId,
            aliasesByReferencePublicId: $aliasesByReferencePublicId,
            sourceLinksByVersionPublicId: $sourceLinksByVersionPublicId,
            fingerprint: $fingerprint,
            queryCount: count($queryKinds),
            queryKinds: $queryKinds,
        );
    }

    /** @param list<string> $queryKinds @return array<string, mixed>|null */
    private function source(array &$queryKinds): ?array
    {
        $queryKinds[] = 'apply_plan_source_identity';
        $row = FoodSource::query()
            ->select([
                'public_id', 'visibility', 'owner_user_id', 'kind', 'authority_status', 'title',
                'publisher', 'edition', 'source_uri', 'citation', 'license', 'checksum_algorithm',
                'checksum', 'retrieved_at', 'metadata', 'archived_at',
            ])
            ->where('public_id', CatalogImportDeterministicIdentity::sourcePublicId())
            ->first();

        if ($row === null) {
            return null;
        }

        return [
            'archived' => $row->archived_at !== null,
            'authority_status' => $this->scalar($row->authority_status),
            'checksum' => $row->checksum,
            'checksum_algorithm' => $row->checksum_algorithm,
            'citation' => $row->citation,
            'edition' => $row->edition,
            'kind' => $this->scalar($row->kind),
            'license' => $row->license,
            'metadata' => $row->metadata,
            'owner_user_id' => $row->owner_user_id,
            'public_id' => $row->public_id,
            'publisher' => $row->publisher,
            'retrieved_at' => $row->retrieved_at?->format('Y-m-d\TH:i:s.u\Z'),
            'source_uri' => $row->source_uri,
            'title' => $row->title,
            'visibility' => $this->scalar($row->visibility),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $selectedEntries
     * @param  list<string>  $queryKinds
     * @return array{array<string, array<string, mixed>>, array<string, array<string, mixed>>, array<int, string>}
     */
    private function references(array $selectedEntries, array &$queryKinds): array
    {
        $publicIds = [];
        $stableKeys = [];

        foreach ($selectedEntries as $entry) {
            $publicId = $entry['reference_target'] === 'new_reference'
                ? $entry['planned_reference_uuid']['public_id']
                : $entry['existing_reference_public_id'];
            $publicIds[$publicId] = true;
            $stableKeys[$entry['stable_key']] = true;
        }

        $queryKinds[] = 'apply_plan_relevant_references';
        $rows = FoodReference::query()
            ->select(['id', 'public_id', 'stable_key', 'visibility', 'owner_user_id', 'is_generic', 'archived_at'])
            ->where(function ($query) use ($publicIds, $stableKeys): void {
                $query->whereIn('public_id', array_keys($publicIds))
                    ->orWhereIn('stable_key', array_keys($stableKeys));
            })
            ->orderBy('public_id')
            ->get();
        $byPublicId = [];
        $byStableKey = [];
        $internalIds = [];

        foreach ($rows as $row) {
            $semantic = [
                'archived' => $row->archived_at !== null,
                'is_generic' => (bool) $row->is_generic,
                'owner_user_id' => $row->owner_user_id,
                'public_id' => $row->public_id,
                'stable_key' => $row->stable_key,
                'visibility' => $this->scalar($row->visibility),
            ];
            $byPublicId[$row->public_id] = $semantic;
            $byStableKey[$row->stable_key] = $semantic;
            $internalIds[(int) $row->id] = $row->public_id;
        }

        ksort($byPublicId);
        ksort($byStableKey);

        return [$byPublicId, $byStableKey, $internalIds];
    }

    /**
     * @param  list<array<string, mixed>>  $selectedEntries
     * @param  array<int, string>  $referenceInternalIds
     * @param  list<string>  $queryKinds
     * @return array{array<string, list<array<string, mixed>>>, array<int, string>}
     */
    private function versions(array $referenceInternalIds, array &$queryKinds): array
    {
        if ($referenceInternalIds === []) {
            return [[], []];
        }

        $versionTable = (new FoodReferenceVersion)->getTable();
        $queryKinds[] = 'apply_plan_relevant_versions';
        $rows = FoodReferenceVersion::query()
            ->leftJoin("{$versionTable} as predecessor", 'predecessor.id', '=', "{$versionTable}.supersedes_food_reference_version_id")
            ->select([
                "{$versionTable}.id", "{$versionTable}.public_id", "{$versionTable}.food_reference_id",
                "{$versionTable}.version_number", "{$versionTable}.canonical_name",
                "{$versionTable}.normalized_canonical_name", "{$versionTable}.locale",
                "{$versionTable}.classification", "{$versionTable}.preparation_key",
                "{$versionTable}.energy_basis_grams", "{$versionTable}.energy_kcal",
                "{$versionTable}.nutrient_values", "{$versionTable}.provenance",
                "{$versionTable}.review_status", "{$versionTable}.submitted_at",
                "{$versionTable}.reviewed_at", "{$versionTable}.published_at",
                "{$versionTable}.activated_at", "{$versionTable}.deactivated_at",
                "{$versionTable}.withdrawn_at", "{$versionTable}.archived_at",
                'predecessor.public_id as predecessor_public_id',
            ])
            ->whereIn("{$versionTable}.food_reference_id", array_keys($referenceInternalIds))
            ->orderBy("{$versionTable}.food_reference_id")
            ->orderBy("{$versionTable}.version_number")
            ->get();
        $byReference = [];
        $internalIds = [];

        foreach ($rows as $row) {
            $referencePublicId = $referenceInternalIds[(int) $row->food_reference_id];
            $byReference[$referencePublicId][] = [
                'archived' => $row->archived_at !== null,
                'canonical_name' => $row->canonical_name,
                'classification' => $this->scalar($row->classification),
                'energy_basis_grams' => $this->decimal($row->energy_basis_grams),
                'energy_kcal' => $this->decimal($row->energy_kcal),
                'locale' => $row->locale,
                'lifecycle_state' => [
                    'activated' => $row->activated_at !== null,
                    'deactivated' => $row->deactivated_at !== null,
                    'published' => $row->published_at !== null,
                    'reviewed' => $row->reviewed_at !== null,
                    'submitted' => $row->submitted_at !== null,
                    'withdrawn' => $row->withdrawn_at !== null,
                ],
                'normalized_canonical_name' => $row->normalized_canonical_name,
                'nutrient_values' => $row->nutrient_values,
                'predecessor_public_id' => $row->predecessor_public_id,
                'preparation_key' => $row->preparation_key,
                'provenance' => $row->provenance,
                'public_id' => $row->public_id,
                'reference_public_id' => $referencePublicId,
                'review_status' => $this->scalar($row->review_status),
                'version_number' => (int) $row->version_number,
            ];
            $internalIds[(int) $row->id] = $row->public_id;
        }

        ksort($byReference);

        return [$byReference, $internalIds];
    }

    /**
     * @param  array<int, string>  $referenceInternalIds
     * @param  list<string>  $queryKinds
     * @return array<string, list<array<string, mixed>>>
     */
    private function aliases(array $selectedEntries, array $referenceInternalIds, array &$queryKinds): array
    {
        $lineageIds = [];

        foreach ($selectedEntries as $entry) {
            $referencePublicId = $entry['reference_target'] === 'new_reference'
                ? $entry['planned_reference_uuid']['public_id']
                : $entry['existing_reference_public_id'];

            foreach ($entry['alias_decisions'] as $alias) {
                if (($alias['status'] ?? null) === 'include') {
                    $lineageIds[CatalogImportDeterministicIdentity::aliasLineageId(
                        $referencePublicId,
                        $entry['version_locale'],
                        $alias['normalized_alias'],
                    )] = true;
                }
            }
        }

        if ($referenceInternalIds === [] && $lineageIds === []) {
            return [];
        }

        $aliasTable = (new FoodAlias)->getTable();
        $sourceTable = (new FoodSource)->getTable();
        $referenceTable = (new FoodReference)->getTable();
        $queryKinds[] = 'apply_plan_relevant_aliases';
        $rows = FoodAlias::query()
            ->leftJoin("{$aliasTable} as predecessor", 'predecessor.id', '=', "{$aliasTable}.supersedes_food_alias_id")
            ->leftJoin($sourceTable, "{$sourceTable}.id", '=', "{$aliasTable}.food_source_id")
            ->join($referenceTable, "{$referenceTable}.id", '=', "{$aliasTable}.food_reference_id")
            ->select([
                "{$aliasTable}.public_id", "{$aliasTable}.lineage_id", "{$aliasTable}.food_reference_id",
                "{$aliasTable}.revision_number", "{$aliasTable}.display_alias", "{$aliasTable}.normalized_alias",
                "{$aliasTable}.locale", "{$aliasTable}.alias_kind", "{$aliasTable}.source_record_key",
                "{$aliasTable}.provenance", "{$aliasTable}.review_status", "{$aliasTable}.submitted_at",
                "{$aliasTable}.reviewed_at", "{$aliasTable}.published_at", "{$aliasTable}.activated_at",
                "{$aliasTable}.deactivated_at", "{$aliasTable}.withdrawn_at", "{$aliasTable}.archived_at",
                'predecessor.public_id as predecessor_public_id', "{$sourceTable}.public_id as source_public_id",
                "{$referenceTable}.public_id as reference_public_id",
            ])
            ->where(function ($query) use ($aliasTable, $referenceInternalIds, $lineageIds): void {
                if ($referenceInternalIds !== []) {
                    $query->whereIn("{$aliasTable}.food_reference_id", array_keys($referenceInternalIds));
                }

                if ($lineageIds !== []) {
                    $method = $referenceInternalIds === [] ? 'whereIn' : 'orWhereIn';
                    $query->{$method}("{$aliasTable}.lineage_id", array_keys($lineageIds));
                }
            })
            ->orderBy("{$aliasTable}.food_reference_id")
            ->orderBy("{$aliasTable}.lineage_id")
            ->orderBy("{$aliasTable}.revision_number")
            ->get();
        $byReference = [];

        foreach ($rows as $row) {
            $referencePublicId = $row->reference_public_id;
            $byReference[$referencePublicId][] = [
                'alias_kind' => $this->scalar($row->alias_kind),
                'archived' => $row->archived_at !== null,
                'display_alias' => $row->display_alias,
                'lineage_id' => $row->lineage_id,
                'locale' => $row->locale,
                'lifecycle_state' => [
                    'activated' => $row->activated_at !== null,
                    'deactivated' => $row->deactivated_at !== null,
                    'published' => $row->published_at !== null,
                    'reviewed' => $row->reviewed_at !== null,
                    'submitted' => $row->submitted_at !== null,
                    'withdrawn' => $row->withdrawn_at !== null,
                ],
                'normalized_alias' => $row->normalized_alias,
                'predecessor_public_id' => $row->predecessor_public_id,
                'provenance' => $row->provenance,
                'public_id' => $row->public_id,
                'reference_public_id' => $referencePublicId,
                'review_status' => $this->scalar($row->review_status),
                'revision_number' => (int) $row->revision_number,
                'source_public_id' => $row->source_public_id,
                'source_record_key' => $row->source_record_key,
            ];
        }

        ksort($byReference);

        return $byReference;
    }

    /**
     * @param  array<int, string>  $versionInternalIds
     * @param  list<string>  $queryKinds
     * @return array<string, list<array<string, mixed>>>
     */
    private function sourceLinks(array $versionInternalIds, array &$queryKinds): array
    {
        if ($versionInternalIds === []) {
            return [];
        }

        $linkTable = (new FoodReferenceVersionSource)->getTable();
        $sourceTable = (new FoodSource)->getTable();
        $queryKinds[] = 'apply_plan_relevant_source_links';
        $rows = FoodReferenceVersionSource::query()
            ->join($sourceTable, "{$sourceTable}.id", '=', "{$linkTable}.food_source_id")
            ->select([
                "{$linkTable}.food_reference_version_id", "{$linkTable}.role",
                "{$linkTable}.source_record_key", "{$linkTable}.evidence_metadata",
                "{$sourceTable}.public_id as source_public_id",
                "{$sourceTable}.authority_status as source_authority_status",
            ])
            ->whereIn("{$linkTable}.food_reference_version_id", array_keys($versionInternalIds))
            ->orderBy("{$linkTable}.food_reference_version_id")
            ->orderBy("{$linkTable}.role")
            ->orderBy("{$sourceTable}.public_id")
            ->get();
        $byVersion = [];

        foreach ($rows as $row) {
            $versionPublicId = $versionInternalIds[(int) $row->food_reference_version_id];
            $byVersion[$versionPublicId][] = [
                'evidence_metadata' => $row->evidence_metadata,
                'role' => $this->scalar($row->role),
                'source_authority_status' => $this->scalar($row->source_authority_status),
                'source_public_id' => $row->source_public_id,
                'source_record_key' => $row->source_record_key,
                'version_public_id' => $versionPublicId,
            ];
        }

        ksort($byVersion);

        return $byVersion;
    }

    private function scalar(mixed $value): mixed
    {
        return $value instanceof BackedEnum ? $value->value : $value;
    }

    private function decimal(mixed $value): int|float|null
    {
        if ($value === null) {
            return null;
        }

        $number = (float) $value;

        return floor($number) === $number ? (int) $number : $number;
    }
}
