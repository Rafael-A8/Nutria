<?php

use App\Nutrition\Domain\Catalog\Lifecycle\Enums\CatalogLifecycleOperation;
use App\Nutrition\Domain\Catalog\Lifecycle\Enums\CatalogLifecycleOutcome;
use App\Nutrition\Domain\Catalog\Lifecycle\Enums\CatalogLifecycleReason;
use App\Nutrition\Domain\Catalog\Lifecycle\Enums\CatalogLifecycleState;
use App\Nutrition\Domain\Catalog\Lifecycle\Enums\CatalogLifecycleSubjectType;
use App\Nutrition\Domain\Catalog\Lifecycle\Policies\FoodReferenceVersionLifecyclePolicy;
use App\Nutrition\Domain\Catalog\Lifecycle\ValueObjects\CatalogLifecycleCommand;
use App\Nutrition\Domain\Catalog\Lifecycle\ValueObjects\FoodReferenceVersionLifecycleSnapshot;

function versionCommandForM2342(CatalogLifecycleOperation $operation): CatalogLifecycleCommand
{
    return new CatalogLifecycleCommand(
        CatalogLifecycleSubjectType::ReferenceVersion,
        'version-1',
        $operation,
        'actor-1',
        'governed reason',
        '018f1f2e-7b2a-7c4d-8e9f-0123456789ab',
        new DateTimeImmutable('2026-07-12T12:00:00-03:00'),
    );
}

function versionSnapshotForM2342(CatalogLifecycleState $state, array $overrides = []): FoodReferenceVersionLifecycleSnapshot
{
    return new FoodReferenceVersionLifecycleSnapshot(...array_replace([
        'subjectId' => 'version-1',
        'exists' => true,
        'state' => $state,
        'parentArchived' => false,
        'contentComplete' => true,
        'normalizedCanonicalNamePresent' => true,
        'provenanceComplete' => true,
        'conceptCompatible' => true,
        'hasPositiveEnergyBasis' => true,
        'hasPositiveEnergyKcal' => true,
        'primarySourceCount' => 1,
        'primarySourceEligible' => true,
        'primarySourceProhibited' => false,
        'primarySourceArchived' => false,
        'primarySourceRecordKeyPresent' => true,
        'sourceScopeCompatible' => true,
        'isReferenceHead' => true,
        'successorParentMatches' => true,
        'successorNumberIsContiguous' => true,
    ], $overrides));
}

it('creates and edits a version draft without requiring complete content', function () {
    $policy = new FoodReferenceVersionLifecyclePolicy;
    $new = new FoodReferenceVersionLifecycleSnapshot('version-1', false, null);

    expect($policy->evaluate(versionCommandForM2342(CatalogLifecycleOperation::CreateDraft), $new))
        ->reason->toBe(CatalogLifecycleReason::DraftCreated)
        ->nextState->toBe(CatalogLifecycleState::Draft)
        ->and($policy->evaluate(versionCommandForM2342(CatalogLifecycleOperation::EditDraft), versionSnapshotForM2342(CatalogLifecycleState::Draft)))
        ->reason->toBe(CatalogLifecycleReason::DraftUpdated);
});

it('submits complete versions while allowing untrusted primary evidence', function () {
    $snapshot = versionSnapshotForM2342(CatalogLifecycleState::Draft, ['primarySourceEligible' => false]);
    $result = (new FoodReferenceVersionLifecyclePolicy)->evaluate(versionCommandForM2342(CatalogLifecycleOperation::SubmitForReview), $snapshot);

    expect($result->outcome)->toBe(CatalogLifecycleOutcome::Succeeded)
        ->and($result->nextState)->toBe(CatalogLifecycleState::PendingReview);
});

it('returns deterministic ordered submission failures', function (array $overrides, CatalogLifecycleReason $reason) {
    $result = (new FoodReferenceVersionLifecyclePolicy)->evaluate(
        versionCommandForM2342(CatalogLifecycleOperation::SubmitForReview),
        versionSnapshotForM2342(CatalogLifecycleState::Draft, $overrides),
    );

    expect($result->outcome)->toBe(CatalogLifecycleOutcome::ValidationFailed)
        ->and($result->reason)->toBe($reason);
})->with([
    'incomplete content' => [['contentComplete' => false], CatalogLifecycleReason::IncompleteContent],
    'missing normalization' => [['normalizedCanonicalNamePresent' => false], CatalogLifecycleReason::IncompleteContent],
    'prohibited source' => [['primarySourceEligible' => false, 'primarySourceProhibited' => true], CatalogLifecycleReason::SourceProhibited],
    'missing primary' => [[
        'primarySourceCount' => 0,
        'primarySourceEligible' => false,
        'primarySourceRecordKeyPresent' => false,
        'sourceScopeCompatible' => false,
    ], CatalogLifecycleReason::PrimarySourceMissing],
]);

