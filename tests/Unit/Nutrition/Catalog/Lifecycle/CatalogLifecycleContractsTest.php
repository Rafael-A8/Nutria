<?php

use App\Nutrition\Domain\Catalog\Lifecycle\Contracts\CatalogLifecycleSnapshot;
use App\Nutrition\Domain\Catalog\Lifecycle\Enums\AliasKind;
use App\Nutrition\Domain\Catalog\Lifecycle\Enums\CatalogLifecycleOperation;
use App\Nutrition\Domain\Catalog\Lifecycle\Enums\CatalogLifecycleOutcome;
use App\Nutrition\Domain\Catalog\Lifecycle\Enums\CatalogLifecycleReason;
use App\Nutrition\Domain\Catalog\Lifecycle\Enums\CatalogLifecycleState;
use App\Nutrition\Domain\Catalog\Lifecycle\Enums\CatalogLifecycleSubjectType;
use App\Nutrition\Domain\Catalog\Lifecycle\ValueObjects\CatalogEligibilityResult;
use App\Nutrition\Domain\Catalog\Lifecycle\ValueObjects\CatalogLifecycleCommand;
use App\Nutrition\Domain\Catalog\Lifecycle\ValueObjects\CatalogLifecycleResult;
use App\Nutrition\Domain\Catalog\Lifecycle\ValueObjects\FoodAliasLifecycleSnapshot;
use App\Nutrition\Domain\Catalog\Lifecycle\ValueObjects\FoodPortionLifecycleSnapshot;
use App\Nutrition\Domain\Catalog\Lifecycle\ValueObjects\FoodReferenceLifecycleSnapshot;
use App\Nutrition\Domain\Catalog\Lifecycle\ValueObjects\FoodReferenceVersionLifecycleSnapshot;
use App\Nutrition\Domain\Catalog\Lifecycle\ValueObjects\FoodSourceLifecycleSnapshot;

function lifecycleCommandForM2342(
    CatalogLifecycleOperation $operation,
    ?string $reason = null,
    string $subjectId = 'subject-1',
    string $actorId = 'actor-1',
    string $idempotencyKey = '018f1f2e-7b2a-7c4d-8e9f-0123456789ab',
): CatalogLifecycleCommand {
    return new CatalogLifecycleCommand(
        subjectType: CatalogLifecycleSubjectType::ReferenceVersion,
        subjectId: $subjectId,
        operation: $operation,
        actorId: $actorId,
        reason: $reason,
        idempotencyKey: $idempotencyKey,
        occurredAt: new DateTimeImmutable('2026-07-12T12:00:00-03:00'),
    );
}

it('defines the exact lifecycle enum vocabularies', function () {
    expect(array_column(AliasKind::cases(), 'value'))->toBe(['common', 'generic', 'regional', 'brand'])
        ->and(array_column(CatalogLifecycleState::cases(), 'value'))->toBe([
            'available', 'draft', 'pending_review', 'approved', 'rejected', 'published_inactive', 'active', 'deactivated', 'withdrawn', 'archived',
        ])->and(array_column(CatalogLifecycleSubjectType::cases(), 'value'))->toBe([
            'source', 'reference', 'reference_version', 'alias', 'portion',
        ])->and(array_column(CatalogLifecycleOperation::cases(), 'value'))->toBe([
            'create_source', 'edit_source', 'create_reference', 'create_draft', 'edit_draft', 'submit_for_review', 'return_to_draft', 'approve', 'reject', 'publish', 'activate', 'reactivate', 'deactivate', 'withdraw', 'archive', 'create_successor', 'change_authority',
        ])->and(array_column(CatalogLifecycleOutcome::cases(), 'value'))->toBe([
            'succeeded', 'no_op', 'invalid_transition', 'validation_failed', 'conflict',
        ]);
});

it('contains every frozen lifecycle reason without human-facing descriptions', function () {
    $values = array_column(CatalogLifecycleReason::cases(), 'value');

    expect($values)->toContain(
        'transition_applied', 'source_created', 'source_updated', 'reference_created', 'draft_created', 'draft_updated', 'successor_created',
        'already_pending_review', 'already_approved', 'already_published', 'already_active', 'already_deactivated', 'already_withdrawn', 'already_archived',
        'invalid_transition', 'content_frozen', 'terminal_rejected', 'terminal_withdrawn', 'terminal_archived',
        'source_prohibited', 'source_ineligible', 'source_scope_mismatch', 'concept_incompatible', 'self_approval_prohibited',
        'parent_mismatch', 'lineage_mismatch', 'not_lineage_head', 'number_conflict', 'active_version_conflict', 'catalog_integrity_violation',
    );

    foreach ($values as $value) {
        expect($value)->toMatch('/^[a-z][a-z0-9_]*$/');
    }
});

it('requires reasons only for governed operations', function (CatalogLifecycleOperation $operation) {
    expect(fn () => lifecycleCommandForM2342($operation))->toThrow(InvalidArgumentException::class);
})->with([
    CatalogLifecycleOperation::ReturnToDraft,
    CatalogLifecycleOperation::Approve,
    CatalogLifecycleOperation::Reject,
    CatalogLifecycleOperation::Publish,
    CatalogLifecycleOperation::Activate,
    CatalogLifecycleOperation::Reactivate,
    CatalogLifecycleOperation::Deactivate,
    CatalogLifecycleOperation::Withdraw,
    CatalogLifecycleOperation::Archive,
    CatalogLifecycleOperation::CreateSuccessor,
    CatalogLifecycleOperation::ChangeAuthority,
]);

