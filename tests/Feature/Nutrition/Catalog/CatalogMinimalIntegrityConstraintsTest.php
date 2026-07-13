<?php

use App\Models\User;
use App\Nutrition\Application\Catalog\Lifecycle\CatalogLifecycleProjectionStateResolver;
use App\Nutrition\Application\Catalog\Lifecycle\CatalogLifecycleReplayGuard;
use App\Nutrition\Application\Catalog\Lifecycle\CatalogLifecycleRootEventFactory;
use App\Nutrition\Application\Catalog\Lifecycle\FoodReferenceVersionLifecycleService;
use App\Nutrition\Application\Catalog\Lifecycle\ValueObjects\CatalogLifecycleExecutionContext;
use App\Nutrition\Domain\Catalog\Lifecycle\Enums\CatalogLifecycleOperation;
use App\Nutrition\Domain\Catalog\Lifecycle\Enums\CatalogLifecycleOutcome;
use App\Nutrition\Domain\Catalog\Lifecycle\Enums\CatalogLifecycleSubjectType;
use App\Nutrition\Domain\Catalog\Lifecycle\Policies\FoodReferenceVersionLifecyclePolicy;
use App\Nutrition\Domain\Catalog\Lifecycle\ValueObjects\CatalogLifecycleCommand;
use App\Nutrition\Infrastructure\Catalog\Eloquent\EloquentCatalogLifecycleEventStore;
use App\Nutrition\Infrastructure\Catalog\Eloquent\FoodReferenceVersion;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

function catalogIntegrityConstraintSourceM2345(array $values = []): int
{
    return DB::table('food_sources')->insertGetId(array_replace([
        'public_id' => (string) Str::uuid(),
        'visibility' => 'global',
        'kind' => 'curated_dataset',
        'authority_status' => 'eligible',
        'title' => 'Integrity constraint source',
        'metadata' => json_encode(['origin' => 'catalog'], JSON_THROW_ON_ERROR),
    ], $values));
}

function catalogIntegrityConstraintReferenceM2345(array $values = []): int
{
    return DB::table('food_references')->insertGetId(array_replace([
        'public_id' => (string) Str::uuid(),
        'stable_key' => 'integrity-constraint-'.Str::uuid(),
        'visibility' => 'global',
        'is_generic' => false,
    ], $values));
}

function catalogIntegrityConstraintVersionM2345(int $referenceId, array $values = []): int
{
    return DB::table('food_reference_versions')->insertGetId(array_replace([
        'public_id' => (string) Str::uuid(),
        'food_reference_id' => $referenceId,
        'version_number' => 1,
        'canonical_name' => 'Integrity constraint food',
        'normalized_canonical_name' => 'integrity constraint food',
        'locale' => 'pt-BR',
        'classification' => 'food',
        'preparation_key' => 'raw',
        'energy_basis_grams' => 100,
        'energy_kcal' => 120,
        'nutrient_values' => json_encode(['protein' => 20], JSON_THROW_ON_ERROR),
        'provenance' => json_encode(['origin' => 'catalog'], JSON_THROW_ON_ERROR),
        'review_status' => 'draft',
    ], $values));
}

function catalogIntegrityConstraintAliasM2345(int $referenceId, array $values = []): int
{
    return DB::table('food_aliases')->insertGetId(array_replace([
        'public_id' => (string) Str::uuid(),
        'lineage_id' => (string) Str::uuid(),
        'food_reference_id' => $referenceId,
        'revision_number' => 1,
        'display_alias' => 'Integrity constraint alias',
        'normalized_alias' => 'integrity constraint alias',
        'locale' => 'pt-BR',
        'alias_kind' => 'common',
        'provenance' => json_encode(['origin' => 'catalog'], JSON_THROW_ON_ERROR),
        'review_status' => 'draft',
    ], $values));
}

function catalogIntegrityConstraintPortionM2345(int $referenceId, array $values = []): int
{
    return DB::table('food_portions')->insertGetId(array_replace([
        'public_id' => (string) Str::uuid(),
        'lineage_id' => (string) Str::uuid(),
        'food_reference_id' => $referenceId,
        'revision_number' => 1,
        'portion_key' => 'unit',
        'display_label' => 'Unit',
        'normalized_label' => 'unit',
        'locale' => 'pt-BR',
        'portion_type' => 'unit',
        'unit_code' => 'unit',
        'unit_quantity' => 1,
        'gram_weight' => 100,
        'preparation_key' => 'any',
        'provenance' => json_encode(['origin' => 'catalog'], JSON_THROW_ON_ERROR),
        'review_status' => 'draft',
    ], $values));
}

