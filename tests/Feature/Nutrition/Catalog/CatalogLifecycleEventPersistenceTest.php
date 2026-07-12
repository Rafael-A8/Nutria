<?php

use App\Models\User;
use App\Nutrition\Application\Catalog\Lifecycle\Exceptions\CatalogLifecycleIdempotencyConflictException;
use App\Nutrition\Application\Catalog\Lifecycle\ValueObjects\CatalogLifecycleEventDraft;
use App\Nutrition\Domain\Catalog\Lifecycle\Enums\CatalogLifecycleOperation;
use App\Nutrition\Domain\Catalog\Lifecycle\Enums\CatalogLifecycleOutcome;
use App\Nutrition\Domain\Catalog\Lifecycle\Enums\CatalogLifecycleReason;
use App\Nutrition\Domain\Catalog\Lifecycle\Enums\CatalogLifecycleState;
use App\Nutrition\Domain\Catalog\Lifecycle\Enums\CatalogLifecycleSubjectType;
use App\Nutrition\Infrastructure\Catalog\Eloquent\CatalogLifecycleEvent;
use App\Nutrition\Infrastructure\Catalog\Eloquent\EloquentCatalogLifecycleEventStore;
use Carbon\CarbonImmutable;
use Database\Factories\Nutrition\Catalog\CatalogLifecycleEventFactory;
use Illuminate\Database\QueryException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/** @param array<string, mixed> $overrides */
function lifecycleRootDraftForM2343(array $overrides = []): CatalogLifecycleEventDraft
{
    return CatalogLifecycleEventDraft::root(...array_replace([
        'subjectType' => CatalogLifecycleSubjectType::ReferenceVersion,
        'subjectInternalId' => 41,
        'subjectPublicId' => (string) Str::uuid7(),
        'operation' => CatalogLifecycleOperation::SubmitForReview,
        'outcome' => CatalogLifecycleOutcome::Succeeded,
        'reasonCode' => CatalogLifecycleReason::TransitionApplied,
        'reason' => 'Editorial review submitted.',
        'previousState' => CatalogLifecycleState::Draft,
        'nextState' => CatalogLifecycleState::PendingReview,
        'eligibilityReasons' => [],
        'actorUserId' => null,
        'actorReference' => 'audit:editor:stable',
        'metadata' => ['source' => ['kind' => 'editorial'], 'sequence' => [2, 1]],
        'occurredAt' => new DateTimeImmutable('2026-07-12T12:34:56.123456-03:00'),
        'idempotencyKey' => (string) Str::uuid7(),
        'commandFingerprint' => hash('sha256', 'catalog-lifecycle-command'),
        'correlationId' => (string) Str::uuid7(),
        'transactionId' => (string) Str::uuid7(),
    ], $overrides));
}

/** @param array<string, mixed> $overrides */
function lifecycleRawEventForM2343(array $overrides = []): array
{
    return array_replace([
        'public_id' => (string) Str::uuid7(),
        'subject_type' => 'reference_version',
        'subject_id' => 99,
        'subject_public_id' => (string) Str::uuid7(),
        'event_type' => 'submit_for_review',
        'outcome' => 'succeeded',
        'reason_code' => 'transition_applied',
        'reason' => null,
        'previous_state' => 'draft',
        'next_state' => 'pending_review',
        'eligibility_reasons' => null,
        'actor_user_id' => null,
        'actor_reference' => 'audit:raw-actor',
        'metadata' => null,
        'occurred_at' => '2026-07-12 15:00:00.123456+00:00',
        'idempotency_key' => (string) Str::uuid7(),
        'command_fingerprint' => hash('sha256', 'raw-command-'.Str::uuid7()),
        'correlation_id' => (string) Str::uuid7(),
        'transaction_id' => (string) Str::uuid7(),
        'created_at' => '2026-07-12 15:00:01.123456+00:00',
    ], $overrides);
}

function expectLifecycleConstraintFailureM2343(Closure $operation, ?string $sqlState = null): QueryException
{
    DB::beginTransaction();

    try {
        $operation();
        DB::rollBack();
    } catch (QueryException $exception) {
        DB::rollBack();

        expect($exception)->toBeInstanceOf(QueryException::class);

        if ($sqlState !== null) {
            expect((string) ($exception->errorInfo[0] ?? $exception->getCode()))->toBe($sqlState);
        }

        return $exception;
    }

    throw new RuntimeException('The database accepted an invalid lifecycle event operation.');
}

