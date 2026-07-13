<?php

use App\Models\User;
use App\Nutrition\Application\Catalog\Lifecycle\CatalogLifecycleDerivedEventFactory;
use App\Nutrition\Application\Catalog\Lifecycle\CatalogLifecycleProjectionStateResolver;
use App\Nutrition\Application\Catalog\Lifecycle\CatalogLifecycleReplayGuard;
use App\Nutrition\Application\Catalog\Lifecycle\CatalogLifecycleRootEventFactory;
use App\Nutrition\Application\Catalog\Lifecycle\Contracts\CatalogLifecycleEventStore;
use App\Nutrition\Application\Catalog\Lifecycle\FoodPortionSupersessionService;
use App\Nutrition\Application\Catalog\Lifecycle\ValueObjects\CatalogLifecycleEventDraft;
use App\Nutrition\Application\Catalog\Lifecycle\ValueObjects\CatalogLifecycleExecutionContext;
use App\Nutrition\Application\Catalog\Lifecycle\ValueObjects\CatalogLifecycleStoredEvent;
use App\Nutrition\Domain\Catalog\Enums\CatalogReviewStatus;
use App\Nutrition\Domain\Catalog\Lifecycle\Enums\CatalogLifecycleOperation;
use App\Nutrition\Domain\Catalog\Lifecycle\Enums\CatalogLifecycleReason;
use App\Nutrition\Domain\Catalog\Lifecycle\Enums\CatalogLifecycleState;
use App\Nutrition\Domain\Catalog\Lifecycle\Enums\CatalogLifecycleSubjectType;
use App\Nutrition\Domain\Catalog\Lifecycle\Policies\FoodPortionLifecyclePolicy;
use App\Nutrition\Domain\Catalog\Lifecycle\ValueObjects\CatalogLifecycleCommand;
use App\Nutrition\Infrastructure\Catalog\Eloquent\CatalogLifecycleEvent;
use App\Nutrition\Infrastructure\Catalog\Eloquent\EloquentCatalogLifecycleEventStore;
use App\Nutrition\Infrastructure\Catalog\Eloquent\FoodPortion;
use App\Nutrition\Infrastructure\Catalog\Eloquent\FoodReference;
use App\Nutrition\Infrastructure\Catalog\Eloquent\FoodSource;
use Illuminate\Support\Str;

function portionSupersessionServiceM2346(?CatalogLifecycleEventStore $store = null): FoodPortionSupersessionService
{
    $store ??= new EloquentCatalogLifecycleEventStore;

    return new FoodPortionSupersessionService(
        new FoodPortionLifecyclePolicy,
        $store,
        new CatalogLifecycleReplayGuard($store),
        new CatalogLifecycleRootEventFactory,
        new CatalogLifecycleDerivedEventFactory,
        new CatalogLifecycleProjectionStateResolver,
    );
}

function portionContextM2346(User $actor): CatalogLifecycleExecutionContext
{
    return new CatalogLifecycleExecutionContext($actor->id, "audit:user:{$actor->id}");
}

function portionCommandM2346(FoodPortion $subject, User $actor, CatalogLifecycleOperation $operation, ?string $key = null): CatalogLifecycleCommand
{
    return new CatalogLifecycleCommand(
        CatalogLifecycleSubjectType::Portion,
        $subject->public_id,
        $operation,
        (string) $actor->id,
        $operation === CatalogLifecycleOperation::CreateSuccessor ? 'Create portion successor.' : 'Replace active portion.',
        $key ?? (string) Str::uuid7(),
        new DateTimeImmutable('2026-07-13T12:00:00.123456-03:00'),
    );
}

function portionPredecessorM2346(User $actor, CatalogLifecycleState $state = CatalogLifecycleState::Approved): FoodPortion
{
    $source = FoodSource::factory()->eligible()->create();
    $portion = FoodPortion::factory()->create([
        'food_reference_id' => FoodReference::factory()->create()->id,
        'portion_key' => 'medium-cup',
        'display_label' => '1 xícara média',
        'normalized_label' => '1 xicara media',
        'locale' => 'pt-BR',
        'portion_type' => 'household',
        'unit_code' => 'cup',
        'unit_quantity' => '1.2500',
        'gram_weight' => '137.7500',
        'preparation_key' => 'any',
        'size_label' => 'medium',
        'food_source_id' => $source->id,
        'source_record_key' => 'portion:cup:1',
        'provenance' => ['measurement' => 'laboratory'],
        'created_by_user_id' => $actor->id,
    ]);
    $attributes = match ($state) {
        CatalogLifecycleState::Approved => ['review_status' => 'approved', 'reviewed_at' => now()],
        CatalogLifecycleState::Rejected => ['review_status' => 'rejected', 'reviewed_at' => now()],
        CatalogLifecycleState::Active => ['review_status' => 'approved', 'reviewed_at' => now(), 'published_at' => now(), 'activated_at' => now()],
        default => throw new InvalidArgumentException('Unsupported portion fixture state.'),
    };
    $portion->forceFill($attributes)->save();

    return $portion->refresh();
}