function expectCatalogIntegrityConstraintFailureM2345(Closure $operation, string $sqlState = 'N3402'): void
{
    DB::beginTransaction();

    try {
        $operation();
        DB::rollBack();
    } catch (QueryException $exception) {
        DB::rollBack();
        expect($exception->errorInfo[0] ?? null)->toBe($sqlState);

        return;
    }

    throw new RuntimeException('PostgreSQL accepted a prohibited catalog mutation.');
}

it('installs the explicit PostgreSQL functions triggers and AliasKind check', function () {
    expect(DB::connection()->getDriverName())->toBe('pgsql');

    $functions = DB::table('pg_proc')->pluck('proname')->all();
    $triggers = DB::table('pg_trigger')->where('tgisinternal', false)->pluck('tgname')->all();
    $constraints = DB::table('pg_constraint')->pluck('conname')->all();

    expect($functions)->toContain(
        'fn_catalog_block_physical_delete',
        'fn_food_sources_guard_identity_and_used_content',
        'fn_food_references_guard_identity',
        'fn_food_reference_versions_guard_identity_and_frozen_content',
        'fn_food_aliases_guard_identity_and_frozen_content',
        'fn_food_portions_guard_identity_and_frozen_content',
        'fn_food_reference_version_sources_guard_frozen_parent',
    )->and($triggers)->toContain(
        'trg_food_sources_guard_update',
        'trg_food_references_guard_update',
        'trg_food_reference_versions_guard_update',
        'trg_food_aliases_guard_update',
        'trg_food_portions_guard_update',
        'trg_food_reference_version_sources_guard_mutation',
        'trg_food_sources_block_delete',
        'trg_food_references_block_delete',
        'trg_food_reference_versions_block_delete',
        'trg_food_aliases_block_delete',
        'trg_food_portions_block_delete',
    )->and($constraints)->toContain('food_aliases_alias_kind_check');
});

it('blocks representative immutable identity changes on all six catalog tables', function () {
    $firstReferenceId = catalogIntegrityConstraintReferenceM2345();
    $secondReferenceId = catalogIntegrityConstraintReferenceM2345();
    $sourceId = catalogIntegrityConstraintSourceM2345();
    $otherSourceId = catalogIntegrityConstraintSourceM2345();
    $versionId = catalogIntegrityConstraintVersionM2345($firstReferenceId);
    $aliasId = catalogIntegrityConstraintAliasM2345($firstReferenceId);
    $portionId = catalogIntegrityConstraintPortionM2345($firstReferenceId);
    $linkId = DB::table('food_reference_version_sources')->insertGetId([
        'food_reference_version_id' => $versionId,
        'food_source_id' => $sourceId,
        'role' => 'primary',
    ]);

    expectCatalogIntegrityConstraintFailureM2345(fn () => DB::table('food_sources')->where('id', $sourceId)->update(['visibility' => 'private']));
    expectCatalogIntegrityConstraintFailureM2345(fn () => DB::table('food_references')->where('id', $firstReferenceId)->update(['stable_key' => 'changed']));
    expectCatalogIntegrityConstraintFailureM2345(fn () => DB::table('food_reference_versions')->where('id', $versionId)->update(['food_reference_id' => $secondReferenceId]));
    expectCatalogIntegrityConstraintFailureM2345(fn () => DB::table('food_aliases')->where('id', $aliasId)->update(['revision_number' => 2]));
    expectCatalogIntegrityConstraintFailureM2345(fn () => DB::table('food_portions')->where('id', $portionId)->update(['lineage_id' => (string) Str::uuid()]));
    expectCatalogIntegrityConstraintFailureM2345(fn () => DB::table('food_reference_version_sources')->where('id', $linkId)->update(['food_source_id' => $otherSourceId]));
});

