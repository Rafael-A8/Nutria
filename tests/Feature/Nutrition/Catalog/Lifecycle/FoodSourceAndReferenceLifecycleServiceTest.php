<?php

use App\Models\User;
use App\Nutrition\Application\Catalog\Lifecycle\CatalogLifecycleProjectionStateResolver;
use App\Nutrition\Application\Catalog\Lifecycle\CatalogLifecycleReplayGuard;
use App\Nutrition\Application\Catalog\Lifecycle\CatalogLifecycleRootEventFactory;
use App\Nutrition\Application\Catalog\Lifecycle\FoodReferenceLifecycleService;
use App\Nutrition\Application\Catalog\Lifecycle\FoodSourceLifecycleService;
use App\Nutrition\Application\Catalog\Lifecycle\ValueObjects\CatalogLifecycleExecutionContext;
use App\Nutrition\Domain\Catalog\Enums\FoodSourceAuthorityStatus;
use App\Nutrition\Domain\Catalog\Lifecycle\Enums\CatalogLifecycleOperation;
use App\Nutrition\Domain\Catalog\Lifecycle\Enums\CatalogLifecycleOutcome;
use App\Nutrition\Domain\Catalog\Lifecycle\Enums\CatalogLifecycleReason;
use App\Nutrition\Domain\Catalog\Lifecycle\Enums\CatalogLifecycleSubjectType;
use App\Nutrition\Domain\Catalog\Lifecycle\Policies\FoodReferenceLifecyclePolicy;
use App\Nutrition\Domain\Catalog\Lifecycle\Policies\FoodSourceLifecyclePolicy;
use App\Nutrition\Domain\Catalog\Lifecycle\ValueObjects\CatalogLifecycleCommand;
use App\Nutrition\Infrastructure\Catalog\Eloquent\EloquentCatalogLifecycleEventStore;
use App\Nutrition\Infrastructure\Catalog\Eloquent\FoodAlias;
use App\Nutrition\Infrastructure\Catalog\Eloquent\FoodPortion;
use App\Nutrition\Infrastructure\Catalog\Eloquent\FoodReference;
use App\Nutrition\Infrastructure\Catalog\Eloquent\FoodReferenceVersion;
use App\Nutrition\Infrastructure\Catalog\Eloquent\FoodReferenceVersionSource;
use App\Nutrition\Infrastructure\Catalog\Eloquent\FoodSource;
use Illuminate\Support\Str;

function sourceServiceM2344(): FoodSourceLifecycleService
{
    $store = new EloquentCatalogLifecycleEventStore;

    return new FoodSourceLifecycleService(new FoodSourceLifecyclePolicy, $store, new CatalogLifecycleReplayGuard($store), new CatalogLifecycleRootEventFactory, new CatalogLifecycleProjectionStateResolver);
}

function referenceServiceM2344(): FoodReferenceLifecycleService
{
    $store = new EloquentCatalogLifecycleEventStore;

    return new FoodReferenceLifecycleService(new FoodReferenceLifecyclePolicy, $store, new CatalogLifecycleReplayGuard($store), new CatalogLifecycleRootEventFactory, new CatalogLifecycleProjectionStateResolver);
}

function simpleCommandM2344(string $publicId, User $actor, CatalogLifecycleSubjectType $type, CatalogLifecycleOperation $operation): CatalogLifecycleCommand
{
    return new CatalogLifecycleCommand($type, $publicId, $operation, (string) $actor->id, 'Governed reason.', (string) Str::uuid7(), new DateTimeImmutable('2026-07-12T16:30:00-03:00'));
}

function simpleContextM2344(User $actor): CatalogLifecycleExecutionContext
{
    return new CatalogLifecycleExecutionContext($actor->id, "audit:user:{$actor->id}");
}

