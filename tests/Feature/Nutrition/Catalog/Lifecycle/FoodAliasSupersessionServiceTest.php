<?php

use App\Models\User;
use App\Nutrition\Application\Catalog\Lifecycle\CatalogLifecycleDerivedEventFactory;
use App\Nutrition\Application\Catalog\Lifecycle\CatalogLifecycleProjectionStateResolver;
use App\Nutrition\Application\Catalog\Lifecycle\CatalogLifecycleReplayGuard;
use App\Nutrition\Application\Catalog\Lifecycle\CatalogLifecycleRootEventFactory;
use App\Nutrition\Application\Catalog\Lifecycle\Contracts\CatalogLifecycleEventStore;
use App\Nutrition\Application\Catalog\Lifecycle\FoodAliasSupersessionService;
use App\Nutrition\Application\Catalog\Lifecycle\ValueObjects\CatalogLifecycleEventDraft;
use App\Nutrition\Application\Catalog\Lifecycle\ValueObjects\CatalogLifecycleExecutionContext;
use App\Nutrition\Application\Catalog\Lifecycle\ValueObjects\CatalogLifecycleStoredEvent;
use App\Nutrition\Domain\Catalog\Enums\CatalogReviewStatus;
use App\Nutrition\Domain\Catalog\Lifecycle\Enums\CatalogLifecycleOperation;
use App\Nutrition\Domain\Catalog\Lifecycle\Enums\CatalogLifecycleReason;
use App\Nutrition\Domain\Catalog\Lifecycle\Enums\CatalogLifecycleState;
use App\Nutrition\Domain\Catalog\Lifecycle\Enums\CatalogLifecycleSubjectType;
use App\Nutrition\Domain\Catalog\Lifecycle\Policies\FoodAliasLifecyclePolicy;
use App\Nutrition\Domain\Catalog\Lifecycle\ValueObjects\CatalogLifecycleCommand;
use App\Nutrition\Infrastructure\Catalog\Eloquent\CatalogLifecycleEvent;
use App\Nutrition\Infrastructure\Catalog\Eloquent\EloquentCatalogLifecycleEventStore;
use App\Nutrition\Infrastructure\Catalog\Eloquent\FoodAlias;
use App\Nutrition\Infrastructure\Catalog\Eloquent\FoodReference;
use App\Nutrition\Infrastructure\Catalog\Eloquent\FoodSource;
use Illuminate\Support\Str;

function aliasSupersessionServiceM2346(?CatalogLifecycleEventStore $store = null): FoodAliasSupersessionService
{
    $store ??= new EloquentCatalogLifecycleEventStore;

    return new FoodAliasSupersessionService(
        new FoodAliasLifecyclePolicy,
        $store,
        new CatalogLifecycleReplayGuard($store),
        new CatalogLifecycleRootEventFactory,
        new CatalogLifecycleDerivedEventFactory,
        new CatalogLifecycleProjectionStateResolver,
    );
}

function aliasContextM2346(User $actor): CatalogLifecycleExecutionContext
{
    return new CatalogLifecycleExecutionContext($actor->id, "audit:user:{$actor->id}");
}

function aliasCommandM2346(FoodAlias $subject, User $actor, CatalogLifecycleOperation $operation, ?string $key = null): CatalogLifecycleCommand
{
    return new CatalogLifecycleCommand(
        CatalogLifecycleSubjectType::Alias,
        $subject->public_id,
        $operation,
        (string) $actor->id,
        $operation === CatalogLifecycleOperation::CreateSuccessor ? 'Create alias successor.' : 'Replace active alias.',
        $key ?? (string) Str::uuid7(),
        new DateTimeImmutable('2026-07-13T11:00:00.123456-03:00'),
    );
}

function aliasPredecessorM2346(
    User $actor,
    CatalogLifecycleState $state = CatalogLifecycleState::Approved,
    bool $genericReference = false,
): FoodAlias {
    $source = FoodSource::factory()->eligible()->create();
    $reference = $genericReference
        ? FoodReference::factory()->generic()->create()
        : FoodReference::factory()->create();
    $alias = FoodAlias::factory()->create([
        'food_reference_id' => $reference->id,
        'display_alias' => 'Arroz integral cozido',
        'normalized_alias' => 'arroz integral cozido',
        'locale' => 'pt-BR',
        'alias_kind' => 'common',
        'food_source_id' => $source->id,
        'source_record_key' => 'alias:rice:1',
        'provenance' => ['curator' => 'catalog'],
        'created_by_user_id' => $actor->id,
    ]);
    $attributes = match ($state) {
        CatalogLifecycleState::Approved => ['review_status' => 'approved', 'reviewed_at' => now()],
        CatalogLifecycleState::Rejected => ['review_status' => 'rejected', 'reviewed_at' => now()],
        CatalogLifecycleState::Active => ['review_status' => 'approved', 'reviewed_at' => now(), 'published_at' => now(), 'activated_at' => now()],
        default => throw new InvalidArgumentException('Unsupported alias fixture state.'),
    };
    $alias->forceFill($attributes)->save();

    return $alias->refresh();
}