it('keeps draft and pending review content editable at the database boundary', function () {
    $referenceId = catalogIntegrityConstraintReferenceM2345();
    $draftVersionId = catalogIntegrityConstraintVersionM2345($referenceId);
    $pendingAliasId = catalogIntegrityConstraintAliasM2345($referenceId, ['review_status' => 'pending_review']);
    $pendingPortionId = catalogIntegrityConstraintPortionM2345($referenceId, ['review_status' => 'pending_review']);

    expect(DB::table('food_reference_versions')->where('id', $draftVersionId)->update(['canonical_name' => 'Edited draft']))->toBe(1)
        ->and(DB::table('food_aliases')->where('id', $pendingAliasId)->update(['display_alias' => 'Edited pending alias']))->toBe(1)
        ->and(DB::table('food_portions')->where('id', $pendingPortionId)->update(['display_label' => 'Edited pending portion']))->toBe(1);
});

it('freezes representative approved rejected published withdrawn and archived content', function () {
    $referenceId = catalogIntegrityConstraintReferenceM2345();
    $approvedVersionId = catalogIntegrityConstraintVersionM2345($referenceId, ['review_status' => 'approved']);
    $rejectedAliasId = catalogIntegrityConstraintAliasM2345($referenceId, ['review_status' => 'rejected']);
    $publishedPortionId = catalogIntegrityConstraintPortionM2345($referenceId, ['review_status' => 'approved', 'published_at' => now()]);
    $withdrawnVersionId = catalogIntegrityConstraintVersionM2345($referenceId, ['version_number' => 2, 'withdrawn_at' => now()]);
    $archivedAliasId = catalogIntegrityConstraintAliasM2345($referenceId, ['revision_number' => 2, 'archived_at' => now()]);

    expectCatalogIntegrityConstraintFailureM2345(fn () => DB::table('food_reference_versions')->where('id', $approvedVersionId)->update(['energy_kcal' => 121]));
    expectCatalogIntegrityConstraintFailureM2345(fn () => DB::table('food_aliases')->where('id', $rejectedAliasId)->update(['display_alias' => 'Changed']));
    expectCatalogIntegrityConstraintFailureM2345(fn () => DB::table('food_portions')->where('id', $publishedPortionId)->update(['gram_weight' => 101]));
    expectCatalogIntegrityConstraintFailureM2345(fn () => DB::table('food_reference_versions')->where('id', $withdrawnVersionId)->update(['canonical_name' => 'Changed']));
    expectCatalogIntegrityConstraintFailureM2345(fn () => DB::table('food_aliases')->where('id', $archivedAliasId)->update(['normalized_alias' => 'changed']));
});

it('allows legitimate lifecycle projection changes without validating transitions', function () {
    $referenceId = catalogIntegrityConstraintReferenceM2345();
    $versionId = catalogIntegrityConstraintVersionM2345($referenceId, ['review_status' => 'approved']);
    $aliasId = catalogIntegrityConstraintAliasM2345($referenceId, ['review_status' => 'approved', 'published_at' => now()]);
    $portionId = catalogIntegrityConstraintPortionM2345($referenceId, [
        'review_status' => 'approved',
        'published_at' => now(),
        'activated_at' => now(),
    ]);

    expect(DB::table('food_reference_versions')->where('id', $versionId)->update(['published_at' => now()]))->toBe(1)
        ->and(DB::table('food_aliases')->where('id', $aliasId)->update(['activated_at' => now()]))->toBe(1)
        ->and(DB::table('food_portions')->where('id', $portionId)->update(['deactivated_at' => now()]))->toBe(1)
        ->and(DB::table('food_references')->where('id', $referenceId)->update(['archived_at' => now()]))->toBe(1);
});

