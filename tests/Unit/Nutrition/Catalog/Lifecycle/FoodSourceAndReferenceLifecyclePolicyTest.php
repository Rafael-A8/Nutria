<?php

use App\Nutrition\Domain\Catalog\Lifecycle\Enums\CatalogLifecycleOperation;
use App\Nutrition\Domain\Catalog\Lifecycle\Enums\CatalogLifecycleOutcome;
use App\Nutrition\Domain\Catalog\Lifecycle\Enums\CatalogLifecycleReason;
use App\Nutrition\Domain\Catalog\Lifecycle\Enums\CatalogLifecycleState;
use App\Nutrition\Domain\Catalog\Lifecycle\Enums\CatalogLifecycleSubjectType;
use App\Nutrition\Domain\Catalog\Lifecycle\Policies\FoodReferenceLifecyclePolicy;
use App\Nutrition\Domain\Catalog\Lifecycle\Policies\FoodSourceLifecyclePolicy;
use App\Nutrition\Domain\Catalog\Lifecycle\ValueObjects\CatalogLifecycleCommand;
use App\Nutrition\Domain\Catalog\Lifecycle\ValueObjects\FoodReferenceLifecycleSnapshot;
use App\Nutrition\Domain\Catalog\Lifecycle\ValueObjects\FoodSourceLifecycleSnapshot;

function simpleCatalogCommandForM2342(
    CatalogLifecycleSubjectType $subjectType,
    CatalogLifecycleOperation $operation,
    string $subjectId,
): CatalogLifecycleCommand {
    return new CatalogLifecycleCommand(
        $subjectType,
        $subjectId,
        $operation,
        'actor-1',
        'governed reason',
        '018f1f2e-7b2a-7c4d-8e9f-3123456789ab',
        new DateTimeImmutable('2026-07-12T12:00:00-03:00'),
    );
}

it('creates edits and governs source authority independently of review lifecycle', function () {
    $policy = new FoodSourceLifecyclePolicy;
    $create = $policy->evaluate(
        simpleCatalogCommandForM2342(CatalogLifecycleSubjectType::Source, CatalogLifecycleOperation::CreateSource, 'source-1'),
        new FoodSourceLifecycleSnapshot('source-1', false, null),
    );
    $edit = $policy->evaluate(
        simpleCatalogCommandForM2342(CatalogLifecycleSubjectType::Source, CatalogLifecycleOperation::EditSource, 'source-1'),
        new FoodSourceLifecycleSnapshot('source-1', true, CatalogLifecycleState::Available),
    );
    $authority = $policy->evaluate(
        simpleCatalogCommandForM2342(CatalogLifecycleSubjectType::Source, CatalogLifecycleOperation::ChangeAuthority, 'source-1'),
        new FoodSourceLifecycleSnapshot('source-1', true, CatalogLifecycleState::Available, authorityChangeValid: true),
    );

    expect($create->reason)->toBe(CatalogLifecycleReason::SourceCreated)
        ->and($edit->reason)->toBe(CatalogLifecycleReason::SourceUpdated)
        ->and($authority->outcome)->toBe(CatalogLifecycleOutcome::Succeeded);
});

it('rejects editing a referenced source and an invalid authority change', function () {
    $policy = new FoodSourceLifecyclePolicy;
    $used = $policy->evaluate(
        simpleCatalogCommandForM2342(CatalogLifecycleSubjectType::Source, CatalogLifecycleOperation::EditSource, 'source-1'),
        new FoodSourceLifecycleSnapshot('source-1', true, CatalogLifecycleState::Available, isAlreadyReferenced: true),
    );
    $authority = $policy->evaluate(
        simpleCatalogCommandForM2342(CatalogLifecycleSubjectType::Source, CatalogLifecycleOperation::ChangeAuthority, 'source-1'),
        new FoodSourceLifecycleSnapshot('source-1', true, CatalogLifecycleState::Available, authorityChangeValid: false),
    );

    expect($used->reason)->toBe(CatalogLifecycleReason::SourceAlreadyUsed)
        ->and($authority->reason)->toBe(CatalogLifecycleReason::CatalogIntegrityViolation);
});

