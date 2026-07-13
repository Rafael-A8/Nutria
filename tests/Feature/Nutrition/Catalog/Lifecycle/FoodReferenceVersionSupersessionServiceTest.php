<?php

use App\Models\User;
use App\Nutrition\Application\Catalog\Lifecycle\CatalogLifecycleDerivedEventFactory;
use App\Nutrition\Application\Catalog\Lifecycle\CatalogLifecycleProjectionStateResolver;
use App\Nutrition\Application\Catalog\Lifecycle\CatalogLifecycleReplayGuard;
use App\Nutrition\Application\Catalog\Lifecycle\CatalogLifecycleRootEventFactory;
use App\Nutrition\Application\Catalog\Lifecycle\Contracts\CatalogLifecycleEventStore;
use App\Nutrition\Application\Catalog\Lifecycle\FoodReferenceVersionSupersessionService;
use App\Nutrition\Application\Catalog\Lifecycle\ValueObjects\CatalogLifecycleEventDraft;
use App\Nutrition\Application\Catalog\Lifecycle\ValueObjects\CatalogLifecycleExecutionContext;
use App\Nutrition\Application\Catalog\Lifecycle\ValueObjects\CatalogLifecycleStoredEvent;
use App\Nutrition\Domain\Catalog\Enums\CatalogReviewStatus;
use App\Nutrition\Domain\Catalog\Lifecycle\Enums\CatalogLifecycleOperation;
use App\Nutrition\Domain\Catalog\Lifecycle\Enums\CatalogLifecycleOutcome;
use App\Nutrition\Domain\Catalog\Lifecycle\Enums\CatalogLifecycleReason;
use App\Nutrition\Domain\Catalog\Lifecycle\Enums\CatalogLifecycleState;
use App\Nutrition\Domain\Catalog\Lifecycle\Enums\CatalogLifecycleSubjectType;
use App\Nutrition\Domain\Catalog\Lifecycle\Policies\FoodReferenceVersionLifecyclePolicy;
use App\Nutrition\Domain\Catalog\Lifecycle\ValueObjects\CatalogLifecycleCommand;
use App\Nutrition\Infrastructure\Catalog\Eloquent\CatalogLifecycleEvent;
use App\Nutrition\Infrastructure\Catalog\Eloquent\EloquentCatalogLifecycleEventStore;
use App\Nutrition\Infrastructure\Catalog\Eloquent\FoodReference;
use App\Nutrition\Infrastructure\Catalog\Eloquent\FoodReferenceVersion;
use App\Nutrition\Infrastructure\Catalog\Eloquent\FoodReferenceVersionSource;
use App\Nutrition\Infrastructure\Catalog\Eloquent\FoodSource;
use Illuminate\Support\Str;

function versionSupersessionServiceM2346(?CatalogLifecycleEventStore $store = null): FoodReferenceVersionSupersessionService
{
    $store ??= new EloquentCatalogLifecycleEventStore;

    return new FoodReferenceVersionSupersessionService(
        new FoodReferenceVersionLifecyclePolicy,
        $store,
        new CatalogLifecycleReplayGuard($store),
        new CatalogLifecycleRootEventFactory,
        new CatalogLifecycleDerivedEventFactory,
        new CatalogLifecycleProjectionStateResolver,
    );
}

function versionSupersessionContextM2346(User $actor): CatalogLifecycleExecutionContext
{
    return new CatalogLifecycleExecutionContext($actor->id, "audit:user:{$actor->id}");
}

function versionSupersessionCommandM2346(
    FoodReferenceVersion $subject,
    User $actor,
    CatalogLifecycleOperation $operation,
    ?string $key = null,
): CatalogLifecycleCommand {
    return new CatalogLifecycleCommand(
        CatalogLifecycleSubjectType::ReferenceVersion,
        $subject->public_id,
        $operation,
        (string) $actor->id,
        $operation === CatalogLifecycleOperation::CreateSuccessor ? 'Create governed successor.' : 'Replace active predecessor.',
        $key ?? (string) Str::uuid7(),
        new DateTimeImmutable('2026-07-13T10:00:00.123456-03:00'),
    );
}