it('creates the lifecycle event schema checks indexes and append-only triggers', function () {
    expect(DB::connection()->getDriverName())->toBe('pgsql')
        ->and(Schema::hasTable('catalog_lifecycle_events'))->toBeTrue()
        ->and(Schema::getColumnListing('catalog_lifecycle_events'))->toBe([
            'id', 'public_id', 'subject_type', 'subject_id', 'subject_public_id', 'event_type', 'outcome',
            'reason_code', 'reason', 'previous_state', 'next_state', 'eligibility_reasons', 'actor_user_id',
            'actor_reference', 'metadata', 'occurred_at', 'idempotency_key', 'command_fingerprint',
            'correlation_id', 'transaction_id', 'created_at',
        ])
        ->and(Schema::hasColumn('catalog_lifecycle_events', 'updated_at'))->toBeFalse();

    $indexes = DB::table('pg_indexes')
        ->where('tablename', 'catalog_lifecycle_events')
        ->pluck('indexname')
        ->all();
    $constraints = DB::table('pg_constraint')
        ->where('conrelid', DB::raw("'catalog_lifecycle_events'::regclass"))
        ->pluck('conname')
        ->all();
    $triggers = DB::table('pg_trigger')
        ->where('tgrelid', DB::raw("'catalog_lifecycle_events'::regclass"))
        ->where('tgisinternal', false)
        ->pluck('tgname')
        ->all();

    expect($indexes)->toContain(
        'catalog_lifecycle_events_public_id_unique',
        'catalog_lifecycle_events_root_idempotency_unique',
        'cl_evt_subject_id_occurred_idx',
        'cl_evt_subject_public_occurred_idx',
        'cl_evt_correlation_occurred_idx',
        'cl_evt_transaction_occurred_idx',
        'cl_evt_actor_reference_occurred_idx',
        'cl_evt_outcome_reason_occurred_idx',
    )->and($constraints)->toContain(
        'cl_evt_subject_type_check',
        'cl_evt_event_type_check',
        'cl_evt_outcome_check',
        'cl_evt_reason_code_check',
        'cl_evt_previous_state_check',
        'cl_evt_next_state_check',
        'cl_evt_root_pair_check',
        'cl_evt_fingerprint_check',
        'cl_evt_actor_reference_check',
        'cl_evt_subject_id_positive_check',
        'cl_evt_no_op_states_check',
        'cl_evt_validation_eligibility_check',
    )->and($triggers)->toContain(
        'trg_catalog_lifecycle_events_block_update_delete',
        'trg_catalog_lifecycle_events_block_truncate',
    );
});

it('binds the model factory casts actor and requires explicit event identity', function () {
    $actor = User::factory()->create();
    $event = CatalogLifecycleEvent::factory()
        ->forActor($actor, 'audit:actor:42')
        ->validationFailed()
        ->create()
        ->refresh();

    expect(CatalogLifecycleEvent::factory())->toBeInstanceOf(CatalogLifecycleEventFactory::class)
        ->and($event->subject_type)->toBe(CatalogLifecycleSubjectType::ReferenceVersion)
        ->and($event->event_type)->toBe(CatalogLifecycleOperation::SubmitForReview)
        ->and($event->outcome)->toBe(CatalogLifecycleOutcome::ValidationFailed)
        ->and($event->reason_code)->toBe(CatalogLifecycleReason::IncompleteContent)
        ->and($event->previous_state)->toBe(CatalogLifecycleState::Draft)
        ->and($event->next_state)->toBe(CatalogLifecycleState::Draft)
        ->and($event->occurred_at)->toBeInstanceOf(CarbonImmutable::class)
        ->and($event->created_at)->toBeInstanceOf(CarbonImmutable::class)
        ->and($event->actor->is($actor))->toBeTrue()
        ->and(Str::isUuid($event->public_id, version: 7))->toBeTrue()
        ->and(CatalogLifecycleEvent::factory()->make(['public_id' => null])->public_id)->toBeNull();

    expectLifecycleConstraintFailureM2343(
        fn () => CatalogLifecycleEvent::factory()->create(['public_id' => null]),
    );
});