it('archives sources terminally with a stable archive no op', function () {
    $policy = new FoodSourceLifecyclePolicy;
    $archive = $policy->evaluate(
        simpleCatalogCommandForM2342(CatalogLifecycleSubjectType::Source, CatalogLifecycleOperation::Archive, 'source-1'),
        new FoodSourceLifecycleSnapshot('source-1', true, CatalogLifecycleState::Available, archiveAllowed: true),
    );
    $noOp = $policy->evaluate(
        simpleCatalogCommandForM2342(CatalogLifecycleSubjectType::Source, CatalogLifecycleOperation::Archive, 'source-1'),
        new FoodSourceLifecycleSnapshot('source-1', true, CatalogLifecycleState::Archived),
    );
    $terminal = $policy->evaluate(
        simpleCatalogCommandForM2342(CatalogLifecycleSubjectType::Source, CatalogLifecycleOperation::EditSource, 'source-1'),
        new FoodSourceLifecycleSnapshot('source-1', true, CatalogLifecycleState::Archived),
    );

    expect($archive->nextState)->toBe(CatalogLifecycleState::Archived)
        ->and($noOp->reason)->toBe(CatalogLifecycleReason::AlreadyArchived)
        ->and($terminal->reason)->toBe(CatalogLifecycleReason::TerminalArchived);
});

it('rejects review operations for sources', function () {
    $result = (new FoodSourceLifecyclePolicy)->evaluate(
        simpleCatalogCommandForM2342(CatalogLifecycleSubjectType::Source, CatalogLifecycleOperation::SubmitForReview, 'source-1'),
        new FoodSourceLifecycleSnapshot('source-1', true, CatalogLifecycleState::Available),
    );

    expect($result->reason)->toBe(CatalogLifecycleReason::InvalidTransition);
});

it('creates and archives references without active children', function () {
    $policy = new FoodReferenceLifecyclePolicy;
    $create = $policy->evaluate(
        simpleCatalogCommandForM2342(CatalogLifecycleSubjectType::Reference, CatalogLifecycleOperation::CreateReference, 'reference-1'),
        new FoodReferenceLifecycleSnapshot('reference-1', false, null),
    );
    $archive = $policy->evaluate(
        simpleCatalogCommandForM2342(CatalogLifecycleSubjectType::Reference, CatalogLifecycleOperation::Archive, 'reference-1'),
        new FoodReferenceLifecycleSnapshot('reference-1', true, CatalogLifecycleState::Available),
    );

    expect($create->reason)->toBe(CatalogLifecycleReason::ReferenceCreated)
        ->and($archive->nextState)->toBe(CatalogLifecycleState::Archived);
});

it('blocks reference archive for every active child kind', function (array $activeChild) {
    $result = (new FoodReferenceLifecyclePolicy)->evaluate(
        simpleCatalogCommandForM2342(CatalogLifecycleSubjectType::Reference, CatalogLifecycleOperation::Archive, 'reference-1'),
        new FoodReferenceLifecycleSnapshot('reference-1', true, CatalogLifecycleState::Available, ...$activeChild),
    );

    expect($result->reason)->toBe(CatalogLifecycleReason::ReferenceHasActiveChildren);
})->with([
    'version' => [['hasActiveVersion' => true]],
    'alias' => [['hasActiveAlias' => true]],
    'portion' => [['hasActivePortion' => true]],
]);

it('keeps archived references terminal and rejects review operations', function () {
    $policy = new FoodReferenceLifecyclePolicy;
    $archived = new FoodReferenceLifecycleSnapshot('reference-1', true, CatalogLifecycleState::Archived);
    $noOp = $policy->evaluate(
        simpleCatalogCommandForM2342(CatalogLifecycleSubjectType::Reference, CatalogLifecycleOperation::Archive, 'reference-1'),
        $archived,
    );
    $terminal = $policy->evaluate(
        simpleCatalogCommandForM2342(CatalogLifecycleSubjectType::Reference, CatalogLifecycleOperation::CreateReference, 'reference-1'),
        $archived,
    );
    $review = $policy->evaluate(
        simpleCatalogCommandForM2342(CatalogLifecycleSubjectType::Reference, CatalogLifecycleOperation::SubmitForReview, 'reference-1'),
        new FoodReferenceLifecycleSnapshot('reference-1', true, CatalogLifecycleState::Available),
    );

    expect($noOp->reason)->toBe(CatalogLifecycleReason::AlreadyArchived)
        ->and($terminal->reason)->toBe(CatalogLifecycleReason::TerminalArchived)
        ->and($review->reason)->toBe(CatalogLifecycleReason::InvalidTransition);
});
