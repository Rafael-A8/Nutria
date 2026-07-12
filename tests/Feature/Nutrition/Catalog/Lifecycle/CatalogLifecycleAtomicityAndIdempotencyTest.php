<?php

use App\Models\User;
use App\Nutrition\Application\Catalog\Lifecycle\CatalogLifecycleProjectionStateResolver;
use App\Nutrition\Application\Catalog\Lifecycle\CatalogLifecycleReplayGuard;
use App\Nutrition\Application\Catalog\Lifecycle\CatalogLifecycleRootEventFactory;
use App\Nutrition\Application\Catalog\Lifecycle\Contracts\CatalogLifecycleEventStore;
use App\Nutrition\Application\Catalog\Lifecycle\Exceptions\CatalogLifecycleIdempotencyConflictException;
use App\Nutrition\Application\Catalog\Lifecycle\Exceptions\CatalogLifecycleSubjectNotFoundException;
use App\Nutrition\Application\Catalog\Lifecycle\FoodReferenceVersionLifecycleService;
use App\Nutrition\Application\Catalog\Lifecycle\ValueObjects\CatalogLifecycleEventDraft;
use App\Nutrition\Application\Catalog\Lifecycle\ValueObjects\CatalogLifecycleExecutionContext;
use App\Nutrition\Application\Catalog\Lifecycle\ValueObjects\CatalogLifecycleStoredEvent;
use App\Nutrition\Domain\Catalog\Enums\CatalogReviewStatus;
use App\Nutrition\Domain\Catalog\Lifecycle\Enums\CatalogLifecycleOperation;
use App\Nutrition\Domain\Catalog\Lifecycle\Enums\CatalogLifecycleOutcome;
use App\Nutrition\Domain\Catalog\Lifecycle\Enums\CatalogLifecycleSubjectType;
use App\Nutrition\Domain\Catalog\Lifecycle\Policies\FoodReferenceVersionLifecyclePolicy;
use App\Nutrition\Domain\Catalog\Lifecycle\ValueObjects\CatalogLifecycleCommand;
use App\Nutrition\Infrastructure\Catalog\Eloquent\CatalogLifecycleEvent;
use App\Nutrition\Infrastructure\Catalog\Eloquent\EloquentCatalogLifecycleEventStore;
use App\Nutrition\Infrastructure\Catalog\Eloquent\FoodReferenceVersion;
use App\Nutrition\Infrastructure\Catalog\Eloquent\FoodReferenceVersionSource;
use App\Nutrition\Infrastructure\Catalog\Eloquent\FoodSource;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

function atomicServiceM2344(?CatalogLifecycleEventStore $store = null): FoodReferenceVersionLifecycleService
{
    $store ??= new EloquentCatalogLifecycleEventStore;

    return new FoodReferenceVersionLifecycleService(new FoodReferenceVersionLifecyclePolicy, $store, new CatalogLifecycleReplayGuard($store), new CatalogLifecycleRootEventFactory, new CatalogLifecycleProjectionStateResolver);
}

function atomicFixtureM2344(User $actor, array $overrides = []): FoodReferenceVersion
{
    $version = FoodReferenceVersion::factory()->create(array_replace([
        'provenance' => ['origin' => 'curated'], 'energy_basis_grams' => '100.0000',
        'energy_kcal' => '120.0000', 'created_by_user_id' => $actor->id,
    ], $overrides));
    $source = FoodSource::factory()->eligible()->create();
    FoodReferenceVersionSource::factory()->primary()->create([
        'food_reference_version_id' => $version->id, 'food_source_id' => $source->id, 'source_record_key' => 'primary:1',
    ]);

    return $version;
}

function atomicCommandM2344(FoodReferenceVersion $version, User $actor, string $key, ?DateTimeImmutable $occurredAt = null): CatalogLifecycleCommand
{
    return new CatalogLifecycleCommand(CatalogLifecycleSubjectType::ReferenceVersion, $version->public_id, CatalogLifecycleOperation::SubmitForReview, (string) $actor->id, null, $key, $occurredAt ?? new DateTimeImmutable('2026-07-12T17:00:00.123456-03:00'));
}

function atomicContextM2344(User $actor): CatalogLifecycleExecutionContext
{
    return new CatalogLifecycleExecutionContext($actor->id, "audit:user:{$actor->id}");
}

it('returns the original result and event on an equal fingerprint replay without reapplying projection', function () {
    $actor = User::factory()->create();
    $version = atomicFixtureM2344($actor);
    $key = (string) Str::uuid7();
    $command = atomicCommandM2344($version, $actor, $key);
    $service = atomicServiceM2344();
    $first = $service->submitForReview($command, atomicContextM2344($actor));
    $firstSubmittedAt = $version->refresh()->submitted_at;
    $second = $service->submitForReview($command, atomicContextM2344($actor));

    expect($second->replayed)->toBeTrue()
        ->and($second->rootEvent->publicId)->toBe($first->rootEvent->publicId)
        ->and($second->lifecycleResult)->toEqual($first->lifecycleResult)
        ->and($version->refresh()->submitted_at)->toEqual($firstSubmittedAt)
        ->and(CatalogLifecycleEvent::query()->where('idempotency_key', $key)->count())->toBe(1);
});

