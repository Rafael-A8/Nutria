<?php

use App\Models\User;
use App\Nutrition\Domain\Catalog\Enums\CatalogReviewStatus;
use App\Nutrition\Domain\Catalog\Enums\CatalogVisibility;
use App\Nutrition\Domain\Catalog\Enums\FoodReferenceVersionSourceRole;
use App\Nutrition\Domain\Catalog\Enums\FoodSourceAuthorityStatus;
use App\Nutrition\Domain\Catalog\Enums\FoodSourceKind;
use App\Nutrition\Infrastructure\Catalog\Eloquent\FoodAlias;
use App\Nutrition\Infrastructure\Catalog\Eloquent\FoodPortion;
use App\Nutrition\Infrastructure\Catalog\Eloquent\FoodReference;
use App\Nutrition\Infrastructure\Catalog\Eloquent\FoodReferenceVersion;
use App\Nutrition\Infrastructure\Catalog\Eloquent\FoodReferenceVersionSource;
use App\Nutrition\Infrastructure\Catalog\Eloquent\FoodSource;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

function expectCatalogModelConstraintFailureM233(Closure $operation): void
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

    throw new RuntimeException('The database accepted a catalog model missing an explicit identity.');
}

it('exposes the approved table metadata and fillable attributes', function (
    string $modelClass,
    string $table,
    array $requiredFillable,
) {
    $model = new $modelClass;
    $reflection = new ReflectionClass($modelClass);
    $declaredMethods = collect($reflection->getMethods())
        ->filter(fn (ReflectionMethod $method): bool => $method->getDeclaringClass()->getName() === $modelClass)
        ->pluck('name')
        ->all();

    expect($model->getTable())->toBe($table)
        ->and($model->getFillable())->toContain(...$requiredFillable)
        ->and($model->getFillable())->not->toContain('id', 'created_at', 'updated_at')
        ->and($model->getDateFormat())->toBe('Y-m-d H:i:s.uP')
        ->and(class_uses_recursive($modelClass))->not->toContain(HasUuids::class)
        ->and($model->getGlobalScopes())->toBe([])
        ->and($declaredMethods)->not->toContain('boot', 'booted');
})->with([
    [FoodSource::class, 'food_sources', ['public_id', 'visibility', 'owner_user_id', 'kind', 'authority_status', 'title', 'retrieved_at', 'metadata', 'archived_at', 'archived_by_user_id', 'created_by_user_id']],
    [FoodReference::class, 'food_references', ['public_id', 'stable_key', 'visibility', 'owner_user_id', 'is_generic', 'archived_at', 'archived_by_user_id', 'created_by_user_id']],
    [FoodReferenceVersion::class, 'food_reference_versions', ['public_id', 'food_reference_id', 'version_number', 'canonical_name', 'normalized_canonical_name', 'locale', 'classification', 'preparation_key', 'energy_basis_grams', 'energy_kcal', 'nutrient_values', 'provenance', 'review_status', 'supersedes_food_reference_version_id', 'created_by_user_id']],
    [FoodReferenceVersionSource::class, 'food_reference_version_sources', ['food_reference_version_id', 'food_source_id', 'role', 'source_record_key', 'evidence_metadata', 'created_by_user_id']],
    [FoodAlias::class, 'food_aliases', ['public_id', 'lineage_id', 'food_reference_id', 'revision_number', 'supersedes_food_alias_id', 'display_alias', 'normalized_alias', 'locale', 'alias_kind', 'food_source_id', 'provenance', 'review_status', 'created_by_user_id']],
    [FoodPortion::class, 'food_portions', ['public_id', 'lineage_id', 'food_reference_id', 'revision_number', 'supersedes_food_portion_id', 'portion_key', 'display_label', 'normalized_label', 'locale', 'portion_type', 'unit_code', 'unit_quantity', 'gram_weight', 'preparation_key', 'food_source_id', 'provenance', 'review_status', 'created_by_user_id']],
]);