it('changes source authority with exact metadata and no dependent mutation', function () {
    $actor = User::factory()->create();
    $source = FoodSource::factory()->create();
    $version = FoodReferenceVersion::factory()->create();
    $link = FoodReferenceVersionSource::factory()->create(['food_reference_version_id' => $version->id, 'food_source_id' => $source->id]);
    $command = simpleCommandM2344($source->public_id, $actor, CatalogLifecycleSubjectType::Source, CatalogLifecycleOperation::ChangeAuthority);

    $execution = sourceServiceM2344()->changeAuthority($command, simpleContextM2344($actor), FoodSourceAuthorityStatus::Eligible);

    expect($source->refresh()->authority_status)->toBe(FoodSourceAuthorityStatus::Eligible)
        ->and($execution->rootEvent->metadata)->toBe(['previous_authority' => 'prohibited', 'next_authority' => 'eligible'])
        ->and($link->refresh()->food_source_id)->toBe($source->id)
        ->and($version->refresh()->activated_at)->toBeNull();
});

it('audits an unchanged authority target according to policy semantics', function () {
    $actor = User::factory()->create();
    $source = FoodSource::factory()->eligible()->create();

    $execution = sourceServiceM2344()->changeAuthority(
        simpleCommandM2344($source->public_id, $actor, CatalogLifecycleSubjectType::Source, CatalogLifecycleOperation::ChangeAuthority),
        simpleContextM2344($actor), FoodSourceAuthorityStatus::Eligible,
    );

    expect($execution->lifecycleResult->reason)->toBe(CatalogLifecycleReason::CatalogIntegrityViolation)
        ->and($execution->rootEvent->metadata)->toBe([]);
});

it('archives a source without editing content or removing links and persists archive no op', function () {
    $actor = User::factory()->create();
    $source = FoodSource::factory()->create();
    $link = FoodReferenceVersionSource::factory()->create(['food_source_id' => $source->id]);
    $title = $source->title;
    $service = sourceServiceM2344();

    $service->archive(simpleCommandM2344($source->public_id, $actor, CatalogLifecycleSubjectType::Source, CatalogLifecycleOperation::Archive), simpleContextM2344($actor));
    $noOp = $service->archive(simpleCommandM2344($source->public_id, $actor, CatalogLifecycleSubjectType::Source, CatalogLifecycleOperation::Archive), simpleContextM2344($actor));

    expect($source->refresh()->title)->toBe($title)
        ->and($source->archived_by_user_id)->toBe($actor->id)
        ->and($link->refresh()->food_source_id)->toBe($source->id)
        ->and($noOp->lifecycleResult->outcome)->toBe(CatalogLifecycleOutcome::NoOp);
});

it('archives a reference only without active children and preserves stable identity', function () {
    $actor = User::factory()->create();
    $reference = FoodReference::factory()->create();
    $identity = $reference->only(['stable_key', 'visibility', 'owner_user_id', 'is_generic']);

    referenceServiceM2344()->archive(
        simpleCommandM2344($reference->public_id, $actor, CatalogLifecycleSubjectType::Reference, CatalogLifecycleOperation::Archive), simpleContextM2344($actor),
    );

    expect($reference->refresh()->archived_by_user_id)->toBe($actor->id)
        ->and($reference->only(array_keys($identity)))->toBe($identity);
});

it('blocks reference archive for each active child without deactivating children', function (string $child) {
    $actor = User::factory()->create();
    $reference = FoodReference::factory()->create();
    $active = match ($child) {
        'version' => FoodReferenceVersion::factory()->active()->create(['food_reference_id' => $reference->id]),
        'alias' => FoodAlias::factory()->active()->create(['food_reference_id' => $reference->id]),
        'portion' => FoodPortion::factory()->active()->create(['food_reference_id' => $reference->id]),
    };

    $execution = referenceServiceM2344()->archive(
        simpleCommandM2344($reference->public_id, $actor, CatalogLifecycleSubjectType::Reference, CatalogLifecycleOperation::Archive), simpleContextM2344($actor),
    );

    expect($execution->lifecycleResult->reason)->toBe(CatalogLifecycleReason::ReferenceHasActiveChildren)
        ->and($reference->refresh()->archived_at)->toBeNull()
        ->and($active->refresh()->deactivated_at)->toBeNull();
})->with(['version', 'alias', 'portion']);
