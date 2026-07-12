<?php

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

function catalogSourceM232(array $values = []): int
{
    return DB::table('food_sources')->insertGetId(array_merge([
        'public_id' => (string) Str::uuid(),
        'visibility' => 'global',
        'owner_user_id' => null,
        'kind' => 'curated_dataset',
        'authority_status' => 'eligible',
        'title' => 'Catalog source',
    ], $values));
}

function catalogReferenceM232(array $values = []): int
{
    return DB::table('food_references')->insertGetId(array_merge([
        'public_id' => (string) Str::uuid(),
        'stable_key' => 'reference-'.Str::uuid(),
        'visibility' => 'global',
        'owner_user_id' => null,
    ], $values));
}

function catalogVersionM232(int $referenceId, array $values = []): int
{
    return DB::table('food_reference_versions')->insertGetId(array_merge([
        'public_id' => (string) Str::uuid(),
        'food_reference_id' => $referenceId,
        'version_number' => 1,
        'canonical_name' => 'Catalog food',
        'normalized_canonical_name' => 'catalog food',
        'locale' => 'pt-BR',
        'classification' => 'food',
    ], $values));
}

function catalogAliasM232(int $referenceId, array $values = []): int
{
    return DB::table('food_aliases')->insertGetId(array_merge([
        'public_id' => (string) Str::uuid(),
        'lineage_id' => (string) Str::uuid(),
        'food_reference_id' => $referenceId,
        'revision_number' => 1,
        'display_alias' => 'Catalog alias',
        'normalized_alias' => 'catalog alias',
        'locale' => 'pt-BR',
        'alias_kind' => 'common',
    ], $values));
}

function catalogPortionM232(int $referenceId, array $values = []): int
{
    return DB::table('food_portions')->insertGetId(array_merge([
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
    ], $values));
}

function expectCatalogConstraintFailureM232(Closure $operation): void
{
    DB::beginTransaction();

    try {
        $operation();
        DB::rollBack();
    } catch (QueryException $exception) {
        DB::rollBack();

        expect($exception)->toBeInstanceOf(QueryException::class);

        return;
    }

    throw new RuntimeException('The database accepted a row that should violate a catalog constraint.');
}