it('allows optional reasons for creation editing and submission operations', function (CatalogLifecycleOperation $operation) {
    expect(lifecycleCommandForM2342($operation))->toBeInstanceOf(CatalogLifecycleCommand::class);
})->with([
    CatalogLifecycleOperation::CreateSource,
    CatalogLifecycleOperation::EditSource,
    CatalogLifecycleOperation::CreateReference,
    CatalogLifecycleOperation::CreateDraft,
    CatalogLifecycleOperation::EditDraft,
    CatalogLifecycleOperation::SubmitForReview,
]);

it('rejects invalid command identity fields', function (string $subjectId, string $actorId, string $idempotencyKey) {
    expect(fn () => lifecycleCommandForM2342(
        CatalogLifecycleOperation::CreateDraft,
        subjectId: $subjectId,
        actorId: $actorId,
        idempotencyKey: $idempotencyKey,
    ))->toThrow(InvalidArgumentException::class);
})->with([
    'blank subject' => ['  ', 'actor', '018f1f2e-7b2a-7c4d-8e9f-0123456789ab'],
    'blank actor' => ['subject', '  ', '018f1f2e-7b2a-7c4d-8e9f-0123456789ab'],
    'non UUID' => ['subject', 'actor', 'not-a-uuid'],
    'uppercase noncanonical UUID' => ['subject', 'actor', '018F1F2E-7B2A-7C4D-8E9F-0123456789AB'],
]);

it('rejects untrimmed or blank optional reasons', function (string $reason) {
    expect(fn () => lifecycleCommandForM2342(CatalogLifecycleOperation::CreateDraft, $reason))
        ->toThrow(InvalidArgumentException::class);
})->with(['', '  ', ' needs trimming ']);

it('requires an explicit immutable occurrence time', function () {
    $constructor = (new ReflectionClass(CatalogLifecycleCommand::class))->getConstructor();
    $occurredAt = collect($constructor?->getParameters())->first(
        fn (ReflectionParameter $parameter): bool => $parameter->getName() === 'occurredAt',
    );

    expect($occurredAt)->not->toBeNull()
        ->and($occurredAt->getType()?->getName())->toBe(DateTimeImmutable::class)
        ->and($occurredAt->isDefaultValueAvailable())->toBeFalse();
});

it('preserves ordered eligibility reasons and rejects duplicates', function () {
    $reasons = [CatalogLifecycleReason::ParentArchived, CatalogLifecycleReason::IncompleteContent];
    $result = CatalogEligibilityResult::ineligible($reasons);

    expect($result->isEligible())->toBeFalse()
        ->and($result->reasons())->toBe($reasons)
        ->and($result->firstReason())->toBe(CatalogLifecycleReason::ParentArchived)
        ->and(CatalogEligibilityResult::eligible()->reasons())->toBe([])
        ->and(fn () => CatalogEligibilityResult::ineligible([$reasons[0], $reasons[0]]))->toThrow(InvalidArgumentException::class);
});

it('builds semantically consistent typed lifecycle results', function () {
    $eligibility = CatalogEligibilityResult::ineligible([CatalogLifecycleReason::IncompleteContent]);
    $results = [
        CatalogLifecycleResult::succeeded(CatalogLifecycleReason::DraftCreated, null, CatalogLifecycleState::Draft),
        CatalogLifecycleResult::noOp(CatalogLifecycleReason::AlreadyApproved, CatalogLifecycleState::Approved),
        CatalogLifecycleResult::invalid(CatalogLifecycleReason::InvalidTransition, CatalogLifecycleState::Draft),
        CatalogLifecycleResult::validationFailed(CatalogLifecycleReason::IncompleteContent, CatalogLifecycleState::Draft, $eligibility),
        CatalogLifecycleResult::conflict(CatalogLifecycleReason::NumberConflict, CatalogLifecycleState::Approved),
    ];

    expect(array_map(fn (CatalogLifecycleResult $result): CatalogLifecycleOutcome => $result->outcome, $results))->toBe([
        CatalogLifecycleOutcome::Succeeded,
        CatalogLifecycleOutcome::NoOp,
        CatalogLifecycleOutcome::InvalidTransition,
        CatalogLifecycleOutcome::ValidationFailed,
        CatalogLifecycleOutcome::Conflict,
    ])->and(fn () => CatalogLifecycleResult::noOp(CatalogLifecycleReason::InvalidTransition, CatalogLifecycleState::Draft))
        ->toThrow(InvalidArgumentException::class);
});

it('keeps concrete value objects final readonly and snapshots entity specific', function () {
    $classes = [
        CatalogLifecycleCommand::class,
        CatalogEligibilityResult::class,
        CatalogLifecycleResult::class,
        FoodReferenceVersionLifecycleSnapshot::class,
        FoodAliasLifecycleSnapshot::class,
        FoodPortionLifecycleSnapshot::class,
        FoodSourceLifecycleSnapshot::class,
        FoodReferenceLifecycleSnapshot::class,
    ];

    foreach ($classes as $class) {
        $reflection = new ReflectionClass($class);
        expect($reflection->isFinal())->toBeTrue()->and($reflection->isReadOnly())->toBeTrue();
    }

    foreach (array_slice($classes, 3) as $snapshot) {
        expect(is_subclass_of($snapshot, CatalogLifecycleSnapshot::class))->toBeTrue();
    }

    expect((new ReflectionClass(CatalogLifecycleSnapshot::class))->getMethods())->toHaveCount(4);
});