/** @param array<string, mixed> $overrides */
function publishedPortionSuccessorM2346(User $actor, FoodPortion $predecessor, array $overrides = []): FoodPortion
{
    return FoodPortion::factory()->published()->create(array_merge([
        'lineage_id' => $predecessor->lineage_id,
        'food_reference_id' => $predecessor->food_reference_id,
        'revision_number' => $predecessor->revision_number + 1,
        'supersedes_food_portion_id' => $predecessor->id,
        'portion_key' => $predecessor->portion_key,
        'display_label' => $predecessor->display_label,
        'normalized_label' => $predecessor->normalized_label,
        'locale' => $predecessor->locale,
        'portion_type' => $predecessor->portion_type,
        'unit_code' => $predecessor->unit_code,
        'unit_quantity' => $predecessor->unit_quantity,
        'gram_weight' => $predecessor->gram_weight,
        'preparation_key' => $predecessor->preparation_key,
        'size_label' => $predecessor->size_label,
        'food_source_id' => $predecessor->food_source_id,
        'source_record_key' => $predecessor->source_record_key,
        'provenance' => $predecessor->provenance,
        'created_by_user_id' => $actor->id,
    ], $overrides));
}

it('creates a contiguous portion successor with exact evidence and no conversion or serving defaults', function (CatalogLifecycleState $state) {
    $actor = User::factory()->create();
    $predecessor = portionPredecessorM2346($actor, $state);
    $before = $predecessor->getAttributes();

    $result = portionSupersessionServiceM2346()->createSuccessor(
        portionCommandM2346($predecessor, $actor, CatalogLifecycleOperation::CreateSuccessor),
        portionContextM2346($actor),
    );
    $successor = FoodPortion::query()->where('public_id', $result->successorPublicId)->firstOrFail();
    $events = CatalogLifecycleEvent::query()->where('correlation_id', $result->execution->rootEvent->correlationId)->orderBy('id')->get();
    $copiedFields = [
        'portion_key', 'display_label', 'normalized_label', 'locale', 'portion_type', 'unit_code',
        'unit_quantity', 'gram_weight', 'preparation_key', 'size_label', 'food_source_id',
        'source_record_key', 'provenance',
    ];

    expect($result->wasCreated())->toBeTrue()
        ->and($successor->lineage_id)->toBe($predecessor->lineage_id)
        ->and($successor->revision_number)->toBe($predecessor->revision_number + 1)
        ->and($successor->supersedes_food_portion_id)->toBe($predecessor->id)
        ->and($successor->only($copiedFields))->toBe($predecessor->only($copiedFields))
        ->and($successor->unit_quantity)->toBe('1.2500')
        ->and($successor->gram_weight)->toBe('137.7500')
        ->and($successor->size_label)->toBe('medium')
        ->and($successor->review_status)->toBe(CatalogReviewStatus::Draft)
        ->and($successor->reviewed_at)->toBeNull()
        ->and($successor->published_at)->toBeNull()
        ->and($successor->activated_at)->toBeNull()
        ->and($predecessor->refresh()->getAttributes())->toBe($before)
        ->and($events)->toHaveCount(2)
        ->and($events[0]->previous_state)->toBe($state)
        ->and($events[0]->next_state)->toBe($state)
        ->and($events[1]->event_type)->toBe(CatalogLifecycleOperation::CreateDraft)
        ->and($events[1]->correlation_id)->toBe($events[0]->correlation_id)
        ->and($events[1]->transaction_id)->toBe($events[0]->transaction_id);
})->with([
    'approved' => CatalogLifecycleState::Approved,
    'rejected' => CatalogLifecycleState::Rejected,
]);