it('stores a root event without rewriting semantic fields', function () {
    $draft = lifecycleRootDraftForM2343();
    $stored = (new EloquentCatalogLifecycleEventStore)->storeRoot($draft);

    expect($stored->subjectType)->toBe($draft->subjectType)
        ->and($stored->subjectInternalId)->toBe($draft->subjectInternalId)
        ->and($stored->subjectPublicId)->toBe($draft->subjectPublicId)
        ->and($stored->operation)->toBe($draft->operation)
        ->and($stored->outcome)->toBe($draft->outcome)
        ->and($stored->reasonCode)->toBe($draft->reasonCode)
        ->and($stored->reason)->toBe($draft->reason)
        ->and($stored->previousState)->toBe($draft->previousState)
        ->and($stored->nextState)->toBe($draft->nextState)
        ->and($stored->eligibilityReasons)->toBe([])
        ->and($stored->metadata)->toBe($draft->metadata)
        ->and($stored->occurredAt->format('Y-m-d H:i:s.uP'))->toBe($draft->occurredAt->format('Y-m-d H:i:s.uP'))
        ->and($stored->createdAt)->not->toEqual($stored->occurredAt)
        ->and(Str::isUuid($stored->publicId, version: 7))->toBeTrue();
});

it('preserves exact eligibility reason and metadata order', function () {
    $reasons = [
        CatalogLifecycleReason::ParentArchived,
        CatalogLifecycleReason::IncompleteContent,
        CatalogLifecycleReason::ProvenanceIncomplete,
    ];
    $metadata = ['zeta' => ['second', 'first'], 'alpha' => ['nested' => true]];
    $draft = lifecycleRootDraftForM2343([
        'outcome' => CatalogLifecycleOutcome::ValidationFailed,
        'reasonCode' => CatalogLifecycleReason::ParentArchived,
        'previousState' => CatalogLifecycleState::Draft,
        'nextState' => CatalogLifecycleState::Draft,
        'eligibilityReasons' => $reasons,
        'metadata' => $metadata,
    ]);

    $stored = (new EloquentCatalogLifecycleEventStore)->storeRoot($draft);

    expect($stored->eligibilityReasons)->toBe($reasons)
        ->and($stored->metadata)->toBe($metadata)
        ->and($stored->toLifecycleResult()->eligibility->reasons())->toBe($reasons);
});

it('replays equal root fingerprints and rejects different fingerprints deterministically', function () {
    $store = new EloquentCatalogLifecycleEventStore;
    $draft = lifecycleRootDraftForM2343();
    $first = $store->storeRoot($draft);
    $replayed = $store->storeRoot($draft);

    expect($replayed->internalId)->toBe($first->internalId)
        ->and($replayed->publicId)->toBe($first->publicId)
        ->and(CatalogLifecycleEvent::query()->where('idempotency_key', $draft->idempotencyKey)->count())->toBe(1);

    try {
        $store->storeRoot(lifecycleRootDraftForM2343([
            'idempotencyKey' => $draft->idempotencyKey,
            'commandFingerprint' => hash('sha256', 'different-command'),
        ]));
    } catch (CatalogLifecycleIdempotencyConflictException $exception) {
        expect($exception->reason)->toBe(CatalogLifecycleReason::IdempotencyKeyReused)
            ->and($exception->getMessage())->not->toContain('insert', 'SQL', $draft->idempotencyKey);

        return;
    }

    throw new RuntimeException('A reused idempotency key did not produce a typed conflict.');
});

it('appends multiple correlated derived events without deduplication', function () {
    $root = lifecycleRootDraftForM2343();
    $derived = CatalogLifecycleEventDraft::derived(
        subjectType: CatalogLifecycleSubjectType::Alias,
        subjectInternalId: 77,
        subjectPublicId: (string) Str::uuid7(),
        operation: CatalogLifecycleOperation::CreateDraft,
        outcome: CatalogLifecycleOutcome::Succeeded,
        reasonCode: CatalogLifecycleReason::DraftCreated,
        reason: null,
        previousState: null,
        nextState: CatalogLifecycleState::Draft,
        eligibilityReasons: [],
        actorUserId: null,
        actorReference: $root->actorReference,
        metadata: [],
        occurredAt: $root->occurredAt,
        correlationId: $root->correlationId,
        transactionId: $root->transactionId,
    );
    $store = new EloquentCatalogLifecycleEventStore;

    $storedRoot = $store->storeRoot($root);
    $firstDerived = $store->appendDerived($derived);
    $secondDerived = $store->appendDerived($derived);

    expect($firstDerived->idempotencyKey)->toBeNull()
        ->and($firstDerived->commandFingerprint)->toBeNull()
        ->and($firstDerived->correlationId)->toBe($storedRoot->correlationId)
        ->and($firstDerived->transactionId)->toBe($storedRoot->transactionId)
        ->and($firstDerived->occurredAt)->toEqual($storedRoot->occurredAt)
        ->and($secondDerived->internalId)->not->toBe($firstDerived->internalId)
        ->and(CatalogLifecycleEvent::query()->whereNull('idempotency_key')->count())->toBe(2);
});