/** @return array<string, mixed> */
function versionStateAttributesM2346(CatalogLifecycleState $state): array
{
    return match ($state) {
        CatalogLifecycleState::Draft => [],
        CatalogLifecycleState::PendingReview => ['review_status' => 'pending_review', 'submitted_at' => now()],
        CatalogLifecycleState::Approved => ['review_status' => 'approved', 'reviewed_at' => now()],
        CatalogLifecycleState::Rejected => ['review_status' => 'rejected', 'reviewed_at' => now()],
        CatalogLifecycleState::PublishedInactive => ['review_status' => 'approved', 'reviewed_at' => now(), 'published_at' => now()],
        CatalogLifecycleState::Active => ['review_status' => 'approved', 'reviewed_at' => now(), 'published_at' => now(), 'activated_at' => now()],
        CatalogLifecycleState::Deactivated => ['review_status' => 'approved', 'reviewed_at' => now(), 'published_at' => now(), 'activated_at' => now(), 'deactivated_at' => now()],
        CatalogLifecycleState::Withdrawn => ['review_status' => 'approved', 'reviewed_at' => now(), 'published_at' => now(), 'withdrawn_at' => now()],
        CatalogLifecycleState::Archived => ['archived_at' => now()],
        default => throw new InvalidArgumentException('Unsupported version fixture state.'),
    };
}

function versionPredecessorM2346(User $actor, CatalogLifecycleState $state = CatalogLifecycleState::Approved): FoodReferenceVersion
{
    $reference = FoodReference::factory()->create();
    $version = FoodReferenceVersion::factory()->create([
        'food_reference_id' => $reference->id,
        'canonical_name' => 'Feijão carioca cozido',
        'normalized_canonical_name' => 'feijao carioca cozido',
        'locale' => 'pt-BR',
        'classification' => 'legume',
        'preparation_key' => 'cooked',
        'energy_basis_grams' => '100.0000',
        'energy_kcal' => '76.5000',
        'nutrient_values' => ['protein_grams' => 4.8],
        'provenance' => ['source' => 'curated'],
        'created_by_user_id' => $actor->id,
    ]);
    $primary = FoodSource::factory()->eligible()->create();
    $supporting = FoodSource::factory()->eligible()->create();
    FoodReferenceVersionSource::factory()->primary()->create([
        'food_reference_version_id' => $version->id,
        'food_source_id' => $primary->id,
        'source_record_key' => 'primary:beans',
        'evidence_metadata' => ['page' => 10],
    ]);
    FoodReferenceVersionSource::factory()->create([
        'food_reference_version_id' => $version->id,
        'food_source_id' => $supporting->id,
        'source_record_key' => 'supporting:beans',
        'evidence_metadata' => ['page' => 11],
    ]);

    if ($state !== CatalogLifecycleState::Draft) {
        $version->forceFill(versionStateAttributesM2346($state))->save();
    }

    return $version->refresh();
}

function publishedVersionSuccessorM2346(
    User $actor,
    FoodReferenceVersion $predecessor,
    bool $eligibleSource = true,
    bool $directSuccessor = true,
): FoodReferenceVersion {
    $successor = FoodReferenceVersion::factory()->withNutrition()->create([
        'food_reference_id' => $predecessor->food_reference_id,
        'version_number' => $predecessor->version_number + 1,
        'supersedes_food_reference_version_id' => $directSuccessor ? $predecessor->id : null,
        'canonical_name' => $predecessor->canonical_name,
        'normalized_canonical_name' => $predecessor->normalized_canonical_name,
        'locale' => $predecessor->locale,
        'classification' => $predecessor->classification,
        'preparation_key' => $predecessor->preparation_key,
        'provenance' => $predecessor->provenance,
        'created_by_user_id' => $actor->id,
    ]);
    $source = $eligibleSource
        ? FoodSource::factory()->eligible()->create()
        : FoodSource::factory()->untrusted()->create();
    FoodReferenceVersionSource::factory()->primary()->create([
        'food_reference_version_id' => $successor->id,
        'food_source_id' => $source->id,
        'source_record_key' => 'primary:successor',
    ]);
    $successor->forceFill([
        'review_status' => CatalogReviewStatus::Approved,
        'reviewed_at' => now(),
        'published_at' => now(),
    ])->save();

    return $successor->refresh();
}