it('rolls back all changes when a key is reused with a different fingerprint', function () {
    $actor = User::factory()->create();
    $version = atomicFixtureM2344($actor);
    $key = (string) Str::uuid7();
    $service = atomicServiceM2344();
    $service->submitForReview(atomicCommandM2344($version, $actor, $key), atomicContextM2344($actor));

    expect(fn () => $service->submitForReview(
        atomicCommandM2344($version, $actor, $key, new DateTimeImmutable('2026-07-12T18:00:00-03:00')),
        atomicContextM2344($actor),
    ))->toThrow(CatalogLifecycleIdempotencyConflictException::class)
        ->and($version->refresh()->review_status)->toBe(CatalogReviewStatus::PendingReview)
        ->and(CatalogLifecycleEvent::query()->where('idempotency_key', $key)->count())->toBe(1);
});

it('rolls back projection when event persistence fails after the update', function () {
    $actor = User::factory()->create();
    $version = atomicFixtureM2344($actor);
    $store = new class implements CatalogLifecycleEventStore
    {
        public function findRootByIdempotencyKey(string $idempotencyKey): ?CatalogLifecycleStoredEvent
        {
            return null;
        }

        public function storeRoot(CatalogLifecycleEventDraft $event): CatalogLifecycleStoredEvent
        {
            throw new RuntimeException('controlled store failure');
        }

        public function appendDerived(CatalogLifecycleEventDraft $event): CatalogLifecycleStoredEvent
        {
            throw new LogicException('not used');
        }
    };

    expect(fn () => atomicServiceM2344($store)->submitForReview(
        atomicCommandM2344($version, $actor, (string) Str::uuid7()), atomicContextM2344($actor),
    ))->toThrow(RuntimeException::class, 'controlled store failure')
        ->and($version->refresh()->review_status)->toBe(CatalogReviewStatus::Draft)
        ->and($version->submitted_at)->toBeNull();
});

it('commits policy validation events without changing projection and preserves eligibility order', function () {
    $actor = User::factory()->create();
    $version = atomicFixtureM2344($actor, ['canonical_name' => '', 'provenance' => null]);

    $execution = atomicServiceM2344()->submitForReview(
        atomicCommandM2344($version, $actor, (string) Str::uuid7()), atomicContextM2344($actor),
    );

    expect($execution->lifecycleResult->outcome)->toBe(CatalogLifecycleOutcome::ValidationFailed)
        ->and($execution->rootEvent->eligibilityReasons)->toBe($execution->lifecycleResult->eligibility->reasons())
        ->and($version->refresh()->review_status)->toBe(CatalogReviewStatus::Draft);
});

it('rejects actor mismatch before transactions and persists no event', function () {
    $actor = User::factory()->create();
    $other = User::factory()->create();
    $version = atomicFixtureM2344($actor);

    expect(fn () => atomicServiceM2344()->submitForReview(
        atomicCommandM2344($version, $actor, (string) Str::uuid7()), atomicContextM2344($other),
    ))->toThrow(InvalidArgumentException::class)
        ->and(CatalogLifecycleEvent::query()->count())->toBe(0);
});

it('does not audit a missing public subject', function () {
    $actor = User::factory()->create();
    $missing = FoodReferenceVersion::factory()->make(['public_id' => (string) Str::uuid7()]);

    expect(fn () => atomicServiceM2344()->submitForReview(
        atomicCommandM2344($missing, $actor, (string) Str::uuid7()), atomicContextM2344($actor),
    ))->toThrow(CatalogLifecycleSubjectNotFoundException::class)
        ->and(CatalogLifecycleEvent::query()->count())->toBe(0);
});

it('stores event actor semantics and executes the store inside the service transaction', function () {
    $actor = User::factory()->create();
    $version = atomicFixtureM2344($actor);
    $delegate = new EloquentCatalogLifecycleEventStore;
    $store = new class($delegate) implements CatalogLifecycleEventStore
    {
        public bool $storedInsideTransaction = false;

        public function __construct(private EloquentCatalogLifecycleEventStore $delegate) {}

        public function findRootByIdempotencyKey(string $idempotencyKey): ?CatalogLifecycleStoredEvent
        {
            return $this->delegate->findRootByIdempotencyKey($idempotencyKey);
        }

        public function storeRoot(CatalogLifecycleEventDraft $event): CatalogLifecycleStoredEvent
        {
            $this->storedInsideTransaction = DB::transactionLevel() > 0;

            return $this->delegate->storeRoot($event);
        }

        public function appendDerived(CatalogLifecycleEventDraft $event): CatalogLifecycleStoredEvent
        {
            return $this->delegate->appendDerived($event);
        }
    };
    $command = atomicCommandM2344($version, $actor, (string) Str::uuid7());
    $execution = atomicServiceM2344($store)->submitForReview($command, atomicContextM2344($actor));

    expect($store->storedInsideTransaction)->toBeTrue()
        ->and($execution->rootEvent->actorUserId)->toBe($actor->id)
        ->and($execution->rootEvent->actorReference)->toBe("audit:user:{$actor->id}")
        ->and($execution->rootEvent->occurredAt)->toEqual($command->occurredAt)
        ->and($version->refresh()->submitted_at->toDateTimeImmutable())->toEqual($command->occurredAt);
});
