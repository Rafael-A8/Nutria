<?php

use App\Models\User;
use App\Nutrition\Domain\Catalog\Enums\CatalogReviewStatus;
use App\Nutrition\Domain\Catalog\Enums\CatalogVisibility;
use App\Nutrition\Domain\Catalog\Enums\FoodReferenceVersionSourceRole;
use App\Nutrition\Domain\Catalog\Enums\FoodSourceAuthorityStatus;
use App\Nutrition\Infrastructure\Catalog\Eloquent\FoodAlias;
use App\Nutrition\Infrastructure\Catalog\Eloquent\FoodPortion;
use App\Nutrition\Infrastructure\Catalog\Eloquent\FoodReference;
use App\Nutrition\Infrastructure\Catalog\Eloquent\FoodReferenceVersion;
use App\Nutrition\Infrastructure\Catalog\Eloquent\FoodReferenceVersionSource;
use App\Nutrition\Infrastructure\Catalog\Eloquent\FoodSource;
use Database\Factories\Nutrition\Catalog\FoodAliasFactory;
use Database\Factories\Nutrition\Catalog\FoodPortionFactory;
use Database\Factories\Nutrition\Catalog\FoodReferenceFactory;
use Database\Factories\Nutrition\Catalog\FoodReferenceVersionFactory;
use Database\Factories\Nutrition\Catalog\FoodReferenceVersionSourceFactory;
use Database\Factories\Nutrition\Catalog\FoodSourceFactory;
use Illuminate\Support\Str;

it('binds every catalog model to its explicit factory', function (string $model, string $factory) {
    expect($model::factory())->toBeInstanceOf($factory);
})->with([
    [FoodSource::class, FoodSourceFactory::class],
    [FoodReference::class, FoodReferenceFactory::class],
    [FoodReferenceVersion::class, FoodReferenceVersionFactory::class],
    [FoodReferenceVersionSource::class, FoodReferenceVersionSourceFactory::class],
    [FoodAlias::class, FoodAliasFactory::class],
    [FoodPortion::class, FoodPortionFactory::class],
]);

it('creates valid inactive defaults for all catalog factories', function () {
    $source = FoodSource::factory()->create();
    $reference = FoodReference::factory()->create();
    $version = FoodReferenceVersion::factory()->create();
    $versionSource = FoodReferenceVersionSource::factory()->create();
    $alias = FoodAlias::factory()->create();
    $portion = FoodPortion::factory()->create();

    foreach ([$source, $reference, $version, $alias, $portion] as $model) {
        expect(Str::isUuid($model->public_id, version: 7))->toBeTrue();
    }

    expect(Str::isUuid($alias->lineage_id, version: 7))->toBeTrue()
        ->and(Str::isUuid($portion->lineage_id, version: 7))->toBeTrue()
        ->and($source->visibility)->toBe(CatalogVisibility::Global)
        ->and($source->owner_user_id)->toBeNull()
        ->and($source->authority_status)->toBe(FoodSourceAuthorityStatus::Prohibited)
        ->and($reference->visibility)->toBe(CatalogVisibility::Global)
        ->and($reference->owner_user_id)->toBeNull()
        ->and($reference->is_generic)->toBeFalse()
        ->and($version->review_status)->toBe(CatalogReviewStatus::Draft)
        ->and($version->activated_at)->toBeNull()
        ->and($alias->review_status)->toBe(CatalogReviewStatus::Draft)
        ->and($alias->activated_at)->toBeNull()
        ->and($alias->food_source_id)->toBeNull()
        ->and($portion->review_status)->toBe(CatalogReviewStatus::Draft)
        ->and($portion->activated_at)->toBeNull()
        ->and($portion->food_source_id)->toBeNull()
        ->and($versionSource->role)->toBe(FoodReferenceVersionSourceRole::Supporting);
});

it('creates schema-valid source and reference states', function () {
    $owner = User::factory()->create();
    $privateSource = FoodSource::factory()->privateFor($owner)->eligible()->create();
    $untrustedSource = FoodSource::factory()->untrusted()->create();
    $archivedSource = FoodSource::factory()->archived()->create();
    $privateReference = FoodReference::factory()->privateFor($owner)->generic()->create();
    $archivedReference = FoodReference::factory()->archived()->create();

    expect($privateSource->visibility)->toBe(CatalogVisibility::Private)
        ->and($privateSource->owner->is($owner))->toBeTrue()
        ->and($privateSource->authority_status)->toBe(FoodSourceAuthorityStatus::Eligible)
        ->and($untrustedSource->authority_status)->toBe(FoodSourceAuthorityStatus::Untrusted)
        ->and($archivedSource->archived_at)->not->toBeNull()
        ->and($privateReference->visibility)->toBe(CatalogVisibility::Private)
        ->and($privateReference->owner->is($owner))->toBeTrue()
        ->and($privateReference->is_generic)->toBeTrue()
        ->and($archivedReference->archived_at)->not->toBeNull();
});

it('creates schema-valid version lifecycle states', function () {
    $withNutrition = FoodReferenceVersion::factory()->withNutrition()->create();
    $approved = FoodReferenceVersion::factory()->approved()->create();
    $published = FoodReferenceVersion::factory()->published()->create();
    $active = FoodReferenceVersion::factory()->active()->create();

    expect($withNutrition->energy_basis_grams)->toBe('100.0000')
        ->and($withNutrition->energy_kcal)->toBe('120.0000')
        ->and($approved->review_status)->toBe(CatalogReviewStatus::Approved)
        ->and($approved->reviewed_at)->not->toBeNull()
        ->and($published->review_status)->toBe(CatalogReviewStatus::Approved)
        ->and($published->published_at)->not->toBeNull()
        ->and($active->review_status)->toBe(CatalogReviewStatus::Approved)
        ->and($active->published_at)->not->toBeNull()
        ->and($active->activated_at)->not->toBeNull()
        ->and($active->energy_basis_grams)->toBe('100.0000')
        ->and($active->energy_kcal)->toBe('120.0000')
        ->and($active->deactivated_at)->toBeNull()
        ->and($active->withdrawn_at)->toBeNull()
        ->and($active->archived_at)->toBeNull();
});

it('creates valid source-link alias and portion states', function () {
    $primaryLink = FoodReferenceVersionSource::factory()->primary()->create();
    $aliasWithSource = FoodAlias::factory()->withSource()->create();
    $activeAlias = FoodAlias::factory()->active()->create();
    $portionWithSource = FoodPortion::factory()->withSource()->create();
    $activePortion = FoodPortion::factory()->active()->create();

    expect($primaryLink->role)->toBe(FoodReferenceVersionSourceRole::Primary)
        ->and($aliasWithSource->source)->toBeInstanceOf(FoodSource::class)
        ->and($activeAlias->review_status)->toBe(CatalogReviewStatus::Approved)
        ->and($activeAlias->published_at)->not->toBeNull()
        ->and($activeAlias->activated_at)->not->toBeNull()
        ->and($portionWithSource->source)->toBeInstanceOf(FoodSource::class)
        ->and($activePortion->review_status)->toBe(CatalogReviewStatus::Approved)
        ->and($activePortion->published_at)->not->toBeNull()
        ->and($activePortion->activated_at)->not->toBeNull()
        ->and($activePortion->unit_quantity)->toBe('1.0000')
        ->and($activePortion->gram_weight)->toBe('100.0000');
});