it('replays portion creation and returns the duplicate successor conflict for another key', function () {
    $actor = User::factory()->create();
    $predecessor = portionPredecessorM2346($actor);
    $service = portionSupersessionServiceM2346();
    $command = portionCommandM2346($predecessor, $actor, CatalogLifecycleOperation::CreateSuccessor, (string) Str::uuid7());
    $first = $service->createSuccessor($command, portionContextM2346($actor));
    $replay = $service->createSuccessor($command, portionContextM2346($actor));
    $conflict = $service->createSuccessor(
        portionCommandM2346($predecessor, $actor, CatalogLifecycleOperation::CreateSuccessor),
        portionContextM2346($actor),
    );

    expect($replay->wasReplayed())->toBeTrue()
        ->and($replay->successorPublicId)->toBe($first->successorPublicId)
        ->and($conflict->execution->lifecycleResult->reason)->toBe(CatalogLifecycleReason::SuccessorExists)
        ->and(FoodPortion::query()->where('supersedes_food_portion_id', $predecessor->id)->count())->toBe(1)
        ->and(CatalogLifecycleEvent::query()->where('event_type', CatalogLifecycleOperation::CreateDraft)->count())->toBe(1);
});

it('atomically replaces the direct active portion and replays without duplicate deactivation', function () {
    $actor = User::factory()->create();
    $predecessor = portionPredecessorM2346($actor, CatalogLifecycleState::Active);
    $successor = publishedPortionSuccessorM2346($actor, $predecessor);
    $command = portionCommandM2346($successor, $actor, CatalogLifecycleOperation::Activate, (string) Str::uuid7());
    $service = portionSupersessionServiceM2346();
    $first = $service->activateSuccessorReplacingCurrent($command, portionContextM2346($actor));
    $replay = $service->activateSuccessorReplacingCurrent($command, portionContextM2346($actor));

    expect($first->deactivatedSubjectPublicId)->toBe($predecessor->public_id)
        ->and($replay->wasReplayed())->toBeTrue()
        ->and($predecessor->refresh()->deactivated_at->toDateTimeImmutable())->toEqual($command->occurredAt)
        ->and($successor->refresh()->activated_at->toDateTimeImmutable())->toEqual($command->occurredAt)
        ->and(CatalogLifecycleEvent::query()->where('event_type', CatalogLifecycleOperation::Deactivate)->count())->toBe(1);
});

it('revalidates portion preparation evidence and source eligibility before replacement', function (string $scenario, CatalogLifecycleReason $reason) {
    $actor = User::factory()->create();
    $predecessor = portionPredecessorM2346($actor, CatalogLifecycleState::Active);
    $overrides = match ($scenario) {
        'preparation' => ['preparation_key' => 'nonexistent-preparation'],
        'source' => [
            'food_source_id' => FoodSource::factory()->untrusted()->create()->id,
            'source_record_key' => 'untrusted:portion',
        ],
    };
    $successor = publishedPortionSuccessorM2346($actor, $predecessor, $overrides);

    $result = portionSupersessionServiceM2346()->activateSuccessorReplacingCurrent(
        portionCommandM2346($successor, $actor, CatalogLifecycleOperation::Activate),
        portionContextM2346($actor),
    );

    expect($result->execution->lifecycleResult->eligibility->reasons())->toContain($reason)
        ->and($predecessor->refresh()->deactivated_at)->toBeNull()
        ->and($successor->refresh()->activated_at)->toBeNull();
})->with([
    'invalid preparation evidence' => ['preparation', CatalogLifecycleReason::PortionEvidenceInvalid],
    'source ineligible' => ['source', CatalogLifecycleReason::SourceIneligible],
]);

it('rolls back portion creation and replacement when derived event persistence fails', function (string $workflow) {
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
            throw new RuntimeException('controlled portion derived failure');
        }
    };

    if ($workflow === 'replacement') {
        $predecessor = portionPredecessorM2346($actor, CatalogLifecycleState::Active);
        $successor = publishedPortionSuccessorM2346($actor, $predecessor);
        expect(fn () => portionSupersessionServiceM2346($store)->activateSuccessorReplacingCurrent(
            portionCommandM2346($successor, $actor, CatalogLifecycleOperation::Activate),
            portionContextM2346($actor),
        ))->toThrow(RuntimeException::class)
            ->and($predecessor->refresh()->deactivated_at)->toBeNull()
            ->and($successor->refresh()->activated_at)->toBeNull();

        return;
    }

    $predecessor = portionPredecessorM2346($actor);
    expect(fn () => portionSupersessionServiceM2346($store)->createSuccessor(
        portionCommandM2346($predecessor, $actor, CatalogLifecycleOperation::CreateSuccessor),
        portionContextM2346($actor),
    ))->toThrow(RuntimeException::class)
        ->and(FoodPortion::query()->where('supersedes_food_portion_id', $predecessor->id)->count())->toBe(0)
        ->and(CatalogLifecycleEvent::query()->count())->toBe(0);
})->with(['creation', 'replacement']);