it('casts catalog values without changing string vocabularies', function () {
    $moment = CarbonImmutable::parse('2026-07-12 10:11:12.123456-03:00');
    $source = FoodSource::factory()->create([
        'kind' => FoodSourceKind::ScientificPublication,
        'authority_status' => FoodSourceAuthorityStatus::Eligible,
        'retrieved_at' => $moment,
        'metadata' => ['doi' => '10.0000/catalog'],
        'archived_at' => $moment,
    ])->refresh();
    $reference = FoodReference::factory()->generic()->create(['archived_at' => $moment])->refresh();
    $version = FoodReferenceVersion::factory()->active()->create([
        'submitted_at' => $moment,
        'reviewed_at' => $moment,
        'published_at' => $moment,
        'activated_at' => $moment,
        'deactivated_at' => $moment,
        'withdrawn_at' => $moment,
        'archived_at' => $moment,
        'energy_basis_grams' => '100.1234',
        'energy_kcal' => '222.5678',
        'nutrient_values' => ['protein' => 10],
        'provenance' => ['method' => 'laboratory'],
        'preparation_key' => 'cooked',
    ])->refresh();
    $link = FoodReferenceVersionSource::factory()->primary()->create([
        'evidence_metadata' => ['page' => 10],
    ])->refresh();
    $alias = FoodAlias::factory()->active()->create([
        'submitted_at' => $moment,
        'deactivated_at' => $moment,
        'withdrawn_at' => $moment,
        'archived_at' => $moment,
        'provenance' => ['origin' => 'editorial'],
    ])->refresh();
    $portion = FoodPortion::factory()->active()->create([
        'submitted_at' => $moment,
        'deactivated_at' => $moment,
        'withdrawn_at' => $moment,
        'archived_at' => $moment,
        'unit_quantity' => '1.2500',
        'gram_weight' => '87.5000',
        'provenance' => ['origin' => 'measurement'],
    ])->refresh();

    expect($source->visibility)->toBe(CatalogVisibility::Global)
        ->and($source->kind)->toBe(FoodSourceKind::ScientificPublication)
        ->and($source->authority_status)->toBe(FoodSourceAuthorityStatus::Eligible)
        ->and($source->metadata)->toBe(['doi' => '10.0000/catalog'])
        ->and($source->retrieved_at)->toBeInstanceOf(CarbonImmutable::class)
        ->and($source->retrieved_at->format('u'))->toBe('123456')
        ->and($source->created_at)->toBeInstanceOf(CarbonImmutable::class)
        ->and($reference->is_generic)->toBeTrue()
        ->and($reference->archived_at)->toBeInstanceOf(CarbonImmutable::class)
        ->and($version->version_number)->toBeInt()
        ->and($version->energy_basis_grams)->toBe('100.1234')
        ->and($version->energy_kcal)->toBe('222.5678')
        ->and($version->nutrient_values)->toBe(['protein' => 10])
        ->and($version->provenance)->toBe(['method' => 'laboratory'])
        ->and($version->review_status)->toBe(CatalogReviewStatus::Approved)
        ->and($version->submitted_at)->toBeInstanceOf(CarbonImmutable::class)
        ->and($version->archived_at)->toBeInstanceOf(CarbonImmutable::class)
        ->and($version->classification)->toBeString()
        ->and($version->preparation_key)->toBeString()
        ->and($link->role)->toBe(FoodReferenceVersionSourceRole::Primary)
        ->and($link->evidence_metadata)->toBe(['page' => 10])
        ->and($alias->revision_number)->toBeInt()
        ->and($alias->provenance)->toBe(['origin' => 'editorial'])
        ->and($alias->alias_kind)->toBeString()
        ->and($alias->locale)->toBeString()
        ->and($portion->revision_number)->toBeInt()
        ->and($portion->unit_quantity)->toBe('1.2500')
        ->and($portion->gram_weight)->toBe('87.5000')
        ->and($portion->provenance)->toBe(['origin' => 'measurement'])
        ->and($portion->portion_type)->toBeString()
        ->and($portion->unit_code)->toBeString()
        ->and($portion->preparation_key)->toBeString();
});