/** @param array<string, mixed> $overrides */
function publishedAliasSuccessorM2346(User $actor, FoodAlias $predecessor, array $overrides = []): FoodAlias
{
    return FoodAlias::factory()->published()->create(array_merge([
        'lineage_id' => $predecessor->lineage_id,
        'food_reference_id' => $predecessor->food_reference_id,
        'revision_number' => $predecessor->revision_number + 1,
        'supersedes_food_alias_id' => $predecessor->id,
        'display_alias' => $predecessor->display_alias,
        'normalized_alias' => $predecessor->normalized_alias,
        'locale' => $predecessor->locale,
        'alias_kind' => $predecessor->alias_kind,
        'food_source_id' => $predecessor->food_source_id,
        'source_record_key' => $predecessor->source_record_key,
        'provenance' => $predecessor->provenance,
        'created_by_user_id' => $actor->id,
    ], $overrides));
}

it('creates a contiguous alias successor with copied content reset lifecycle and correlated events', function (CatalogLifecycleState $state) {
    $actor = User::factory()->create();
    $predecessor = aliasPredecessorM2346($actor, $state);
    $before = $predecessor->getAttributes();

    $result = aliasSupersessionServiceM2346()->createSuccessor(
        aliasCommandM2346($predecessor, $actor, CatalogLifecycleOperation::CreateSuccessor),
        aliasContextM2346($actor),
    );
    $successor = FoodAlias::query()->where('public_id', $result->successorPublicId)->firstOrFail();
    $events = CatalogLifecycleEvent::query()->where('correlation_id', $result->execution->rootEvent->correlationId)->orderBy('id')->get();

    expect($result->wasCreated())->toBeTrue()
        ->and($successor->lineage_id)->toBe($predecessor->lineage_id)
        ->and($successor->food_reference_id)->toBe($predecessor->food_reference_id)
        ->and($successor->revision_number)->toBe($predecessor->revision_number + 1)
        ->and($successor->supersedes_food_alias_id)->toBe($predecessor->id)
        ->and($successor->only(['display_alias', 'normalized_alias', 'locale', 'alias_kind', 'food_source_id', 'source_record_key', 'provenance']))
        ->toBe($predecessor->only(['display_alias', 'normalized_alias', 'locale', 'alias_kind', 'food_source_id', 'source_record_key', 'provenance']))
        ->and($successor->review_status)->toBe(CatalogReviewStatus::Draft)
        ->and($successor->submitted_at)->toBeNull()
        ->and($successor->reviewed_at)->toBeNull()
        ->and($successor->published_at)->toBeNull()
        ->and($successor->activated_at)->toBeNull()
        ->and($successor->created_by_user_id)->toBe($actor->id)
        ->and($predecessor->refresh()->getAttributes())->toBe($before)
        ->and($events)->toHaveCount(2)
        ->and($events[0]->previous_state)->toBe($state)
        ->and($events[0]->next_state)->toBe($state)
        ->and($events[0]->metadata)->toBe(['successor_public_id' => $successor->public_id])
        ->and($events[1]->event_type)->toBe(CatalogLifecycleOperation::CreateDraft)
        ->and($events[1]->metadata)->toBe(['predecessor_public_id' => $predecessor->public_id])
        ->and($events[1]->correlation_id)->toBe($events[0]->correlation_id)
        ->and($events[1]->transaction_id)->toBe($events[0]->transaction_id);
})->with([
    'approved' => CatalogLifecycleState::Approved,
    'rejected' => CatalogLifecycleState::Rejected,
]);

it('replays alias creation and reports a deterministic duplicate successor conflict', function () {
    $actor = User::factory()->create();
    $predecessor = aliasPredecessorM2346($actor);
    $service = aliasSupersessionServiceM2346();
    $command = aliasCommandM2346($predecessor, $actor, CatalogLifecycleOperation::CreateSuccessor, (string) Str::uuid7());
    $first = $service->createSuccessor($command, aliasContextM2346($actor));
    $replay = $service->createSuccessor($command, aliasContextM2346($actor));
    $conflict = $service->createSuccessor(
        aliasCommandM2346($predecessor, $actor, CatalogLifecycleOperation::CreateSuccessor),
        aliasContextM2346($actor),
    );

    expect($replay->wasReplayed())->toBeTrue()
        ->and($replay->successorPublicId)->toBe($first->successorPublicId)
        ->and($conflict->execution->lifecycleResult->reason)->toBe(CatalogLifecycleReason::SuccessorExists)
        ->and(FoodAlias::query()->where('supersedes_food_alias_id', $predecessor->id)->count())->toBe(1)
        ->and(CatalogLifecycleEvent::query()->where('event_type', CatalogLifecycleOperation::CreateDraft)->count())->toBe(1);
});

