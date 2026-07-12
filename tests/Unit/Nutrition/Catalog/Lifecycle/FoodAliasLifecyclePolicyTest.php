<?php

use App\Nutrition\Domain\Catalog\Lifecycle\Enums\AliasKind;
use App\Nutrition\Domain\Catalog\Lifecycle\Enums\CatalogLifecycleOperation;
use App\Nutrition\Domain\Catalog\Lifecycle\Enums\CatalogLifecycleOutcome;
use App\Nutrition\Domain\Catalog\Lifecycle\Enums\CatalogLifecycleReason;
use App\Nutrition\Domain\Catalog\Lifecycle\Enums\CatalogLifecycleState;
use App\Nutrition\Domain\Catalog\Lifecycle\Enums\CatalogLifecycleSubjectType;
use App\Nutrition\Domain\Catalog\Lifecycle\Policies\FoodAliasLifecyclePolicy;
use App\Nutrition\Domain\Catalog\Lifecycle\ValueObjects\CatalogLifecycleCommand;
use App\Nutrition\Domain\Catalog\Lifecycle\ValueObjects\FoodAliasLifecycleSnapshot;

function aliasCommandForM2342(CatalogLifecycleOperation $operation): CatalogLifecycleCommand
{
    return new CatalogLifecycleCommand(
        CatalogLifecycleSubjectType::Alias,
        'alias-1',
        $operation,
        'actor-1',
        'governed reason',
        '018f1f2e-7b2a-7c4d-8e9f-1123456789ab',
        new DateTimeImmutable('2026-07-12T12:00:00-03:00'),
    );
}