it('returns pending review to draft and approves or rejects it', function () {
    $policy = new FoodReferenceVersionLifecyclePolicy;
    $pending = versionSnapshotForM2342(CatalogLifecycleState::PendingReview);

    expect($policy->evaluate(versionCommandForM2342(CatalogLifecycleOperation::ReturnToDraft), $pending))->nextState->toBe(CatalogLifecycleState::Draft)
        ->and($policy->evaluate(versionCommandForM2342(CatalogLifecycleOperation::Approve), $pending))->nextState->toBe(CatalogLifecycleState::Approved)
        ->and($policy->evaluate(versionCommandForM2342(CatalogLifecycleOperation::Reject), $pending))->nextState->toBe(CatalogLifecycleState::Rejected);
});

it('prohibits self approval and keeps rejection terminal', function () {
    $policy = new FoodReferenceVersionLifecyclePolicy;
    $selfApproval = $policy->evaluate(
        versionCommandForM2342(CatalogLifecycleOperation::Approve),
        versionSnapshotForM2342(CatalogLifecycleState::PendingReview, ['actorIsOriginalAuthor' => true]),
    );
    $rejected = $policy->evaluate(
        versionCommandForM2342(CatalogLifecycleOperation::Approve),
        versionSnapshotForM2342(CatalogLifecycleState::Rejected),
    );

    expect($selfApproval->reason)->toBe(CatalogLifecycleReason::SelfApprovalProhibited)
        ->and($rejected->reason)->toBe(CatalogLifecycleReason::TerminalRejected);
});

it('publishes only approved versions and activates eligible published versions', function () {
    $policy = new FoodReferenceVersionLifecyclePolicy;

    expect($policy->evaluate(versionCommandForM2342(CatalogLifecycleOperation::Publish), versionSnapshotForM2342(CatalogLifecycleState::Approved)))
        ->nextState->toBe(CatalogLifecycleState::PublishedInactive)
        ->and($policy->evaluate(versionCommandForM2342(CatalogLifecycleOperation::Publish), versionSnapshotForM2342(CatalogLifecycleState::Draft)))
        ->reason->toBe(CatalogLifecycleReason::NotApproved)
        ->and($policy->evaluate(versionCommandForM2342(CatalogLifecycleOperation::Activate), versionSnapshotForM2342(CatalogLifecycleState::PublishedInactive)))
        ->nextState->toBe(CatalogLifecycleState::Active);
});

it('fully revalidates activation and reactivation eligibility', function (CatalogLifecycleOperation $operation, CatalogLifecycleState $state) {
    $policy = new FoodReferenceVersionLifecyclePolicy;
    $eligible = $policy->evaluate(versionCommandForM2342($operation), versionSnapshotForM2342($state));
    $ineligible = $policy->evaluate(versionCommandForM2342($operation), versionSnapshotForM2342($state, ['primarySourceEligible' => false]));

    expect($eligible->nextState)->toBe(CatalogLifecycleState::Active)
        ->and($ineligible->reason)->toBe(CatalogLifecycleReason::SourceIneligible);
})->with([
    'activation' => [CatalogLifecycleOperation::Activate, CatalogLifecycleState::PublishedInactive],
    'reactivation' => [CatalogLifecycleOperation::Reactivate, CatalogLifecycleState::Deactivated],
]);

it('blocks source archive conflicts active conflicts and superseded activation', function (array $overrides, CatalogLifecycleReason $reason, CatalogLifecycleOutcome $outcome) {
    $result = (new FoodReferenceVersionLifecyclePolicy)->evaluate(
        versionCommandForM2342(CatalogLifecycleOperation::Activate),
        versionSnapshotForM2342(CatalogLifecycleState::PublishedInactive, $overrides),
    );

    expect($result->reason)->toBe($reason)->and($result->outcome)->toBe($outcome);
})->with([
    'archived source' => [['primarySourceArchived' => true], CatalogLifecycleReason::SourceArchived, CatalogLifecycleOutcome::ValidationFailed],
    'active version conflict' => [['hasActiveVersionConflict' => true], CatalogLifecycleReason::ActiveVersionConflict, CatalogLifecycleOutcome::Conflict],
    'superseded predecessor' => [['isSupersededPredecessor' => true], CatalogLifecycleReason::SupersededPredecessor, CatalogLifecycleOutcome::ValidationFailed],
]);