it('creates the catalog schema without changing legacy meal tables', function () {
    expect(DB::connection()->getDriverName())->toBe('pgsql');

    $requiredColumns = [
        'food_sources' => ['id', 'public_id', 'visibility', 'owner_user_id', 'kind', 'authority_status', 'title', 'publisher', 'edition', 'source_uri', 'citation', 'license', 'checksum_algorithm', 'checksum', 'retrieved_at', 'metadata', 'archived_at', 'archived_by_user_id', 'archive_reason', 'created_by_user_id', 'created_at', 'updated_at'],
        'food_references' => ['id', 'public_id', 'stable_key', 'visibility', 'owner_user_id', 'is_generic', 'archived_at', 'archived_by_user_id', 'archive_reason', 'created_by_user_id', 'created_at', 'updated_at'],
        'food_reference_versions' => ['id', 'public_id', 'food_reference_id', 'version_number', 'canonical_name', 'normalized_canonical_name', 'locale', 'classification', 'preparation_key', 'energy_basis_grams', 'energy_kcal', 'nutrient_values', 'provenance', 'review_status', 'submitted_at', 'submitted_by_user_id', 'reviewed_at', 'reviewed_by_user_id', 'review_reason', 'published_at', 'published_by_user_id', 'activated_at', 'activated_by_user_id', 'deactivated_at', 'deactivated_by_user_id', 'deactivation_reason', 'withdrawn_at', 'withdrawn_by_user_id', 'withdrawal_reason', 'archived_at', 'archived_by_user_id', 'archive_reason', 'supersedes_food_reference_version_id', 'created_by_user_id', 'created_at', 'updated_at'],
        'food_reference_version_sources' => ['id', 'food_reference_version_id', 'food_source_id', 'role', 'source_record_key', 'evidence_metadata', 'created_by_user_id', 'created_at', 'updated_at'],
        'food_aliases' => ['id', 'public_id', 'lineage_id', 'food_reference_id', 'revision_number', 'supersedes_food_alias_id', 'display_alias', 'normalized_alias', 'locale', 'alias_kind', 'food_source_id', 'source_record_key', 'provenance', 'review_status', 'submitted_at', 'submitted_by_user_id', 'reviewed_at', 'reviewed_by_user_id', 'review_reason', 'published_at', 'published_by_user_id', 'activated_at', 'activated_by_user_id', 'deactivated_at', 'deactivated_by_user_id', 'deactivation_reason', 'withdrawn_at', 'withdrawn_by_user_id', 'withdrawal_reason', 'archived_at', 'archived_by_user_id', 'archive_reason', 'created_by_user_id', 'created_at', 'updated_at'],
        'food_portions' => ['id', 'public_id', 'lineage_id', 'food_reference_id', 'revision_number', 'supersedes_food_portion_id', 'portion_key', 'display_label', 'normalized_label', 'locale', 'portion_type', 'unit_code', 'unit_quantity', 'gram_weight', 'preparation_key', 'size_label', 'food_source_id', 'source_record_key', 'provenance', 'review_status', 'submitted_at', 'submitted_by_user_id', 'reviewed_at', 'reviewed_by_user_id', 'review_reason', 'published_at', 'published_by_user_id', 'activated_at', 'activated_by_user_id', 'deactivated_at', 'deactivated_by_user_id', 'deactivation_reason', 'withdrawn_at', 'withdrawn_by_user_id', 'withdrawal_reason', 'archived_at', 'archived_by_user_id', 'archive_reason', 'created_by_user_id', 'created_at', 'updated_at'],
    ];

    foreach ($requiredColumns as $table => $columns) {
        expect(Schema::hasTable($table))->toBeTrue()
            ->and(Schema::getColumnListing($table))->toContain(...$columns);
    }

    expect(Schema::getColumnListing('meals'))->toBe([
        'id', 'user_id', 'meal_type', 'consumed_at', 'created_at', 'updated_at',
    ])->and(Schema::getColumnListing('meal_items'))->toBe([
        'id', 'meal_id', 'description', 'quantity_grams', 'calories', 'created_at', 'updated_at', 'embedding',
    ]);

    $sourceId = DB::table('food_sources')->insertGetId([
        'public_id' => (string) Str::uuid(),
        'visibility' => 'global',
        'kind' => 'curated_dataset',
        'title' => 'Default source',
    ]);
    $referenceId = catalogReferenceM232();
    $versionId = catalogVersionM232($referenceId);
    $portionId = catalogPortionM232($referenceId);

    expect(DB::table('food_sources')->find($sourceId)->authority_status)->toBe('prohibited')
        ->and(DB::table('food_references')->find($referenceId)->is_generic)->toBeFalse()
        ->and(DB::table('food_reference_versions')->find($versionId)->review_status)->toBe('draft')
        ->and(DB::table('food_portions')->find($portionId)->preparation_key)->toBe('any');
});