it('freezes source descriptive evidence after each real catalog use while allowing governance', function (string $usage) {
    $referenceId = catalogIntegrityConstraintReferenceM2345();
    $versionId = catalogIntegrityConstraintVersionM2345($referenceId);
    $sourceId = catalogIntegrityConstraintSourceM2345();

    expect(DB::table('food_sources')->where('id', $sourceId)->update(['title' => 'Unused source edit']))->toBe(1);

    match ($usage) {
        'version link' => DB::table('food_reference_version_sources')->insert([
            'food_reference_version_id' => $versionId,
            'food_source_id' => $sourceId,
            'role' => 'primary',
        ]),
        'alias' => catalogIntegrityConstraintAliasM2345($referenceId, ['food_source_id' => $sourceId]),
        'portion' => catalogIntegrityConstraintPortionM2345($referenceId, ['food_source_id' => $sourceId]),
    };

    expectCatalogIntegrityConstraintFailureM2345(fn () => DB::table('food_sources')->where('id', $sourceId)->update(['citation' => 'Changed evidence']));

    expect(DB::table('food_sources')->where('id', $sourceId)->update([
        'authority_status' => 'untrusted',
        'archived_at' => now(),
        'archive_reason' => 'Governed archive.',
    ]))->toBe(1);
})->with(['version link', 'alias', 'portion']);

it('allows draft version source link changes and freezes all link mutations after acceptance', function () {
    $referenceId = catalogIntegrityConstraintReferenceM2345();
    $draftVersionId = catalogIntegrityConstraintVersionM2345($referenceId);
    $firstSourceId = catalogIntegrityConstraintSourceM2345();
    $secondSourceId = catalogIntegrityConstraintSourceM2345();
    $draftLinkId = DB::table('food_reference_version_sources')->insertGetId([
        'food_reference_version_id' => $draftVersionId,
        'food_source_id' => $firstSourceId,
        'role' => 'primary',
    ]);

    expect(DB::table('food_reference_version_sources')->where('id', $draftLinkId)->update([
        'role' => 'supporting',
        'source_record_key' => 'draft:edited',
    ]))->toBe(1)
        ->and(DB::table('food_reference_version_sources')->where('id', $draftLinkId)->delete())->toBe(1);

    $pendingVersionId = catalogIntegrityConstraintVersionM2345($referenceId, [
        'version_number' => 2,
        'review_status' => 'pending_review',
    ]);
    $pendingLinkId = DB::table('food_reference_version_sources')->insertGetId([
        'food_reference_version_id' => $pendingVersionId,
        'food_source_id' => $firstSourceId,
        'role' => 'primary',
    ]);

    expect(DB::table('food_reference_version_sources')->where('id', $pendingLinkId)->update([
        'source_record_key' => 'pending:edited',
    ]))->toBe(1)
        ->and(DB::table('food_reference_version_sources')->where('id', $pendingLinkId)->delete())->toBe(1);

    $frozenVersionId = catalogIntegrityConstraintVersionM2345($referenceId, ['version_number' => 3]);
    $frozenLinkId = DB::table('food_reference_version_sources')->insertGetId([
        'food_reference_version_id' => $frozenVersionId,
        'food_source_id' => $firstSourceId,
        'role' => 'primary',
    ]);
    DB::table('food_reference_versions')->where('id', $frozenVersionId)->update(['review_status' => 'approved']);

    expectCatalogIntegrityConstraintFailureM2345(fn () => DB::table('food_reference_version_sources')->insert([
        'food_reference_version_id' => $frozenVersionId,
        'food_source_id' => $secondSourceId,
        'role' => 'supporting',
    ]));
    expectCatalogIntegrityConstraintFailureM2345(fn () => DB::table('food_reference_version_sources')->where('id', $frozenLinkId)->update(['source_record_key' => 'changed']));
    expectCatalogIntegrityConstraintFailureM2345(fn () => DB::table('food_reference_version_sources')->where('id', $frozenLinkId)->delete());
});