it('creates an exact draft version successor with reset lifecycle source copies and correlated events', function () {
    $actor = User::factory()->create();
    $predecessor = versionPredecessorM2346($actor);
    $predecessorBefore = $predecessor->getAttributes();
    $command = versionSupersessionCommandM2346($predecessor, $actor, CatalogLifecycleOperation::CreateSuccessor);

    $result = versionSupersessionServiceM2346()->createSuccessor($command, versionSupersessionContextM2346($actor));
    $successor = FoodReferenceVersion::query()->where('public_id', $result->successorPublicId)->firstOrFail();
    $events = CatalogLifecycleEvent::query()->where('correlation_id', $result->execution->rootEvent->correlationId)->orderBy('id')->get();
    $resetLifecycleValues = array_values($successor->only([
        'submitted_at', 'submitted_by_user_id', 'reviewed_at', 'reviewed_by_user_id', 'review_reason',
        'published_at', 'published_by_user_id', 'activated_at', 'activated_by_user_id',
        'deactivated_at', 'deactivated_by_user_id', 'deactivation_reason', 'withdrawn_at',
        'withdrawn_by_user_id', 'withdrawal_reason', 'archived_at', 'archived_by_user_id', 'archive_reason',
    ]));

    expect($result->wasCreated())->toBeTrue()
        ->and(Str::isUuid($successor->public_id, 7))->toBeTrue()
        ->and($successor->food_reference_id)->toBe($predecessor->food_reference_id)
        ->and($successor->version_number)->toBe($predecessor->version_number + 1)
        ->and($successor->supersedes_food_reference_version_id)->toBe($predecessor->id)
        ->and($successor->only([
            'canonical_name', 'normalized_canonical_name', 'locale', 'classification', 'preparation_key',
            'energy_basis_grams', 'energy_kcal', 'nutrient_values', 'provenance',
        ]))->toBe($predecessor->only([
            'canonical_name', 'normalized_canonical_name', 'locale', 'classification', 'preparation_key',
            'energy_basis_grams', 'energy_kcal', 'nutrient_values', 'provenance',
        ]))
        ->and($successor->review_status)->toBe(CatalogReviewStatus::Draft)
        ->and($resetLifecycleValues)->each->toBeNull()
        ->and($successor->created_by_user_id)->toBe($actor->id)
        ->and($successor->sourceLinks()->count())->toBe(2)
        ->and($successor->sourceLinks()->orderBy('food_source_id')->get()->map->only([
            'food_source_id', 'role', 'source_record_key', 'evidence_metadata',
        ])->all())->toBe($predecessor->sourceLinks()->orderBy('food_source_id')->get()->map->only([
            'food_source_id', 'role', 'source_record_key', 'evidence_metadata',
        ])->all())
        ->and($predecessor->refresh()->getAttributes())->toBe($predecessorBefore)
        ->and($events)->toHaveCount(2)
        ->and($events[0]->subject_id)->toBe($predecessor->id)
        ->and($events[0]->event_type)->toBe(CatalogLifecycleOperation::CreateSuccessor)
        ->and($events[0]->previous_state)->toBe(CatalogLifecycleState::Approved)
        ->and($events[0]->next_state)->toBe(CatalogLifecycleState::Approved)
        ->and($events[0]->metadata)->toBe(['successor_public_id' => $successor->public_id])
        ->and($events[1]->subject_id)->toBe($successor->id)
        ->and($events[1]->event_type)->toBe(CatalogLifecycleOperation::CreateDraft)
        ->and($events[1]->previous_state)->toBeNull()
        ->and($events[1]->next_state)->toBe(CatalogLifecycleState::Draft)
        ->and($events[1]->idempotency_key)->toBeNull()
        ->and($events[1]->command_fingerprint)->toBeNull()
        ->and($events[1]->transaction_id)->toBe($events[0]->transaction_id)
        ->and($events[1]->occurred_at)->toEqual($events[0]->occurred_at);
});

it('creates version successors from every allowed predecessor state without deactivating the predecessor', function (CatalogLifecycleState $state) {
    $actor = User::factory()->create();
    $predecessor = versionPredecessorM2346($actor, $state);
    $deactivatedAt = $predecessor->deactivated_at;

    $result = versionSupersessionServiceM2346()->createSuccessor(
        versionSupersessionCommandM2346($predecessor, $actor, CatalogLifecycleOperation::CreateSuccessor),
        versionSupersessionContextM2346($actor),
    );

    expect($result->execution->lifecycleResult->outcome)->toBe(CatalogLifecycleOutcome::Succeeded)
        ->and($result->execution->lifecycleResult->previousState)->toBe($state)
        ->and($result->execution->lifecycleResult->nextState)->toBe($state)
        ->and($predecessor->refresh()->deactivated_at)->toEqual($deactivatedAt);
})->with([
    CatalogLifecycleState::Approved,
    CatalogLifecycleState::Rejected,
    CatalogLifecycleState::PublishedInactive,
    CatalogLifecycleState::Active,
    CatalogLifecycleState::Deactivated,
    CatalogLifecycleState::Withdrawn,
]);

