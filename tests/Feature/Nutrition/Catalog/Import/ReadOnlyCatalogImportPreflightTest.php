<?php

use App\Nutrition\Infrastructure\Catalog\Eloquent\CatalogLifecycleEvent;
use App\Nutrition\Infrastructure\Catalog\Eloquent\FoodAlias;
use App\Nutrition\Infrastructure\Catalog\Eloquent\FoodReference;
use App\Nutrition\Infrastructure\Catalog\Eloquent\FoodReferenceVersion;
use App\Nutrition\Infrastructure\Catalog\Eloquent\FoodReferenceVersionSource;
use App\Nutrition\Infrastructure\Catalog\Eloquent\FoodSource;
use App\Nutrition\Infrastructure\Catalog\Import\ReadOnlyCatalogImportPreflight;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Event;

/** @return array<string, mixed> */
function preflightCandidateM244a(array $overrides = []): array
{
    return array_replace([
        'existing_reference_public_id' => null,
        'is_generic' => null,
        'normalized_aliases' => ['leite condensado'],
        'normalized_canonical_name' => 'leite condensado',
        'owner_user_id' => null,
        'owner_user_id_decision' => 'unresolved',
        'reference_target' => 'unresolved',
        'reference_visibility' => null,
        'source_record_key' => 'leite condensado',
        'stable_key' => null,
    ], $overrides);
}

function exactCatalogFixtureM244a(
    string $referencePublicId = 'bbbbbbbb-bbbb-5bbb-8bbb-bbbbbbbbbbbb',
): FoodReference {
    $reference = FoodReference::factory()->create([
        'public_id' => $referencePublicId,
        'stable_key' => 'dairy-condensed-milk',
        'visibility' => 'global',
        'owner_user_id' => null,
        'is_generic' => false,
    ]);
    $source = FoodSource::factory()->eligible()->create([
        'public_id' => 'cccccccc-cccc-5ccc-8ccc-cccccccccccc',
        'title' => 'Curated dairy source',
    ]);
    $version = FoodReferenceVersion::factory()->for($reference, 'reference')->create([
        'public_id' => 'dddddddd-dddd-5ddd-8ddd-dddddddddddd',
        'canonical_name' => 'Leite condensado',
        'normalized_canonical_name' => 'leite condensado',
        'version_number' => 1,
    ]);
    FoodReferenceVersionSource::factory()
        ->for($version, 'version')
        ->for($source, 'source')
        ->primary()
        ->create(['source_record_key' => 'curated-dairy-1']);
    FoodAlias::factory()
        ->for($reference, 'reference')
        ->for($source, 'source')
        ->create([
            'public_id' => 'eeeeeeee-eeee-5eee-8eee-eeeeeeeeeeee',
            'lineage_id' => 'ffffffff-ffff-5fff-8fff-ffffffffffff',
            'display_alias' => 'Leite condensado',
            'normalized_alias' => 'leite condensado',
            'alias_kind' => 'common',
        ]);

    return $reference;
}

it('reports deterministic exact stable-key canonical-name alias and public-UUID evidence', function () {
    $reference = exactCatalogFixtureM244a();
    $candidate = preflightCandidateM244a([
        'existing_reference_public_id' => $reference->public_id,
        'reference_target' => 'existing_reference',
        'stable_key' => $reference->stable_key,
    ]);
    $originalCandidate = $candidate;
    $result = (new ReadOnlyCatalogImportPreflight)->inspect([$candidate]);
    $matches = $result->matchesFor('leite condensado');

    expect(array_column($matches, 'evidence_type'))->toBe([
        'normalized_alias',
        'normalized_canonical_name',
        'public_uuid',
        'stable_key',
    ])->and($matches[0]['existing_reference']['public_id'])->toBe($reference->public_id)
        ->and($matches[1]['reference_version']['public_id'])->toBe('dddddddd-dddd-5ddd-8ddd-dddddddddddd')
        ->and($matches[1]['source_associations'][0])->toMatchArray([
            'public_id' => 'cccccccc-cccc-5ccc-8ccc-cccccccccccc',
            'role' => 'primary',
        ])
        ->and($matches[0]['existing_reference'])->not->toHaveKey('owner_user_id')
        ->and($matches[0]['alias'])->not->toHaveKey('lineage_id')
        ->and($candidate)->toBe($originalCandidate)
        ->and($candidate['reference_target'])->toBe('existing_reference')
        ->and($result->evidenceCounts['total'])->toBe(4);
});