it('deactivates withdraws and applies archive rules', function () {
    $policy = new FoodReferenceVersionLifecyclePolicy;

    expect($policy->evaluate(versionCommandForM2342(CatalogLifecycleOperation::Deactivate), versionSnapshotForM2342(CatalogLifecycleState::Active)))
        ->nextState->toBe(CatalogLifecycleState::Deactivated)
        ->and($policy->evaluate(versionCommandForM2342(CatalogLifecycleOperation::Withdraw), versionSnapshotForM2342(CatalogLifecycleState::Deactivated)))
        ->nextState->toBe(CatalogLifecycleState::Withdrawn)
        ->and($policy->evaluate(versionCommandForM2342(CatalogLifecycleOperation::Archive), versionSnapshotForM2342(CatalogLifecycleState::Rejected)))
        ->nextState->toBe(CatalogLifecycleState::Archived)
        ->and($policy->evaluate(versionCommandForM2342(CatalogLifecycleOperation::Archive), versionSnapshotForM2342(CatalogLifecycleState::Active)))
        ->outcome->toBe(CatalogLifecycleOutcome::InvalidTransition);
});

it('returns stable semantic no ops', function (CatalogLifecycleOperation $operation, CatalogLifecycleState $state, CatalogLifecycleReason $reason) {
    $result = (new FoodReferenceVersionLifecyclePolicy)->evaluate(versionCommandForM2342($operation), versionSnapshotForM2342($state));

    expect($result->outcome)->toBe(CatalogLifecycleOutcome::NoOp)->and($result->reason)->toBe($reason);
})->with([
    [CatalogLifecycleOperation::SubmitForReview, CatalogLifecycleState::PendingReview, CatalogLifecycleReason::AlreadyPendingReview],
    [CatalogLifecycleOperation::Approve, CatalogLifecycleState::Approved, CatalogLifecycleReason::AlreadyApproved],
    [CatalogLifecycleOperation::Publish, CatalogLifecycleState::Active, CatalogLifecycleReason::AlreadyPublished],
    [CatalogLifecycleOperation::Activate, CatalogLifecycleState::Active, CatalogLifecycleReason::AlreadyActive],
    [CatalogLifecycleOperation::Deactivate, CatalogLifecycleState::Deactivated, CatalogLifecycleReason::AlreadyDeactivated],
    [CatalogLifecycleOperation::Withdraw, CatalogLifecycleState::Withdrawn, CatalogLifecycleReason::AlreadyWithdrawn],
    [CatalogLifecycleOperation::Archive, CatalogLifecycleState::Archived, CatalogLifecycleReason::AlreadyArchived],
]);

it('permits successors from approved rejected and withdrawn predecessors', function (CatalogLifecycleState $state) {
    $result = (new FoodReferenceVersionLifecyclePolicy)->evaluate(
        versionCommandForM2342(CatalogLifecycleOperation::CreateSuccessor),
        versionSnapshotForM2342($state),
    );

    expect($result->reason)->toBe(CatalogLifecycleReason::SuccessorCreated)->and($result->nextState)->toBe(CatalogLifecycleState::Draft);
})->with([CatalogLifecycleState::Approved, CatalogLifecycleState::Rejected, CatalogLifecycleState::Withdrawn]);

it('validates successor parent numbering and uniqueness', function (array $overrides, CatalogLifecycleReason $reason) {
    $result = (new FoodReferenceVersionLifecyclePolicy)->evaluate(
        versionCommandForM2342(CatalogLifecycleOperation::CreateSuccessor),
        versionSnapshotForM2342(CatalogLifecycleState::Approved, $overrides),
    );

    expect($result->reason)->toBe($reason);
})->with([
    'wrong parent' => [['successorParentMatches' => false], CatalogLifecycleReason::ParentMismatch],
    'noncontiguous number' => [['successorNumberIsContiguous' => false], CatalogLifecycleReason::NumberConflict],
    'successor exists' => [['hasSuccessor' => true], CatalogLifecycleReason::SuccessorExists],
]);