it('reconstructs success no-op validation failure and conflict results', function () {
    $store = new EloquentCatalogLifecycleEventStore;
    $events = [
        lifecycleRootDraftForM2343(),
        lifecycleRootDraftForM2343([
            'outcome' => CatalogLifecycleOutcome::NoOp,
            'reasonCode' => CatalogLifecycleReason::AlreadyPendingReview,
            'previousState' => CatalogLifecycleState::PendingReview,
            'nextState' => CatalogLifecycleState::PendingReview,
        ]),
        lifecycleRootDraftForM2343([
            'outcome' => CatalogLifecycleOutcome::ValidationFailed,
            'reasonCode' => CatalogLifecycleReason::IncompleteContent,
            'previousState' => CatalogLifecycleState::Draft,
            'nextState' => CatalogLifecycleState::Draft,
            'eligibilityReasons' => [
                CatalogLifecycleReason::IncompleteContent,
                CatalogLifecycleReason::ProvenanceIncomplete,
            ],
        ]),
        lifecycleRootDraftForM2343([
            'outcome' => CatalogLifecycleOutcome::Conflict,
            'reasonCode' => CatalogLifecycleReason::NumberConflict,
            'previousState' => CatalogLifecycleState::Approved,
            'nextState' => CatalogLifecycleState::Approved,
        ]),
    ];

    $results = array_map(
        fn (CatalogLifecycleEventDraft $event) => $store->storeRoot($event)->toLifecycleResult(),
        $events,
    );

    expect(array_map(fn ($result) => $result->outcome, $results))->toBe([
        CatalogLifecycleOutcome::Succeeded,
        CatalogLifecycleOutcome::NoOp,
        CatalogLifecycleOutcome::ValidationFailed,
        CatalogLifecycleOutcome::Conflict,
    ])->and($results[1]->previousState)->toBe($results[1]->nextState)
        ->and($results[2]->eligibility->reasons())->toBe([
            CatalogLifecycleReason::IncompleteContent,
            CatalogLifecycleReason::ProvenanceIncomplete,
        ])
        ->and($results[3]->reason)->toBe(CatalogLifecycleReason::NumberConflict);
});

it('blocks direct PostgreSQL update delete and truncate with the custom SQLSTATE', function () {
    $event = CatalogLifecycleEvent::factory()->create();

    expectLifecycleConstraintFailureM2343(
        fn () => DB::table('catalog_lifecycle_events')->where('id', $event->id)->update(['reason' => 'changed']),
        'N3401',
    );
    expectLifecycleConstraintFailureM2343(
        fn () => DB::table('catalog_lifecycle_events')->where('id', $event->id)->delete(),
        'N3401',
    );
    expectLifecycleConstraintFailureM2343(
        fn () => DB::statement('TRUNCATE TABLE catalog_lifecycle_events'),
        'N3401',
    );
});

