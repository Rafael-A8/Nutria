<?php

use App\Nutrition\Domain\Catalog\Lifecycle\Enums\CatalogLifecycleOperation;
use App\Nutrition\Domain\Catalog\Lifecycle\Enums\CatalogLifecycleOutcome;
use App\Nutrition\Domain\Catalog\Lifecycle\Enums\CatalogLifecycleReason;
use App\Nutrition\Domain\Catalog\Lifecycle\Enums\CatalogLifecycleState;
use App\Nutrition\Domain\Catalog\Lifecycle\Enums\CatalogLifecycleSubjectType;
use App\Nutrition\Domain\Catalog\Lifecycle\Policies\FoodPortionLifecyclePolicy;
use App\Nutrition\Domain\Catalog\Lifecycle\ValueObjects\CatalogLifecycleCommand;
use App\Nutrition\Domain\Catalog\Lifecycle\ValueObjects\FoodPortionLifecycleSnapshot;

function portionCommandForM2342(CatalogLifecycleOperation $operation): CatalogLifecycleCommand
{
    return new CatalogLifecycleCommand(
        CatalogLifecycleSubjectType::Portion,
        'portion-1',
        $operation,
        'actor-1',
        'governed reason',
        '018f1f2e-7b2a-7c4d-8e9f-2123456789ab',
        new DateTimeImmutable('2026-07-12T12:00:00-03:00'),
    );
}

function portionSnapshotForM2342(CatalogLifecycleState $state, array $overrides = []): FoodPortionLifecycleSnapshot
{
    return new FoodPortionLifecycleSnapshot(...array_replace([
        'subjectId' => 'portion-1',
        'exists' => true,
        'state' => $state,
        'contentComplete' => true,
        'localePresent' => true,
        'provenanceComplete' => true,
        'hasPositiveUnitQuantity' => true,
        'hasPositiveGramWeight' => true,
        'preparationApplicabilityValid' => true,
        'sourcePresent' => true,
        'sourceEligible' => true,
        'sourceRecordKeyPresent' => true,
        'sourceScopeCompatible' => true,
        'isLineageHead' => true,
        'successorParentMatches' => true,
        'successorLineageMatches' => true,
        'successorNumberIsContiguous' => true,
    ], $overrides));
}

it('supports the portion draft lifecycle without quantity conversion', function () {
    $policy = new FoodPortionLifecyclePolicy;
    $new = new FoodPortionLifecycleSnapshot('portion-1', false, null);

    expect($policy->evaluate(portionCommandForM2342(CatalogLifecycleOperation::CreateDraft), $new))->reason->toBe(CatalogLifecycleReason::DraftCreated)
        ->and($policy->evaluate(portionCommandForM2342(CatalogLifecycleOperation::EditDraft), portionSnapshotForM2342(CatalogLifecycleState::Draft)))->reason->toBe(CatalogLifecycleReason::DraftUpdated)
        ->and($policy->evaluate(portionCommandForM2342(CatalogLifecycleOperation::SubmitForReview), portionSnapshotForM2342(CatalogLifecycleState::Draft)))->nextState->toBe(CatalogLifecycleState::PendingReview);
});

it('rejects incomplete nonpositive or inapplicable portion evidence', function (array $overrides, CatalogLifecycleReason $reason) {
    $result = (new FoodPortionLifecyclePolicy)->evaluate(
        portionCommandForM2342(CatalogLifecycleOperation::SubmitForReview),
        portionSnapshotForM2342(CatalogLifecycleState::Draft, $overrides),
    );

    expect($result->outcome)->toBe(CatalogLifecycleOutcome::ValidationFailed)->and($result->reason)->toBe($reason);
})->with([
    'incomplete' => [['contentComplete' => false], CatalogLifecycleReason::IncompleteContent],
    'nonpositive unit quantity' => [['hasPositiveUnitQuantity' => false], CatalogLifecycleReason::PortionEvidenceInvalid],
    'nonpositive gram weight' => [['hasPositiveGramWeight' => false], CatalogLifecycleReason::PortionEvidenceInvalid],
    'invalid preparation applicability' => [['preparationApplicabilityValid' => false], CatalogLifecycleReason::PortionEvidenceInvalid],
]);

it('allows untrusted review evidence and blocks prohibited review evidence', function () {
    $policy = new FoodPortionLifecyclePolicy;

    expect($policy->evaluate(
        portionCommandForM2342(CatalogLifecycleOperation::SubmitForReview),
        portionSnapshotForM2342(CatalogLifecycleState::Draft, ['sourceEligible' => false]),
    ))->outcome->toBe(CatalogLifecycleOutcome::Succeeded)
        ->and($policy->evaluate(
            portionCommandForM2342(CatalogLifecycleOperation::SubmitForReview),
            portionSnapshotForM2342(CatalogLifecycleState::Draft, ['sourceEligible' => false, 'sourceProhibited' => true]),
        ))->reason->toBe(CatalogLifecycleReason::SourceProhibited);
});

it('requires eligible nonarchived source evidence for activation', function (array $overrides, CatalogLifecycleReason $reason) {
    $result = (new FoodPortionLifecyclePolicy)->evaluate(
        portionCommandForM2342(CatalogLifecycleOperation::Activate),
        portionSnapshotForM2342(CatalogLifecycleState::PublishedInactive, $overrides),
    );

    expect($result->reason)->toBe($reason);
})->with([
    'eligible' => [[], CatalogLifecycleReason::TransitionApplied],
    'untrusted' => [['sourceEligible' => false], CatalogLifecycleReason::SourceIneligible],
    'archived' => [['sourceArchived' => true], CatalogLifecycleReason::SourceArchived],
]);

it('reports same-reference active portion conflict', function () {
    $result = (new FoodPortionLifecyclePolicy)->evaluate(
        portionCommandForM2342(CatalogLifecycleOperation::Activate),
        portionSnapshotForM2342(CatalogLifecycleState::PublishedInactive, ['hasActivePortionConflict' => true]),
    );

    expect($result->outcome)->toBe(CatalogLifecycleOutcome::Conflict)
        ->and($result->reason)->toBe(CatalogLifecycleReason::ActivePortionConflict);
});

it('creates successors from rejected predecessors only with matching lineage and revision', function () {
    $policy = new FoodPortionLifecyclePolicy;

    expect($policy->evaluate(
        portionCommandForM2342(CatalogLifecycleOperation::CreateSuccessor),
        portionSnapshotForM2342(CatalogLifecycleState::Rejected),
    ))->reason->toBe(CatalogLifecycleReason::SuccessorCreated)
        ->and($policy->evaluate(
            portionCommandForM2342(CatalogLifecycleOperation::CreateSuccessor),
            portionSnapshotForM2342(CatalogLifecycleState::Rejected, ['successorLineageMatches' => false]),
        ))->reason->toBe(CatalogLifecycleReason::LineageMismatch)
        ->and($policy->evaluate(
            portionCommandForM2342(CatalogLifecycleOperation::CreateSuccessor),
            portionSnapshotForM2342(CatalogLifecycleState::Rejected, ['successorNumberIsContiguous' => false]),
        ))->reason->toBe(CatalogLifecycleReason::NumberConflict);
});

it('does not expose serving defaults or conversion outputs in the snapshot', function () {
    $properties = array_map(
        fn (ReflectionProperty $property): string => mb_strtolower($property->getName()),
        (new ReflectionClass(FoodPortionLifecycleSnapshot::class))->getProperties(),
    );

    expect(implode(' ', $properties))->not->toMatch('/default|serving|converted|calorie/');
});