it('persists every explicit catalog relationship', function () {
    $owner = User::factory()->create();
    $actor = User::factory()->create();
    $source = FoodSource::factory()->privateFor($owner)->archived()->create([
        'created_by_user_id' => $actor->id,
        'archived_by_user_id' => $actor->id,
    ]);
    $reference = FoodReference::factory()->privateFor($owner)->archived()->create([
        'created_by_user_id' => $actor->id,
        'archived_by_user_id' => $actor->id,
    ]);
    $version = FoodReferenceVersion::factory()->create([
        'food_reference_id' => $reference->id,
        'created_by_user_id' => $actor->id,
        'submitted_by_user_id' => $actor->id,
        'reviewed_by_user_id' => $actor->id,
        'published_by_user_id' => $actor->id,
        'activated_by_user_id' => $actor->id,
        'deactivated_by_user_id' => $actor->id,
        'withdrawn_by_user_id' => $actor->id,
        'archived_by_user_id' => $actor->id,
    ]);
    $successorVersion = FoodReferenceVersion::factory()->create([
        'food_reference_id' => $reference->id,
        'version_number' => 2,
        'supersedes_food_reference_version_id' => $version->id,
    ]);
    $link = FoodReferenceVersionSource::factory()->create([
        'food_reference_version_id' => $version->id,
        'food_source_id' => $source->id,
        'created_by_user_id' => $actor->id,
    ]);
    $alias = FoodAlias::factory()->create([
        'food_reference_id' => $reference->id,
        'food_source_id' => $source->id,
        'created_by_user_id' => $actor->id,
        'submitted_by_user_id' => $actor->id,
        'reviewed_by_user_id' => $actor->id,
        'published_by_user_id' => $actor->id,
        'activated_by_user_id' => $actor->id,
        'deactivated_by_user_id' => $actor->id,
        'withdrawn_by_user_id' => $actor->id,
        'archived_by_user_id' => $actor->id,
    ]);
    $successorAlias = FoodAlias::factory()->create([
        'food_reference_id' => $reference->id,
        'revision_number' => 2,
        'supersedes_food_alias_id' => $alias->id,
    ]);
    $portion = FoodPortion::factory()->create([
        'food_reference_id' => $reference->id,
        'food_source_id' => $source->id,
        'created_by_user_id' => $actor->id,
        'submitted_by_user_id' => $actor->id,
        'reviewed_by_user_id' => $actor->id,
        'published_by_user_id' => $actor->id,
        'activated_by_user_id' => $actor->id,
        'deactivated_by_user_id' => $actor->id,
        'withdrawn_by_user_id' => $actor->id,
        'archived_by_user_id' => $actor->id,
    ]);
    $successorPortion = FoodPortion::factory()->create([
        'food_reference_id' => $reference->id,
        'revision_number' => 2,
        'supersedes_food_portion_id' => $portion->id,
    ]);

    expect($source->owner->is($owner))->toBeTrue()
        ->and($source->createdBy->is($actor))->toBeTrue()
        ->and($source->archivedBy->is($actor))->toBeTrue()
        ->and($source->versionSourceLinks->contains($link))->toBeTrue()
        ->and($source->aliases->contains($alias))->toBeTrue()
        ->and($source->portions->contains($portion))->toBeTrue()
        ->and($reference->owner->is($owner))->toBeTrue()
        ->and($reference->createdBy->is($actor))->toBeTrue()
        ->and($reference->archivedBy->is($actor))->toBeTrue()
        ->and($reference->versions->contains($version))->toBeTrue()
        ->and($reference->aliases->contains($alias))->toBeTrue()
        ->and($reference->portions->contains($portion))->toBeTrue()
        ->and($version->reference->is($reference))->toBeTrue()
        ->and($version->sourceLinks->contains($link))->toBeTrue()
        ->and($successorVersion->supersedes->is($version))->toBeTrue()
        ->and($version->supersededBy->is($successorVersion))->toBeTrue()
        ->and($link->version->is($version))->toBeTrue()
        ->and($link->source->is($source))->toBeTrue()
        ->and($link->createdBy->is($actor))->toBeTrue()
        ->and($alias->reference->is($reference))->toBeTrue()
        ->and($alias->source->is($source))->toBeTrue()
        ->and($successorAlias->supersedes->is($alias))->toBeTrue()
        ->and($alias->supersededBy->is($successorAlias))->toBeTrue()
        ->and($portion->reference->is($reference))->toBeTrue()
        ->and($portion->source->is($source))->toBeTrue()
        ->and($successorPortion->supersedes->is($portion))->toBeTrue()
        ->and($portion->supersededBy->is($successorPortion))->toBeTrue();

    foreach ([$version, $alias, $portion] as $auditedRecord) {
        foreach (['createdBy', 'submittedBy', 'reviewedBy', 'publishedBy', 'activatedBy', 'deactivatedBy', 'withdrawnBy', 'archivedBy'] as $relation) {
            expect($auditedRecord->{$relation}->is($actor))->toBeTrue();
        }
    }
});

it('preserves explicit public and lineage UUIDs without generating replacements', function () {
    $sourcePublicId = (string) Str::uuid();
    $aliasPublicId = (string) Str::uuid();
    $aliasLineageId = (string) Str::uuid();
    $portionPublicId = (string) Str::uuid();
    $portionLineageId = (string) Str::uuid();

    $source = FoodSource::factory()->create(['public_id' => $sourcePublicId]);
    $alias = FoodAlias::factory()->create([
        'public_id' => $aliasPublicId,
        'lineage_id' => $aliasLineageId,
    ]);
    $portion = FoodPortion::factory()->create([
        'public_id' => $portionPublicId,
        'lineage_id' => $portionLineageId,
    ]);

    expect($source->refresh()->public_id)->toBe($sourcePublicId)
        ->and($alias->refresh()->public_id)->toBe($aliasPublicId)
        ->and($alias->lineage_id)->toBe($aliasLineageId)
        ->and($portion->refresh()->public_id)->toBe($portionPublicId)
        ->and($portion->lineage_id)->toBe($portionLineageId)
        ->and(FoodSource::factory()->make(['public_id' => null])->public_id)->toBeNull()
        ->and(FoodAlias::factory()->make(['lineage_id' => null])->lineage_id)->toBeNull();

    expectCatalogModelConstraintFailureM233(
        fn () => FoodSource::factory()->create(['public_id' => null]),
    );
    expectCatalogModelConstraintFailureM233(
        fn () => FoodAlias::factory()->create(['lineage_id' => null]),
    );
});