it('allows only actor nullification and preserves the event when its user is deleted', function () {
    $firstActor = User::factory()->create();
    $secondActor = User::factory()->create();
    $actorReference = 'audit:actor:stable-after-deletion';
    $event = CatalogLifecycleEvent::factory()
        ->forActor($firstActor, $actorReference)
        ->validationFailed()
        ->create();
    $eventWithoutActor = CatalogLifecycleEvent::factory()->create();

    expectLifecycleConstraintFailureM2343(
        fn () => DB::table('catalog_lifecycle_events')->where('id', $event->id)->update([
            'actor_reference' => 'audit:actor:changed',
        ]),
        'N3401',
    );
    expectLifecycleConstraintFailureM2343(
        fn () => DB::table('catalog_lifecycle_events')->where('id', $event->id)->update([
            'actor_user_id' => $secondActor->id,
        ]),
        'N3401',
    );
    expectLifecycleConstraintFailureM2343(
        fn () => DB::table('catalog_lifecycle_events')->where('id', $eventWithoutActor->id)->update([
            'actor_user_id' => $firstActor->id,
        ]),
        'N3401',
    );

    $beforeExplicitNullification = get_object_vars(DB::table('catalog_lifecycle_events')->find($event->id));

    expect(DB::table('catalog_lifecycle_events')->where('id', $event->id)->update([
        'actor_user_id' => null,
    ]))->toBe(1);

    $afterExplicitNullification = get_object_vars(DB::table('catalog_lifecycle_events')->find($event->id));

    expect($afterExplicitNullification['actor_user_id'])->toBeNull()
        ->and(Arr::except($afterExplicitNullification, 'actor_user_id'))
        ->toBe(Arr::except($beforeExplicitNullification, 'actor_user_id'));

    $eventNullifiedByForeignKey = CatalogLifecycleEvent::factory()
        ->forActor($firstActor, $actorReference)
        ->create();
    $beforeUserDeletion = get_object_vars(DB::table('catalog_lifecycle_events')->find($eventNullifiedByForeignKey->id));

    $firstActor->delete();

    $afterUserDeletion = get_object_vars(DB::table('catalog_lifecycle_events')->find($eventNullifiedByForeignKey->id));

    expect(CatalogLifecycleEvent::query()->whereKey($eventNullifiedByForeignKey->id)->exists())->toBeTrue()
        ->and($afterUserDeletion['actor_user_id'])->toBeNull()
        ->and($afterUserDeletion['actor_reference'])->toBe($actorReference)
        ->and(Arr::except($afterUserDeletion, 'actor_user_id'))
        ->toBe(Arr::except($beforeUserDeletion, 'actor_user_id'));
});

it('enforces root pairing identity state and eligibility checks', function () {
    $duplicateKey = (string) Str::uuid7();
    DB::table('catalog_lifecycle_events')->insert(lifecycleRawEventForM2343([
        'idempotency_key' => $duplicateKey,
    ]));

    expectLifecycleConstraintFailureM2343(fn () => DB::table('catalog_lifecycle_events')->insert(
        lifecycleRawEventForM2343(['idempotency_key' => $duplicateKey]),
    ));
    expectLifecycleConstraintFailureM2343(fn () => DB::table('catalog_lifecycle_events')->insert(
        lifecycleRawEventForM2343(['command_fingerprint' => null]),
    ));
    expectLifecycleConstraintFailureM2343(fn () => DB::table('catalog_lifecycle_events')->insert(
        lifecycleRawEventForM2343(['command_fingerprint' => str_repeat('A', 64)]),
    ));
    expectLifecycleConstraintFailureM2343(fn () => DB::table('catalog_lifecycle_events')->insert(
        lifecycleRawEventForM2343(['actor_reference' => '   ']),
    ));
    expectLifecycleConstraintFailureM2343(fn () => DB::table('catalog_lifecycle_events')->insert(
        lifecycleRawEventForM2343(['subject_id' => 0]),
    ));
    expectLifecycleConstraintFailureM2343(fn () => DB::table('catalog_lifecycle_events')->insert(
        lifecycleRawEventForM2343([
            'outcome' => 'no_op',
            'reason_code' => 'already_active',
            'previous_state' => 'active',
            'next_state' => 'deactivated',
        ]),
    ));
    expectLifecycleConstraintFailureM2343(fn () => DB::table('catalog_lifecycle_events')->insert(
        lifecycleRawEventForM2343([
            'outcome' => 'validation_failed',
            'reason_code' => 'incomplete_content',
            'previous_state' => 'draft',
            'next_state' => 'draft',
            'eligibility_reasons' => null,
        ]),
    ));
});

it('enforces every PostgreSQL lifecycle vocabulary', function (string $column, string $invalidValue) {
    expectLifecycleConstraintFailureM2343(fn () => DB::table('catalog_lifecycle_events')->insert(
        lifecycleRawEventForM2343([$column => $invalidValue]),
    ));
})->with([
    ['subject_type', 'meal'],
    ['event_type', 'calculate'],
    ['outcome', 'failed'],
    ['reason_code', 'sql_error'],
    ['previous_state', 'deleted'],
    ['next_state', 'restored'],
]);