it('allows foreign keys to null legitimate catalog actor references', function () {
    $actor = User::factory()->create();
    $referenceId = catalogIntegrityConstraintReferenceM2345(['created_by_user_id' => $actor->id]);
    $sourceId = catalogIntegrityConstraintSourceM2345(['created_by_user_id' => $actor->id]);
    $versionId = catalogIntegrityConstraintVersionM2345($referenceId, ['created_by_user_id' => $actor->id]);
    $aliasId = catalogIntegrityConstraintAliasM2345($referenceId, ['created_by_user_id' => $actor->id]);
    $portionId = catalogIntegrityConstraintPortionM2345($referenceId, ['created_by_user_id' => $actor->id]);
    $linkId = DB::table('food_reference_version_sources')->insertGetId([
        'food_reference_version_id' => $versionId,
        'food_source_id' => $sourceId,
        'role' => 'primary',
        'created_by_user_id' => $actor->id,
    ]);
    DB::table('food_reference_versions')->where('id', $versionId)->update(['review_status' => 'approved']);

    $actor->delete();

    expect(DB::table('food_sources')->find($sourceId)->created_by_user_id)->toBeNull()
        ->and(DB::table('food_references')->find($referenceId)->created_by_user_id)->toBeNull()
        ->and(DB::table('food_reference_versions')->find($versionId)->created_by_user_id)->toBeNull()
        ->and(DB::table('food_aliases')->find($aliasId)->created_by_user_id)->toBeNull()
        ->and(DB::table('food_portions')->find($portionId)->created_by_user_id)->toBeNull()
        ->and(DB::table('food_reference_version_sources')->find($linkId)->created_by_user_id)->toBeNull();
});

it('blocks physical deletion of persistent entities and permits draft link deletion', function () {
    $referenceId = catalogIntegrityConstraintReferenceM2345();
    $sourceId = catalogIntegrityConstraintSourceM2345();
    $versionId = catalogIntegrityConstraintVersionM2345($referenceId);
    $aliasId = catalogIntegrityConstraintAliasM2345($referenceId);
    $portionId = catalogIntegrityConstraintPortionM2345($referenceId);
    $linkId = DB::table('food_reference_version_sources')->insertGetId([
        'food_reference_version_id' => $versionId,
        'food_source_id' => $sourceId,
        'role' => 'primary',
    ]);

    expect(DB::table('food_reference_version_sources')->where('id', $linkId)->delete())->toBe(1);

    foreach ([
        ['food_sources', $sourceId],
        ['food_references', $referenceId],
        ['food_reference_versions', $versionId],
        ['food_aliases', $aliasId],
        ['food_portions', $portionId],
    ] as [$table, $id]) {
        expectCatalogIntegrityConstraintFailureM2345(fn () => DB::table($table)->where('id', $id)->delete());
    }
});

it('accepts the committed AliasKind vocabulary and rejects unknown values', function () {
    $referenceId = catalogIntegrityConstraintReferenceM2345();

    foreach (['common', 'generic', 'regional', 'brand'] as $revision => $aliasKind) {
        catalogIntegrityConstraintAliasM2345($referenceId, [
            'lineage_id' => (string) Str::uuid(),
            'revision_number' => $revision + 1,
            'alias_kind' => $aliasKind,
        ]);
    }

    expectCatalogIntegrityConstraintFailureM2345(
        fn () => catalogIntegrityConstraintAliasM2345($referenceId, ['alias_kind' => 'unknown']),
        '23514',
    );
});

it('allows the committed transactional version service to publish accepted content', function () {
    $actor = User::factory()->create();
    $referenceId = catalogIntegrityConstraintReferenceM2345();
    $versionId = catalogIntegrityConstraintVersionM2345($referenceId, [
        'review_status' => 'approved',
        'reviewed_at' => now(),
        'created_by_user_id' => $actor->id,
    ]);
    $version = FoodReferenceVersion::query()->findOrFail($versionId);
    $store = new EloquentCatalogLifecycleEventStore;
    $service = new FoodReferenceVersionLifecycleService(
        new FoodReferenceVersionLifecyclePolicy,
        $store,
        new CatalogLifecycleReplayGuard($store),
        new CatalogLifecycleRootEventFactory,
        new CatalogLifecycleProjectionStateResolver,
    );
    $command = new CatalogLifecycleCommand(
        CatalogLifecycleSubjectType::ReferenceVersion,
        $version->public_id,
        CatalogLifecycleOperation::Publish,
        (string) $actor->id,
        'Publication regression.',
        (string) Str::uuid7(),
        new DateTimeImmutable('2026-07-12T19:00:00.123456-03:00'),
    );

    $execution = $service->publish(
        $command,
        new CatalogLifecycleExecutionContext($actor->id, "audit:user:{$actor->id}"),
    );

    expect($execution->lifecycleResult->outcome)->toBe(CatalogLifecycleOutcome::Succeeded)
        ->and($version->refresh()->published_at?->toDateTimeImmutable())->toEqual($command->occurredAt);
});