it('replaces only the direct active alias predecessor and replays exactly once', function () {
    $actor = User::factory()->create();
    $predecessor = aliasPredecessorM2346($actor, CatalogLifecycleState::Active);
    $successor = publishedAliasSuccessorM2346($actor, $predecessor);
    $command = aliasCommandM2346($successor, $actor, CatalogLifecycleOperation::Activate, (string) Str::uuid7());
    $service = aliasSupersessionServiceM2346();
    $first = $service->activateSuccessorReplacingCurrent($command, aliasContextM2346($actor));
    $replay = $service->activateSuccessorReplacingCurrent($command, aliasContextM2346($actor));

    expect($first->deactivatedSubjectPublicId)->toBe($predecessor->public_id)
        ->and($replay->wasReplayed())->toBeTrue()
        ->and($predecessor->refresh()->deactivated_at->toDateTimeImmutable())->toEqual($command->occurredAt)
        ->and($successor->refresh()->activated_at->toDateTimeImmutable())->toEqual($command->occurredAt)
        ->and(CatalogLifecycleEvent::query()->where('event_type', CatalogLifecycleOperation::Deactivate)->count())->toBe(1);
});

it('revalidates generic brand and source alias eligibility without changing projections', function (string $scenario, CatalogLifecycleReason $reason) {
    $actor = User::factory()->create();
    $predecessor = aliasPredecessorM2346(
        $actor,
        CatalogLifecycleState::Active,
        genericReference: $scenario === 'brand',
    );
    $overrides = match ($scenario) {
        'generic' => ['alias_kind' => 'generic'],
        'brand' => ['alias_kind' => 'brand'],
        'source' => [
            'food_source_id' => FoodSource::factory()->untrusted()->create()->id,
            'source_record_key' => 'untrusted:alias',
        ],
    };
    $successor = publishedAliasSuccessorM2346($actor, $predecessor, $overrides);

    $result = aliasSupersessionServiceM2346()->activateSuccessorReplacingCurrent(
        aliasCommandM2346($successor, $actor, CatalogLifecycleOperation::Activate),
        aliasContextM2346($actor),
    );

    expect($result->execution->lifecycleResult->eligibility->reasons())->toContain($reason)
        ->and($predecessor->refresh()->deactivated_at)->toBeNull()
        ->and($successor->refresh()->activated_at)->toBeNull();
})->with([
    'generic mismatch' => ['generic', CatalogLifecycleReason::GenericAliasReferenceMismatch],
    'brand mismatch' => ['brand', CatalogLifecycleReason::BrandAliasGenericReferenceMismatch],
    'source ineligible' => ['source', CatalogLifecycleReason::SourceIneligible],
]);

it('rolls back alias creation and replacement when derived event persistence fails', function (string $workflow) {
    $actor = User::factory()->create();
    $delegate = new EloquentCatalogLifecycleEventStore;
    $store = new class($delegate) implements CatalogLifecycleEventStore
    {
        public function __construct(private EloquentCatalogLifecycleEventStore $delegate) {}

        public function findRootByIdempotencyKey(string $idempotencyKey): ?CatalogLifecycleStoredEvent
        {
            return $this->delegate->findRootByIdempotencyKey($idempotencyKey);
        }

        public function storeRoot(CatalogLifecycleEventDraft $event): CatalogLifecycleStoredEvent
        {
            return $this->delegate->storeRoot($event);
        }

        public function appendDerived(CatalogLifecycleEventDraft $event): CatalogLifecycleStoredEvent
        {
            throw new RuntimeException('controlled alias derived failure');
        }
    };

    if ($workflow === 'replacement') {
        $predecessor = aliasPredecessorM2346($actor, CatalogLifecycleState::Active);
        $successor = publishedAliasSuccessorM2346($actor, $predecessor);
        expect(fn () => aliasSupersessionServiceM2346($store)->activateSuccessorReplacingCurrent(
            aliasCommandM2346($successor, $actor, CatalogLifecycleOperation::Activate),
            aliasContextM2346($actor),
        ))->toThrow(RuntimeException::class)
            ->and($predecessor->refresh()->deactivated_at)->toBeNull()
            ->and($successor->refresh()->activated_at)->toBeNull();

        return;
    }

    $predecessor = aliasPredecessorM2346($actor);
    expect(fn () => aliasSupersessionServiceM2346($store)->createSuccessor(
        aliasCommandM2346($predecessor, $actor, CatalogLifecycleOperation::CreateSuccessor),
        aliasContextM2346($actor),
    ))->toThrow(RuntimeException::class)
        ->and(FoodAlias::query()->where('supersedes_food_alias_id', $predecessor->id)->count())->toBe(0)
        ->and(CatalogLifecycleEvent::query()->count())->toBe(0);
})->with(['creation', 'replacement']);