it('creates every named PostgreSQL check and partial unique index', function () {
    $constraintNames = DB::table('pg_constraint')->pluck('conname')->all();
    $indexNames = DB::table('pg_indexes')->where('schemaname', 'public')->pluck('indexname')->all();

    expect($constraintNames)->toContain(
        'food_sources_visibility_check',
        'food_sources_owner_scope_check',
        'food_sources_kind_check',
        'food_sources_authority_status_check',
        'food_references_visibility_check',
        'food_references_owner_scope_check',
        'food_reference_versions_version_number_positive_check',
        'food_reference_versions_review_status_check',
        'food_reference_versions_energy_basis_positive_check',
        'food_reference_versions_energy_kcal_positive_check',
        'food_reference_versions_activation_eligibility_check',
        'food_reference_versions_deactivation_requires_activation_check',
        'food_reference_versions_publication_requires_approval_check',
        'food_reference_versions_archived_not_active_check',
        'food_reference_version_sources_role_check',
        'food_aliases_revision_number_positive_check',
        'food_aliases_review_status_check',
        'food_aliases_activation_eligibility_check',
        'food_aliases_deactivation_requires_activation_check',
        'food_aliases_publication_requires_approval_check',
        'food_aliases_archived_not_active_check',
        'food_portions_revision_number_positive_check',
        'food_portions_unit_quantity_positive_check',
        'food_portions_gram_weight_positive_check',
        'food_portions_review_status_check',
        'food_portions_activation_eligibility_check',
        'food_portions_deactivation_requires_activation_check',
        'food_portions_publication_requires_approval_check',
        'food_portions_archived_not_active_check',
    )->and($indexNames)->toContain(
        'food_sources_public_id_unique',
        'food_references_public_id_unique',
        'food_reference_versions_public_id_unique',
        'food_aliases_public_id_unique',
        'food_portions_public_id_unique',
        'food_references_global_stable_key_unique',
        'food_references_private_stable_key_unique',
        'food_reference_versions_one_active_unique',
        'food_reference_version_sources_one_primary_unique',
        'food_aliases_one_active_key_unique',
        'food_portions_one_active_key_unique',
    );
});

it('enforces public identity and revision uniqueness', function () {
    $publicId = (string) Str::uuid();
    catalogSourceM232(['public_id' => $publicId]);
    expectCatalogConstraintFailureM232(fn () => catalogSourceM232(['public_id' => $publicId]));

    $referenceId = catalogReferenceM232();
    catalogVersionM232($referenceId);
    expectCatalogConstraintFailureM232(fn () => catalogVersionM232($referenceId));

    $aliasLineageId = (string) Str::uuid();
    catalogAliasM232($referenceId, ['lineage_id' => $aliasLineageId]);
    expectCatalogConstraintFailureM232(fn () => catalogAliasM232($referenceId, ['lineage_id' => $aliasLineageId]));

    $portionLineageId = (string) Str::uuid();
    catalogPortionM232($referenceId, ['lineage_id' => $portionLineageId]);
    expectCatalogConstraintFailureM232(fn () => catalogPortionM232($referenceId, ['lineage_id' => $portionLineageId]));
});

it('allows only one successor to supersede each revision', function () {
    $referenceId = catalogReferenceM232();
    $versionId = catalogVersionM232($referenceId);
    catalogVersionM232($referenceId, [
        'version_number' => 2,
        'supersedes_food_reference_version_id' => $versionId,
    ]);
    expectCatalogConstraintFailureM232(fn () => catalogVersionM232($referenceId, [
        'version_number' => 3,
        'supersedes_food_reference_version_id' => $versionId,
    ]));

    $aliasId = catalogAliasM232($referenceId);
    catalogAliasM232($referenceId, ['revision_number' => 2, 'supersedes_food_alias_id' => $aliasId]);
    expectCatalogConstraintFailureM232(fn () => catalogAliasM232($referenceId, ['revision_number' => 3, 'supersedes_food_alias_id' => $aliasId]));

    $portionId = catalogPortionM232($referenceId);
    catalogPortionM232($referenceId, ['revision_number' => 2, 'supersedes_food_portion_id' => $portionId]);
    expectCatalogConstraintFailureM232(fn () => catalogPortionM232($referenceId, ['revision_number' => 3, 'supersedes_food_portion_id' => $portionId]));
});