function aliasSnapshotForM2342(CatalogLifecycleState $state, array $overrides = []): FoodAliasLifecycleSnapshot
{
    return new FoodAliasLifecycleSnapshot(...array_replace([
        'subjectId' => 'alias-1',
        'exists' => true,
        'state' => $state,
        'contentComplete' => true,
        'normalizedAliasPresent' => true,
        'localePresent' => true,
        'aliasKind' => AliasKind::Common,
        'provenanceComplete' => true,
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

it('supports the alias draft review publication lifecycle', function () {
    $policy = new FoodAliasLifecyclePolicy;
    $new = new FoodAliasLifecycleSnapshot('alias-1', false, null);

    expect($policy->evaluate(aliasCommandForM2342(CatalogLifecycleOperation::CreateDraft), $new))->reason->toBe(CatalogLifecycleReason::DraftCreated)
        ->and($policy->evaluate(aliasCommandForM2342(CatalogLifecycleOperation::EditDraft), aliasSnapshotForM2342(CatalogLifecycleState::Draft)))->reason->toBe(CatalogLifecycleReason::DraftUpdated)
        ->and($policy->evaluate(aliasCommandForM2342(CatalogLifecycleOperation::SubmitForReview), aliasSnapshotForM2342(CatalogLifecycleState::Draft)))->nextState->toBe(CatalogLifecycleState::PendingReview)
        ->and($policy->evaluate(aliasCommandForM2342(CatalogLifecycleOperation::Approve), aliasSnapshotForM2342(CatalogLifecycleState::PendingReview)))->nextState->toBe(CatalogLifecycleState::Approved)
        ->and($policy->evaluate(aliasCommandForM2342(CatalogLifecycleOperation::Publish), aliasSnapshotForM2342(CatalogLifecycleState::Approved)))->nextState->toBe(CatalogLifecycleState::PublishedInactive);
});

it('requires normalized alias locale and source evidence for review', function (array $overrides, CatalogLifecycleReason $reason) {
    $result = (new FoodAliasLifecyclePolicy)->evaluate(
        aliasCommandForM2342(CatalogLifecycleOperation::SubmitForReview),
        aliasSnapshotForM2342(CatalogLifecycleState::Draft, $overrides),
    );

    expect($result->reason)->toBe($reason)->and($result->outcome)->toBe(CatalogLifecycleOutcome::ValidationFailed);
})->with([
    'normalization' => [['normalizedAliasPresent' => false], CatalogLifecycleReason::AliasNormalizationMissing],
    'locale' => [['localePresent' => false], CatalogLifecycleReason::AliasLocaleMissing],
    'source' => [[
        'sourcePresent' => false,
        'sourceEligible' => false,
        'sourceRecordKeyPresent' => false,
        'sourceScopeCompatible' => false,
    ], CatalogLifecycleReason::SourceMissing],
]);

it('allows untrusted evidence into review but blocks prohibited evidence', function () {
    $policy = new FoodAliasLifecyclePolicy;
    $untrusted = aliasSnapshotForM2342(CatalogLifecycleState::Draft, ['sourceEligible' => false]);
    $prohibited = aliasSnapshotForM2342(CatalogLifecycleState::Draft, ['sourceEligible' => false, 'sourceProhibited' => true]);

    expect($policy->evaluate(aliasCommandForM2342(CatalogLifecycleOperation::SubmitForReview), $untrusted))->outcome->toBe(CatalogLifecycleOutcome::Succeeded)
        ->and($policy->evaluate(aliasCommandForM2342(CatalogLifecycleOperation::SubmitForReview), $prohibited))->reason->toBe(CatalogLifecycleReason::SourceProhibited);
});

it('activates only against eligible nonarchived source evidence', function (array $overrides, CatalogLifecycleOutcome $outcome, CatalogLifecycleReason $reason) {
    $result = (new FoodAliasLifecyclePolicy)->evaluate(
        aliasCommandForM2342(CatalogLifecycleOperation::Activate),
        aliasSnapshotForM2342(CatalogLifecycleState::PublishedInactive, $overrides),
    );

    expect($result->outcome)->toBe($outcome)->and($result->reason)->toBe($reason);
})->with([
    'eligible' => [[], CatalogLifecycleOutcome::Succeeded, CatalogLifecycleReason::TransitionApplied],
    'untrusted' => [['sourceEligible' => false], CatalogLifecycleOutcome::ValidationFailed, CatalogLifecycleReason::SourceIneligible],
    'archived' => [['sourceArchived' => true], CatalogLifecycleOutcome::ValidationFailed, CatalogLifecycleReason::SourceArchived],
]);

it('enforces generic and brand compatibility while allowing common and regional aliases', function (AliasKind $kind, bool $referenceIsGeneric, ?CatalogLifecycleReason $reason) {
    $result = (new FoodAliasLifecyclePolicy)->evaluate(
        aliasCommandForM2342(CatalogLifecycleOperation::SubmitForReview),
        aliasSnapshotForM2342(CatalogLifecycleState::Draft, [
            'aliasKind' => $kind,
            'referenceIsGeneric' => $referenceIsGeneric,
        ]),
    );

    $reason === null
        ? expect($result->outcome)->toBe(CatalogLifecycleOutcome::Succeeded)
        : expect($result->reason)->toBe($reason);
})->with([
    'generic on specific' => [AliasKind::Generic, false, CatalogLifecycleReason::GenericAliasReferenceMismatch],
    'brand on generic' => [AliasKind::Brand, true, CatalogLifecycleReason::BrandAliasGenericReferenceMismatch],
    'common on generic' => [AliasKind::Common, true, null],
    'regional on specific' => [AliasKind::Regional, false, null],
]);

it('treats only same-reference active key collision as a conflict', function () {
    $policy = new FoodAliasLifecyclePolicy;
    $localConflict = $policy->evaluate(
        aliasCommandForM2342(CatalogLifecycleOperation::Activate),
        aliasSnapshotForM2342(CatalogLifecycleState::PublishedInactive, ['hasActiveAliasConflict' => true]),
    );
    $crossReferenceAmbiguity = $policy->evaluate(
        aliasCommandForM2342(CatalogLifecycleOperation::Activate),
        aliasSnapshotForM2342(CatalogLifecycleState::PublishedInactive, ['hasActiveAliasConflict' => false]),
    );

    expect($localConflict->reason)->toBe(CatalogLifecycleReason::ActiveAliasConflict)
        ->and($crossReferenceAmbiguity->outcome)->toBe(CatalogLifecycleOutcome::Succeeded);
});

it('creates a successor from a rejected predecessor with matching lineage and revision', function () {
    $policy = new FoodAliasLifecyclePolicy;
    $success = $policy->evaluate(aliasCommandForM2342(CatalogLifecycleOperation::CreateSuccessor), aliasSnapshotForM2342(CatalogLifecycleState::Rejected));
    $lineageFailure = $policy->evaluate(aliasCommandForM2342(CatalogLifecycleOperation::CreateSuccessor), aliasSnapshotForM2342(CatalogLifecycleState::Rejected, ['successorLineageMatches' => false]));
    $revisionFailure = $policy->evaluate(aliasCommandForM2342(CatalogLifecycleOperation::CreateSuccessor), aliasSnapshotForM2342(CatalogLifecycleState::Rejected, ['successorNumberIsContiguous' => false]));

    expect($success->reason)->toBe(CatalogLifecycleReason::SuccessorCreated)
        ->and($lineageFailure->reason)->toBe(CatalogLifecycleReason::LineageMismatch)
        ->and($revisionFailure->reason)->toBe(CatalogLifecycleReason::NumberConflict);
});

it('reactivates only deactivated aliases after complete revalidation', function () {
    $result = (new FoodAliasLifecyclePolicy)->evaluate(
        aliasCommandForM2342(CatalogLifecycleOperation::Reactivate),
        aliasSnapshotForM2342(CatalogLifecycleState::Deactivated),
    );

    expect($result->nextState)->toBe(CatalogLifecycleState::Active);
});