it('audits blocked version successor states without creating a row', function (CatalogLifecycleState $state) {
    $actor = User::factory()->create();
    $predecessor = versionPredecessorM2346($actor, $state);

    $result = versionSupersessionServiceM2346()->createSuccessor(
        versionSupersessionCommandM2346($predecessor, $actor, CatalogLifecycleOperation::CreateSuccessor),
        versionSupersessionContextM2346($actor),
    );

    expect($result->hasSuccessor())->toBeFalse()
        ->and($result->execution->lifecycleResult->outcome)->toBe(CatalogLifecycleOutcome::InvalidTransition)
        ->and(FoodReferenceVersion::query()->where('supersedes_food_reference_version_id', $predecessor->id)->count())->toBe(0)
        ->and(CatalogLifecycleEvent::query()->where('idempotency_key', $result->execution->rootEvent->idempotencyKey)->count())->toBe(1);
})->with([
    CatalogLifecycleState::Draft,
    CatalogLifecycleState::PendingReview,
    CatalogLifecycleState::Archived,
]);

it('replays creation to the original successor and returns a conflict for another key', function () {
    $actor = User::factory()->create();
    $predecessor = versionPredecessorM2346($actor);
    $service = versionSupersessionServiceM2346();
    $key = (string) Str::uuid7();
    $command = versionSupersessionCommandM2346($predecessor, $actor, CatalogLifecycleOperation::CreateSuccessor, $key);
    $first = $service->createSuccessor($command, versionSupersessionContextM2346($actor));
    $replay = $service->createSuccessor($command, versionSupersessionContextM2346($actor));
    $conflict = $service->createSuccessor(
        versionSupersessionCommandM2346($predecessor, $actor, CatalogLifecycleOperation::CreateSuccessor),
        versionSupersessionContextM2346($actor),
    );

    expect($replay->wasReplayed())->toBeTrue()
        ->and($replay->successorPublicId)->toBe($first->successorPublicId)
        ->and($conflict->execution->lifecycleResult->reason)->toBe(CatalogLifecycleReason::SuccessorExists)
        ->and(FoodReferenceVersion::query()->where('supersedes_food_reference_version_id', $predecessor->id)->count())->toBe(1)
        ->and(CatalogLifecycleEvent::query()->where('reason_code', CatalogLifecycleReason::DraftCreated)->count())->toBe(1);
});

it('atomically replaces the direct active version and replays without another derived event', function () {
    $actor = User::factory()->create();
    $predecessor = versionPredecessorM2346($actor, CatalogLifecycleState::Active);
    $successor = publishedVersionSuccessorM2346($actor, $predecessor);
    $key = (string) Str::uuid7();
    $command = versionSupersessionCommandM2346($successor, $actor, CatalogLifecycleOperation::Activate, $key);
    $service = versionSupersessionServiceM2346();
    $first = $service->activateSuccessorReplacingCurrent($command, versionSupersessionContextM2346($actor));
    $replay = $service->activateSuccessorReplacingCurrent($command, versionSupersessionContextM2346($actor));
    $events = CatalogLifecycleEvent::query()->where('correlation_id', $first->execution->rootEvent->correlationId)->orderBy('id')->get();

    expect($first->wasReplaced())->toBeTrue()
        ->and($first->deactivatedSubjectPublicId)->toBe($predecessor->public_id)
        ->and($replay->wasReplayed())->toBeTrue()
        ->and($predecessor->refresh()->deactivated_at->toDateTimeImmutable())->toEqual($command->occurredAt)
        ->and($predecessor->deactivation_reason)->toBe($command->reason)
        ->and($successor->refresh()->activated_at->toDateTimeImmutable())->toEqual($command->occurredAt)
        ->and($events)->toHaveCount(2)
        ->and($events[0]->metadata)->toBe(['replaced_subject_public_id' => $predecessor->public_id])
        ->and($events[1]->event_type)->toBe(CatalogLifecycleOperation::Deactivate)
        ->and($events[1]->metadata)->toBe(['replacement_subject_public_id' => $successor->public_id])
        ->and(CatalogLifecycleEvent::query()->where('event_type', CatalogLifecycleOperation::Deactivate)->where('subject_id', $predecessor->id)->count())->toBe(1);
});