it('enforces owner scope and owner deletion while nulling audit actors', function () {
    $owner = User::factory()->create();
    $auditActor = User::factory()->create();

    expectCatalogConstraintFailureM232(fn () => catalogSourceM232(['owner_user_id' => $owner->id]));
    expectCatalogConstraintFailureM232(fn () => catalogSourceM232(['visibility' => 'private']));
    expectCatalogConstraintFailureM232(fn () => catalogSourceM232(['visibility' => 'unknown']));
    expectCatalogConstraintFailureM232(fn () => catalogReferenceM232(['owner_user_id' => $owner->id]));
    expectCatalogConstraintFailureM232(fn () => catalogReferenceM232(['visibility' => 'private']));

    $sourceId = catalogSourceM232([
        'visibility' => 'private',
        'owner_user_id' => $owner->id,
        'created_by_user_id' => $auditActor->id,
    ]);
    catalogReferenceM232(['visibility' => 'private', 'owner_user_id' => $owner->id]);

    expectCatalogConstraintFailureM232(fn () => DB::table('users')->where('id', $owner->id)->delete());

    $auditActor->delete();
    expect(DB::table('food_sources')->find($sourceId)->created_by_user_id)->toBeNull();
});

it('enforces global and owner-scoped stable keys', function () {
    catalogReferenceM232(['stable_key' => 'rice']);
    expectCatalogConstraintFailureM232(fn () => catalogReferenceM232(['stable_key' => 'rice']));

    $firstOwner = User::factory()->create();
    $secondOwner = User::factory()->create();
    catalogReferenceM232(['stable_key' => 'private-rice', 'visibility' => 'private', 'owner_user_id' => $firstOwner->id]);
    catalogReferenceM232(['stable_key' => 'private-rice', 'visibility' => 'private', 'owner_user_id' => $secondOwner->id]);
    expectCatalogConstraintFailureM232(fn () => catalogReferenceM232([
        'stable_key' => 'private-rice',
        'visibility' => 'private',
        'owner_user_id' => $firstOwner->id,
    ]));
});

it('enforces version energy and lifecycle eligibility', function () {
    $referenceId = catalogReferenceM232();
    $draftId = catalogVersionM232($referenceId);
    $positiveId = catalogVersionM232($referenceId, [
        'version_number' => 2,
        'energy_basis_grams' => 100,
        'energy_kcal' => 120,
    ]);

    expect(DB::table('food_reference_versions')->find($draftId)->energy_kcal)->toBeNull()
        ->and((float) DB::table('food_reference_versions')->find($positiveId)->energy_kcal)->toBe(120.0);

    expectCatalogConstraintFailureM232(fn () => catalogVersionM232($referenceId, ['version_number' => 3, 'energy_basis_grams' => 0]));
    expectCatalogConstraintFailureM232(fn () => catalogVersionM232($referenceId, ['version_number' => 3, 'energy_kcal' => -1]));
    expectCatalogConstraintFailureM232(fn () => catalogVersionM232($referenceId, ['version_number' => 3, 'published_at' => now()]));
    expectCatalogConstraintFailureM232(fn () => catalogVersionM232($referenceId, ['version_number' => 3, 'review_status' => 'invalid']));
    expectCatalogConstraintFailureM232(fn () => catalogVersionM232($referenceId, ['version_number' => 3, 'activated_at' => now()]));
    expectCatalogConstraintFailureM232(fn () => catalogVersionM232($referenceId, [
        'version_number' => 3,
        'review_status' => 'approved',
        'published_at' => now(),
        'activated_at' => now(),
        'energy_basis_grams' => 100,
    ]));

    catalogVersionM232($referenceId, [
        'version_number' => 3,
        'review_status' => 'approved',
        'published_at' => now(),
        'activated_at' => now(),
        'energy_basis_grams' => 100,
        'energy_kcal' => 120,
    ]);
    expectCatalogConstraintFailureM232(fn () => catalogVersionM232($referenceId, [
        'version_number' => 4,
        'review_status' => 'approved',
        'published_at' => now(),
        'activated_at' => now(),
        'energy_basis_grams' => 100,
        'energy_kcal' => 130,
    ]));
    catalogVersionM232($referenceId, ['version_number' => 4]);
});