it('does not perform fuzzy or partial matching', function () {
    exactCatalogFixtureM244a();
    $result = (new ReadOnlyCatalogImportPreflight)->inspect([
        preflightCandidateM244a([
            'normalized_aliases' => ['leite condensad'],
            'normalized_canonical_name' => 'leite condensad',
            'source_record_key' => 'near match',
        ]),
    ]);

    expect($result->matchesFor('near match'))->toBe([])
        ->and($result->evidenceCounts['total'])->toBe(0);
});

it('orders possible matches by public UUID regardless of insertion order', function () {
    $second = exactCatalogFixtureM244a('bbbbbbbb-bbbb-5bbb-8bbb-bbbbbbbbbbbb');
    $first = FoodReference::factory()->create([
        'public_id' => 'aaaaaaaa-aaaa-5aaa-8aaa-aaaaaaaaaaaa',
        'stable_key' => 'dairy-condensed-milk-alternative',
    ]);
    FoodAlias::factory()->for($first, 'reference')->create([
        'public_id' => '11111111-1111-5111-8111-111111111111',
        'lineage_id' => '22222222-2222-5222-8222-222222222222',
        'display_alias' => 'Leite condensado',
        'normalized_alias' => 'leite condensado',
    ]);
    $result = (new ReadOnlyCatalogImportPreflight)->inspect([
        preflightCandidateM244a(),
    ]);
    $aliasReferenceIds = array_map(
        fn (array $match): string => $match['existing_reference']['public_id'],
        array_values(array_filter(
            $result->matchesFor('leite condensado'),
            fn (array $match): bool => $match['evidence_type'] === 'normalized_alias',
        )),
    );

    expect($aliasReferenceIds)->toBe([$first->public_id, $second->public_id]);
});

it('reports exact stable-key and immutable-field conflicts without changing catalog state', function () {
    $reference = exactCatalogFixtureM244a();
    $newReferenceResult = (new ReadOnlyCatalogImportPreflight)->inspect([
        preflightCandidateM244a([
            'reference_target' => 'new_reference',
            'stable_key' => $reference->stable_key,
        ]),
    ]);
    $existingReferenceResult = (new ReadOnlyCatalogImportPreflight)->inspect([
        preflightCandidateM244a([
            'existing_reference_public_id' => $reference->public_id,
            'is_generic' => true,
            'owner_user_id_decision' => 'explicit_null',
            'reference_target' => 'existing_reference',
            'reference_visibility' => 'global',
            'stable_key' => $reference->stable_key,
        ]),
    ]);

    expect(array_column(
        $newReferenceResult->conflictsFor('leite condensado'),
        'conflict_type',
    ))->toContain('stable_key_conflict')
        ->and(array_column(
            $existingReferenceResult->conflictsFor('leite condensado'),
            'conflict_type',
        ))->toContain('immutable_field_conflict')
        ->and($existingReferenceResult->conflictCounts['immutable_field'])->toBeGreaterThan(0);
});

it('executes only read queries and no DDL lifecycle supersession or resolver behavior', function () {
    exactCatalogFixtureM244a();
    $queries = [];
    Event::listen(QueryExecuted::class, function (QueryExecuted $query) use (&$queries): void {
        $queries[] = $query->sql;
    });
    $countsBefore = [
        FoodAlias::query()->count(),
        FoodReference::query()->count(),
        FoodReferenceVersion::query()->count(),
        FoodReferenceVersionSource::query()->count(),
        FoodSource::query()->count(),
        CatalogLifecycleEvent::query()->count(),
    ];
    $queries = [];
    $result = (new ReadOnlyCatalogImportPreflight)->inspect([
        preflightCandidateM244a(),
    ]);
    $countsAfter = [
        FoodAlias::query()->count(),
        FoodReference::query()->count(),
        FoodReferenceVersion::query()->count(),
        FoodReferenceVersionSource::query()->count(),
        FoodSource::query()->count(),
        CatalogLifecycleEvent::query()->count(),
    ];
    $serviceQueries = array_slice($queries, 0, $result->queryCount);

    expect($result->queryCount)->toBe(count($serviceQueries))
        ->and($countsAfter)->toBe($countsBefore);

    foreach ($serviceQueries as $query) {
        expect($query)->toMatch('/^\s*select\b/i')
            ->and($query)->not->toMatch(
                '/\b(insert|update|delete|upsert|merge|truncate|alter|create|drop|lock)\b/i',
            );
    }
});