it('rejects ineligible or unrelated active replacement without projection changes', function (string $scenario, CatalogLifecycleReason $reason) {
    $actor = User::factory()->create();
    $predecessor = versionPredecessorM2346(
        $actor,
        $scenario === 'unrelated' ? CatalogLifecycleState::Approved : CatalogLifecycleState::Active,
    );
    $successor = publishedVersionSuccessorM2346(
        $actor,
        $predecessor,
        eligibleSource: $scenario !== 'source',
    );

    if ($scenario === 'unrelated') {
        $unrelatedActive = FoodReferenceVersion::factory()->withNutrition()->create([
            'food_reference_id' => $predecessor->food_reference_id,
            'version_number' => $predecessor->version_number + 2,
            'supersedes_food_reference_version_id' => null,
            'created_by_user_id' => $actor->id,
        ]);
        $source = FoodSource::factory()->eligible()->create();
        FoodReferenceVersionSource::factory()->primary()->create([
            'food_reference_version_id' => $unrelatedActive->id,
            'food_source_id' => $source->id,
        ]);
        $unrelatedActive->forceFill([
            'review_status' => CatalogReviewStatus::Approved,
            'reviewed_at' => now(),
            'published_at' => now(),
            'activated_at' => now(),
        ])->save();
    }

    $result = versionSupersessionServiceM2346()->activateSuccessorReplacingCurrent(
        versionSupersessionCommandM2346($successor, $actor, CatalogLifecycleOperation::Activate),
        versionSupersessionContextM2346($actor),
    );

    expect($result->execution->lifecycleResult->reason)->toBe($reason)
        ->and($predecessor->refresh()->deactivated_at)->toBeNull()
        ->and($successor->refresh()->activated_at)->toBeNull()
        ->and(CatalogLifecycleEvent::query()->where('idempotency_key', $result->execution->rootEvent->idempotencyKey)->count())->toBe(1);
})->with([
    'ineligible source' => ['source', CatalogLifecycleReason::SourceIneligible],
    'unrelated active' => ['unrelated', CatalogLifecycleReason::ActiveVersionConflict],
]);

it('rolls back successor rows links root events and replacement projections when event persistence fails', function (string $failurePoint) {
    $actor = User::factory()->create();
    $delegate = new EloquentCatalogLifecycleEventStore;
    $store = new class($delegate, $failurePoint) implements CatalogLifecycleEventStore
    {
        public function __construct(
            private EloquentCatalogLifecycleEventStore $delegate,
            private string $failurePoint,
        ) {}

        public function findRootByIdempotencyKey(string $idempotencyKey): ?CatalogLifecycleStoredEvent
        {
            return $this->delegate->findRootByIdempotencyKey($idempotencyKey);
        }

        public function storeRoot(CatalogLifecycleEventDraft $event): CatalogLifecycleStoredEvent
        {
            if ($this->failurePoint === 'root') {
                throw new RuntimeException('controlled root failure');
            }

            return $this->delegate->storeRoot($event);
        }

        public function appendDerived(CatalogLifecycleEventDraft $event): CatalogLifecycleStoredEvent
        {
            throw new RuntimeException('controlled derived failure');
        }
    };

    if ($failurePoint === 'replacement') {
        $predecessor = versionPredecessorM2346($actor, CatalogLifecycleState::Active);
        $successor = publishedVersionSuccessorM2346($actor, $predecessor);

        expect(fn () => versionSupersessionServiceM2346($store)->activateSuccessorReplacingCurrent(
            versionSupersessionCommandM2346($successor, $actor, CatalogLifecycleOperation::Activate),
            versionSupersessionContextM2346($actor),
        ))->toThrow(RuntimeException::class, 'controlled derived failure')
            ->and($predecessor->refresh()->deactivated_at)->toBeNull()
            ->and($successor->refresh()->activated_at)->toBeNull()
            ->and(CatalogLifecycleEvent::query()->count())->toBe(0);

        return;
    }

    $predecessor = versionPredecessorM2346($actor);

    expect(fn () => versionSupersessionServiceM2346($store)->createSuccessor(
        versionSupersessionCommandM2346($predecessor, $actor, CatalogLifecycleOperation::CreateSuccessor),
        versionSupersessionContextM2346($actor),
    ))->toThrow(RuntimeException::class)
        ->and(FoodReferenceVersion::query()->where('supersedes_food_reference_version_id', $predecessor->id)->count())->toBe(0)
        ->and(FoodReferenceVersionSource::query()->where('food_reference_version_id', '!=', $predecessor->id)->count())->toBe(0)
        ->and(CatalogLifecycleEvent::query()->count())->toBe(0);
})->with(['root', 'derived', 'replacement']);