it('enforces source vocabularies, roles, uniqueness, and restricted deletion', function () {
    expectCatalogConstraintFailureM232(fn () => catalogSourceM232(['kind' => 'unknown']));
    expectCatalogConstraintFailureM232(fn () => catalogSourceM232(['authority_status' => 'unknown']));
    catalogSourceM232(['kind' => 'app_generated_estimate', 'authority_status' => 'eligible']);

    $referenceId = catalogReferenceM232();
    $versionId = catalogVersionM232($referenceId);
    $primarySourceId = catalogSourceM232();
    $supportingSourceId = catalogSourceM232();

    expectCatalogConstraintFailureM232(fn () => DB::table('food_reference_version_sources')->insert([
        'food_reference_version_id' => $versionId,
        'food_source_id' => $primarySourceId,
        'role' => 'unknown',
    ]));
    DB::table('food_reference_version_sources')->insert([
        'food_reference_version_id' => $versionId,
        'food_source_id' => $primarySourceId,
        'role' => 'primary',
    ]);
    expectCatalogConstraintFailureM232(fn () => DB::table('food_reference_version_sources')->insert([
        'food_reference_version_id' => $versionId,
        'food_source_id' => $primarySourceId,
        'role' => 'supporting',
    ]));
    expectCatalogConstraintFailureM232(fn () => DB::table('food_reference_version_sources')->insert([
        'food_reference_version_id' => $versionId,
        'food_source_id' => $supportingSourceId,
        'role' => 'primary',
    ]));
    DB::table('food_reference_version_sources')->insert([
        'food_reference_version_id' => $versionId,
        'food_source_id' => $supportingSourceId,
        'role' => 'supporting',
    ]);
    expectCatalogConstraintFailureM232(fn () => DB::table('food_sources')->where('id', $primarySourceId)->delete());
});

it('allows alias ambiguity across references but enforces revisions and active keys', function () {
    $firstReferenceId = catalogReferenceM232();
    $secondReferenceId = catalogReferenceM232();
    catalogAliasM232($firstReferenceId, ['normalized_alias' => 'rice']);
    catalogAliasM232($secondReferenceId, ['normalized_alias' => 'rice']);

    expectCatalogConstraintFailureM232(fn () => catalogAliasM232($firstReferenceId, ['revision_number' => 0]));
    expectCatalogConstraintFailureM232(fn () => catalogAliasM232($firstReferenceId, ['published_at' => now()]));
    expectCatalogConstraintFailureM232(fn () => catalogAliasM232($firstReferenceId, ['activated_at' => now()]));

    catalogAliasM232($firstReferenceId, [
        'normalized_alias' => 'active rice',
        'review_status' => 'approved',
        'published_at' => now(),
        'activated_at' => now(),
    ]);
    expectCatalogConstraintFailureM232(fn () => catalogAliasM232($firstReferenceId, [
        'normalized_alias' => 'active rice',
        'review_status' => 'approved',
        'published_at' => now(),
        'activated_at' => now(),
    ]));
    catalogAliasM232($firstReferenceId, ['normalized_alias' => 'active rice']);
});

it('enforces positive portions, lifecycle eligibility, and active keys', function () {
    $referenceId = catalogReferenceM232();
    expectCatalogConstraintFailureM232(fn () => catalogPortionM232($referenceId, ['unit_quantity' => 0]));
    expectCatalogConstraintFailureM232(fn () => catalogPortionM232($referenceId, ['gram_weight' => -1]));
    expectCatalogConstraintFailureM232(fn () => catalogPortionM232($referenceId, ['published_at' => now()]));
    expectCatalogConstraintFailureM232(fn () => catalogPortionM232($referenceId, ['activated_at' => now()]));

    catalogPortionM232($referenceId, [
        'portion_key' => 'cup',
        'review_status' => 'approved',
        'published_at' => now(),
        'activated_at' => now(),
    ]);
    expectCatalogConstraintFailureM232(fn () => catalogPortionM232($referenceId, [
        'portion_key' => 'cup',
        'review_status' => 'approved',
        'published_at' => now(),
        'activated_at' => now(),
    ]));
    catalogPortionM232($referenceId, ['portion_key' => 'cup']);
});
